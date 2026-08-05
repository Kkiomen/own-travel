<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Deal\BoardType;
use App\Domain\Deal\Deal;
use App\Domain\Deal\DealSort;
use App\Domain\Deal\DealType;
use App\Domain\Deal\Port\DealRepository;
use App\Domain\Deal\ScoreBasis;
use App\Domain\Deal\Service\Steal;
use App\Domain\Deal\ValueObject\Airport;
use App\Domain\Deal\ValueObject\DealListing;
use App\Domain\Deal\ValueObject\DealScore;
use App\Domain\Deal\ValueObject\DealSummary;
use App\Domain\Deal\ValueObject\HolidayWindow;
use App\Domain\Deal\ValueObject\Money;
use App\Domain\Deal\ValueObject\TravelWindow;
use App\Domain\Deal\ValueObject\TripDetails;
use App\Infrastructure\Persistence\Eloquent\DealRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

final readonly class EloquentDealRepository implements DealRepository
{
    public function __construct(private Steal $steal) {}

    public function store(Deal $deal): bool
    {
        $fingerprint = $deal->fingerprint();
        $known = DealRecord::query()->where('fingerprint', $fingerprint)->first();

        if ($known instanceof DealRecord) {
            $this->fillInMissingAirportNames($known, $deal);

            return false;
        }

        $record = DealRecord::query()->create([
            'fingerprint' => $fingerprint,
            'source' => $deal->source,
            'type' => $deal->type->value,
            'title' => $deal->title,
            'url' => $deal->url,
            'price_minor_units' => $deal->price->minorUnits,
            'price_currency' => $deal->price->currency,
            'origin_iata' => $deal->origin?->iataCode,
            'origin_name' => $deal->origin?->name,
            'destination_iata' => $deal->destination?->iataCode,
            'destination_name' => $deal->destination?->name,
            'destination_country' => $deal->destination?->countryName,
            'departs_at' => $deal->departsAt,
            'returns_at' => $deal->returnsAt,
            'published_at' => $deal->publishedAt,
            'trip_days' => $deal->trip?->days,
            'board' => $deal->trip?->board->value,
            'hotel_stars' => $deal->trip?->hotelStars,
            'trip_details' => $deal->trip === null ? null : [
                'destination' => $deal->trip->destination,
                'hotel' => $deal->trip->hotel,
                'departure_cities' => $deal->trip->departureCities,
                'dates' => array_map(
                    static fn (TravelWindow $window): array => [
                        'from' => $window->from->toDateString(),
                        'to' => $window->to?->toDateString(),
                        'label' => $window->label,
                    ],
                    $deal->trip->dates,
                ),
                'highlights' => $deal->trip->highlights,
            ],
            'score' => $deal->score?->value,
            'rated_price_minor_units' => $deal->score?->ratedPrice->minorUnits,
            'score_basis' => $deal->score?->basis->value,
            'weekend_getaway' => $deal->weekendGetaway,
            'typical_price_minor_units' => $deal->typicalPrice?->minorUnits,
            'steal' => $this->steal->matches($deal),
            'found_at' => Date::now(),
        ]);

        $this->storeTravelWindows($record, $deal);

        return true;
    }

    /**
     * The dates are written twice on purpose: the JSON copy is what the offer
     * shows the reader, these rows are what a holiday search can filter on.
     * One column cannot do both - a query cannot look inside the JSON without
     * picking a dialect, and the tests run on SQLite while the app runs on
     * Postgres.
     */
    private function storeTravelWindows(DealRecord $record, Deal $deal): void
    {
        $windows = $deal->trip->dates ?? [];

        if ($windows === []) {
            return;
        }

        DB::table('deal_travel_windows')->insert(array_map(
            static fn (TravelWindow $window): array => [
                'deal_id' => $record->id,
                'starts_on' => $window->from->toDateString(),
                'ends_on' => $window->to?->toDateString(),
                'label' => $window->label,
            ],
            $windows,
        ));
    }

    /**
     * The fingerprint deliberately leaves airport names out, so the same offer
     * found again with better names is still the same offer - and there is no
     * reason to keep showing the poorer version. Wizz Air's timetable answers
     * with codes alone, and until its station directory was wired up every one
     * of its offers was stored nameless; this is what lets those rows recover
     * on the next scan instead of waiting to expire.
     */
    private function fillInMissingAirportNames(DealRecord $known, Deal $deal): void
    {
        $better = array_filter([
            'origin_name' => $known->origin_name === null ? $deal->origin?->name : null,
            'destination_name' => $known->destination_name === null ? $deal->destination?->name : null,
            'destination_country' => $known->destination_country === null ? $deal->destination?->countryName : null,
        ], static fn (?string $value): bool => $value !== null);

        if ($better !== []) {
            $known->forceFill($better)->save();
        }
    }

    public function list(DealListing $listing): array
    {
        $query = DealRecord::query();

        $this->onlyWhatIsAskedFor($query, $listing);

        $query->limit($listing->limit);

        // The home airport comes first whatever the ordering - it is the one
        // that can be reached without a trip to the airport first.
        if ($listing->preferredOrigin instanceof Airport) {
            $query->orderByRaw('(origin_iata = ?) desc', [$listing->preferredOrigin->iataCode]);
        }

        match ($listing->sort) {
            DealSort::Score => $query->orderByDesc('score')->orderBy('price_minor_units'),
            DealSort::Price => $query->orderBy('price_minor_units'),
            DealSort::Newest => $query->orderByDesc('found_at'),
        };

        return array_values(
            $query->orderByDesc('id')
                ->get()
                ->map(fn (DealRecord $record): Deal => $this->toDeal($record))
                ->all(),
        );
    }

    public function find(string $fingerprint, CarbonImmutable $now): ?Deal
    {
        $record = DealRecord::query()
            ->where('fingerprint', $fingerprint)
            ->where(function (Builder $departures) use ($now): void {
                $departures
                    ->whereNull('departs_at')
                    ->orWhere('departs_at', '>=', $now);
            })
            ->first();

        return $record === null ? null : $this->toDeal($record);
    }

    public function summarise(CarbonImmutable $now): DealSummary
    {
        // Plain query builder: these are aggregates, not deals, and asking the
        // model for them only invites confusion about what a DealRecord holds.
        $rows = DB::table('deals')
            ->selectRaw('type, count(*) as total, min(price_minor_units) as cheapest, min(price_currency) as currency')
            ->where(function (QueryBuilder $departures) use ($now): void {
                $departures
                    ->whereNull('departs_at')
                    ->orWhere('departs_at', '>=', $now);
            })
            ->groupBy('type')
            ->get();

        $counts = [];
        $cheapest = [];

        foreach ($rows as $row) {
            /** @var object{type: string, total: int, cheapest: int, currency: string} $row */
            $counts[$row->type] = (int) $row->total;
            $cheapest[$row->type] = Money::fromMinorUnits((int) $row->cheapest, $row->currency);
        }

        return new DealSummary($counts, $cheapest);
    }

    public function availableAirports(DealListing $listing): array
    {
        return [
            'origins' => $this->airportsIn('origin_iata', $listing),
            'destinations' => $this->airportsIn('destination_iata', $listing),
        ];
    }

    /**
     * The filters the dashboard is looking through. The listing and the lists
     * of airports it offers run through the same ones, so the two can never
     * disagree about what is on offer.
     *
     * @param  Builder<DealRecord>|QueryBuilder  $query
     * @param  'origin_iata'|'destination_iata'|null  $ignoring  the filter a facet must not narrow itself by
     */
    private function onlyWhatIsAskedFor(
        Builder|QueryBuilder $query,
        DealListing $listing,
        ?string $ignoring = null,
    ): void {
        // A flight that has already left is not an offer any more. Written flat
        // rather than as a nested group so the one method serves both builders.
        $query->whereRaw('(departs_at is null or departs_at >= ?)', [$listing->now]);

        if ($listing->type instanceof DealType) {
            $query->where('type', $listing->type->value);
        }

        if ($listing->weekendsOnly) {
            $query->where('weekend_getaway', true);
        }

        if ($listing->stealsOnly) {
            $query->where('steal', true);
        }

        if ($listing->origin instanceof Airport && $ignoring !== 'origin_iata') {
            $query->where('origin_iata', $listing->origin->iataCode);
        }

        if ($listing->destination instanceof Airport && $ignoring !== 'destination_iata') {
            $query->where('destination_iata', $listing->destination->iataCode);
        }

        if ($listing->undatedTripsOnly) {
            $this->onlyTripsNobodyDated($query);

            return;
        }

        if ($listing->holiday instanceof HolidayWindow) {
            $this->onlyWhatFitsTheHoliday($query, $listing->holiday);
        }
    }

    /**
     * Whatever can be shown to fall inside the leave, whichever way it carries
     * its dates: a flight has a departure and possibly a return, a blog offer
     * has the terms the article named. An offer with neither cannot qualify.
     *
     * The whole journey has to fit. Leaving on the last day off is no holiday,
     * and coming back after work has started again is not bookable.
     *
     * @param  Builder<DealRecord>|QueryBuilder  $query
     */
    private function onlyWhatFitsTheHoliday(Builder|QueryBuilder $query, HolidayWindow $holiday): void
    {
        $windows = $this->travelWindowsOfTheDeal()->whereRaw(
            'starts_on >= ? and coalesce(ends_on, starts_on) <= ?',
            [$holiday->from->toDateString(), $holiday->to->toDateString()],
        );

        $query->where(function (Builder|QueryBuilder $fits) use ($holiday, $windows): void {
            $fits
                ->whereRaw(
                    'departs_at is not null and departs_at >= ? and coalesce(returns_at, departs_at) <= ?',
                    [$holiday->from, $holiday->to],
                )
                ->orWhereExists($windows);
        });
    }

    /**
     * Trips the article never dated. They are not matches - nothing about them
     * can be matched - but hiding them would bury most of the blog offers,
     * because only a minority of articles name their terms in the summary the
     * parser is allowed to read.
     *
     * @param  Builder<DealRecord>|QueryBuilder  $query
     */
    private function onlyTripsNobodyDated(Builder|QueryBuilder $query): void
    {
        $query
            ->where('type', DealType::Trip->value)
            ->whereNotExists($this->travelWindowsOfTheDeal());
    }

    /**
     * The dates belonging to the deal the outer query is looking at.
     */
    private function travelWindowsOfTheDeal(): QueryBuilder
    {
        return DB::table('deal_travel_windows')
            ->selectRaw('1')
            ->whereColumn('deal_travel_windows.deal_id', 'deals.id');
    }

    /**
     * @param  'origin_iata'|'destination_iata'  $codeColumn
     * @return list<Airport>
     */
    private function airportsIn(string $codeColumn, DealListing $listing): array
    {
        $selection = match ($codeColumn) {
            'origin_iata' => 'origin_iata as code, min(origin_name) as name',
            'destination_iata' => 'destination_iata as code, min(destination_name) as name',
        };

        $query = DB::table('deals')
            ->selectRaw($selection)
            ->whereNotNull($codeColumn);

        $this->onlyWhatIsAskedFor($query, $listing, $codeColumn);

        $rows = $query
            ->groupBy($codeColumn)
            ->orderBy('name')
            ->get();

        $airports = [];

        foreach ($rows as $row) {
            /** @var object{code: string, name: string|null} $row */
            $airports[] = Airport::fromIataCode($row->code, $row->name);
        }

        return $airports;
    }

    public function purgeExpired(CarbonImmutable $departedBefore, CarbonImmutable $foundBefore): int
    {
        return DealRecord::query()
            ->where(function (Builder $expired) use ($departedBefore, $foundBefore): void {
                $expired
                    ->where('departs_at', '<', $departedBefore)
                    ->orWhere('found_at', '<', $foundBefore);
            })
            ->delete();
    }

    private function toDeal(DealRecord $record): Deal
    {
        $price = Money::fromMinorUnits($record->price_minor_units, $record->price_currency);
        $type = DealType::from($record->type);
        $departsAt = $record->departs_at instanceof CarbonImmutable ? $record->departs_at : null;
        $score = $this->toScore($record, $price->currency);

        if ($record->origin_iata === null || $record->destination_iata === null) {
            return Deal::fromFeed(
                source: $record->source,
                type: $type,
                title: $record->title,
                price: $price,
                url: $record->url,
                publishedAt: $record->published_at instanceof CarbonImmutable ? $record->published_at : null,
                trip: $this->toTripDetails($record),
                score: $score,
            );
        }

        $origin = Airport::fromIataCode($record->origin_iata, $record->origin_name);
        $destination = Airport::fromIataCode(
            $record->destination_iata,
            $record->destination_name,
            $record->destination_country,
        );
        $returnsAt = $record->returns_at instanceof CarbonImmutable ? $record->returns_at : null;

        if ($type === DealType::RoundTrip && $departsAt !== null && $returnsAt !== null) {
            $roundTrip = Deal::roundTrip(
                source: $record->source,
                title: $record->title,
                totalPrice: $price,
                url: $record->url,
                origin: $origin,
                destination: $destination,
                departsAt: $departsAt,
                returnsAt: $returnsAt,
            )
                ->scoredWith($score)
                ->markedAsWeekendGetaway((bool) $record->weekend_getaway);

            return $record->typical_price_minor_units === null
                ? $roundTrip
                : $roundTrip->comparedAgainst(
                    Money::fromMinorUnits($record->typical_price_minor_units, $price->currency),
                );
        }

        return Deal::flight(
            source: $record->source,
            title: $record->title,
            price: $price,
            url: $record->url,
            origin: $origin,
            destination: $destination,
            departsAt: $departsAt,
        )->scoredWith($score);
    }

    private function toTripDetails(DealRecord $record): ?TripDetails
    {
        if ($record->board === null && $record->trip_days === null
            && $record->hotel_stars === null && $record->trip_details === null) {
            return null;
        }

        /** @var array{destination?: string|null, hotel?: string|null, departure_cities?: list<string>, dates?: list<array{from?: string, to?: string|null, label?: string}>, highlights?: list<string>} $details */
        $details = is_array($record->trip_details) ? $record->trip_details : [];

        return new TripDetails(
            days: $record->trip_days,
            board: BoardType::tryFrom((string) $record->board) ?? BoardType::Unknown,
            hotelStars: $record->hotel_stars,
            destination: $details['destination'] ?? null,
            hotel: $details['hotel'] ?? null,
            departureCities: $details['departure_cities'] ?? [],
            dates: $this->toTravelWindows($details['dates'] ?? []),
            highlights: $details['highlights'] ?? [],
        );
    }

    /**
     * @param  array<int, array{from?: string, to?: string|null, label?: string}>  $dates
     * @return list<TravelWindow>
     */
    private function toTravelWindows(array $dates): array
    {
        $windows = [];

        foreach ($dates as $date) {
            if (! is_string($date['from'] ?? null)) {
                continue;
            }

            $windows[] = new TravelWindow(
                CarbonImmutable::parse($date['from']),
                is_string($date['to'] ?? null) ? CarbonImmutable::parse($date['to']) : null,
                is_string($date['label'] ?? null) ? $date['label'] : $date['from'],
            );
        }

        return $windows;
    }

    private function toScore(DealRecord $record, string $currency): ?DealScore
    {
        if ($record->score === null || $record->rated_price_minor_units === null) {
            return null;
        }

        return new DealScore(
            $record->score,
            Money::fromMinorUnits($record->rated_price_minor_units, $currency),
            ScoreBasis::tryFrom((string) $record->score_basis) ?? ScoreBasis::TotalPrice,
        );
    }
}
