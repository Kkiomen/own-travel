<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Deal\BoardType;
use App\Domain\Deal\Deal;
use App\Domain\Deal\DealSort;
use App\Domain\Deal\DealType;
use App\Domain\Deal\Port\DealRepository;
use App\Domain\Deal\Service\DealEvaluator;
use App\Domain\Deal\Service\Steal;
use App\Domain\Deal\ValueObject\Airport;
use App\Domain\Deal\ValueObject\DealListing;
use App\Domain\Deal\ValueObject\DealSummary;
use App\Domain\Deal\ValueObject\TravelWindow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class DealController extends Controller
{
    public function __construct(private readonly Steal $steal) {}

    public function index(Request $request, DealRepository $deals, DealEvaluator $evaluator): Response
    {
        $now = Date::now()->toImmutable();

        $sort = DealSort::tryFrom((string) $request->query('sort')) ?? DealSort::Score;
        $type = DealType::tryFrom((string) $request->query('type'));
        $origin = $this->airport($request->query('origin'));
        $destination = $this->airport($request->query('destination'));
        $weekendsOnly = $request->boolean('weekends');
        $stealsOnly = $request->boolean('steals');

        $listing = new DealListing(
            limit: (int) config('deals.dashboard_limit', 60),
            sort: $sort,
            now: $now,
            type: $type,
            weekendsOnly: $weekendsOnly,
            stealsOnly: $stealsOnly,
            origin: $origin,
            destination: $destination,
            preferredOrigin: $this->airport(config('deals.preferred_origin')),
        );

        $summary = $deals->summarise($now);
        $airports = $deals->availableAirports($now);

        return Inertia::render('Dashboard', [
            'deals' => array_map($this->present(...), $deals->list($listing)),
            'sort' => $sort->value,
            'type' => $type?->value,
            'weekends' => $weekendsOnly,
            'steals' => $stealsOnly,
            'origin' => $origin?->iataCode,
            'destination' => $destination?->iataCode,
            'airports' => [
                'origins' => array_map($this->presentAirport(...), $airports['origins']),
                'destinations' => array_map($this->presentAirport(...), $airports['destinations']),
            ],
            'totals' => [
                'flight' => $this->summarise($summary, DealType::Flight),
                'round_trip' => $this->summarise($summary, DealType::RoundTrip),
                'trip' => $this->summarise($summary, DealType::Trip),
            ],
            'thresholds' => [
                'flight' => $evaluator->thresholdFor(DealType::Flight)->toDecimal(),
                'round_trip' => $evaluator->thresholdFor(DealType::RoundTrip)->toDecimal(),
                'trip' => $evaluator->thresholdFor(DealType::Trip)->toDecimal(),
                'score' => $evaluator->minimumTripScore(),
            ],
            'currency' => (string) config('deals.currency', 'PLN'),
        ]);
    }

    /**
     * Anything that is not a usable IATA code is treated as no filter at all.
     */
    private function airport(mixed $iataCode): ?Airport
    {
        if (! is_string($iataCode) || trim($iataCode) === '') {
            return null;
        }

        try {
            return Airport::fromIataCode($iataCode);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{code: string, label: string}
     */
    private function presentAirport(Airport $airport): array
    {
        return [
            'code' => $airport->iataCode,
            'label' => $airport->label(),
        ];
    }

    /**
     * The page draws both ends of a route in full, so it gets the parts rather
     * than a formatted label it would have to take apart again.
     *
     * @return array{code: string, city: string, country: string|null}|null
     */
    private function presentEndpoint(?Airport $airport): ?array
    {
        if ($airport === null) {
            return null;
        }

        return [
            'code' => $airport->iataCode,
            'city' => $airport->label(),
            'country' => $airport->countryName,
        ];
    }

    /**
     * @return array{count: int, cheapest: float|null}
     */
    private function summarise(DealSummary $summary, DealType $type): array
    {
        return [
            'count' => $summary->countOf($type),
            'cheapest' => $summary->cheapestOf($type)?->toDecimal(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Deal $deal): array
    {
        return [
            'id' => $deal->fingerprint(),
            'source' => $deal->source,
            'type' => $deal->type->value,
            'title' => $deal->title,
            'price' => $deal->price->toDecimal(),
            'currency' => $deal->price->currency,
            'url' => $deal->url,
            'origin' => $this->presentEndpoint($deal->origin),
            'destination' => $this->presentEndpoint($deal->destination),
            'departs_at' => $deal->departsAt?->toIso8601String(),
            'returns_at' => $deal->returnsAt?->toIso8601String(),
            'published_at' => $deal->publishedAt?->toIso8601String(),
            'weekend' => $deal->weekendGetaway,
            'steal' => $this->steal->matches($deal),
            'typical_price' => $deal->typicalPrice?->toDecimal(),
            'discount' => $deal->discount() === null ? null : (int) round($deal->discount() * 100),
            'days' => $deal->trip?->days,
            'board' => $deal->trip === null || $deal->trip->board === BoardType::Unknown
                ? null
                : $deal->trip->board->value,
            'hotel_stars' => $deal->trip?->hotelStars,
            'trip_destination' => $deal->trip?->destination,
            'hotel' => $deal->trip?->hotel,
            'departure_cities' => $deal->trip->departureCities ?? [],
            'dates' => array_map(
                static fn (TravelWindow $window): array => [
                    'from' => $window->from->toDateString(),
                    'to' => $window->to?->toDateString(),
                    'label' => $window->label,
                ],
                $deal->trip->dates ?? [],
            ),
            'highlights' => $deal->trip->highlights ?? [],
            'has_details' => $deal->trip?->areWorthShowing() ?? false,
            'score' => $deal->score?->value,
            'price_per_day' => $deal->score?->pricePerDay()?->toDecimal(),
        ];
    }
}
