<?php

declare(strict_types=1);

namespace App\Infrastructure\DealSource;

use App\Domain\Deal\Deal;
use App\Domain\Deal\Exception\DealSourceUnavailable;
use App\Domain\Deal\Port\DealSource;
use App\Domain\Deal\Service\RoundTripPairing;
use App\Domain\Deal\ValueObject\Airport;
use App\Domain\Deal\ValueObject\FlightLeg;
use App\Domain\Deal\ValueObject\Money;
use App\Domain\Deal\ValueObject\SearchCriteria;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpClient;
use Throwable;

/**
 * Wizz Air's booking backend. Unlike Ryanair it cannot answer "cheapest from
 * anywhere", so the routes we care about are configured explicitly and asked
 * about one at a time.
 *
 * One request returns both directions, so the legs are paired here: for every
 * outbound day the cheapest return inside the stay window is found, and the
 * pair is offered at what the two together cost. Wizz Air will not do that for
 * us, and an unpaired cheap seat is how you end up with a return a month late.
 */
final readonly class WizzAirTimetableSource implements DealSource
{
    public const NAME = 'wizzair';

    /**
     * @param  array<string, list<string>>  $routes  departure IATA => destination IATA codes
     */
    public function __construct(
        private HttpClient $http,
        private WizzAirApiVersionResolver $versionResolver,
        private WizzAirStationDirectory $stations,
        private RoundTripPairing $pairing,
        private string $apiUrl,
        private string $bookingUrl,
        private array $routes,
        private int $timeoutSeconds,
        private int $maxWindowDays,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function findDeals(SearchCriteria $criteria): array
    {
        $routes = $this->routesFor($criteria);

        if ($routes === []) {
            return [];
        }

        $version = $this->versionResolver->resolve();
        $deals = [];
        $failures = 0;

        foreach ($routes as [$origin, $destination]) {
            try {
                $deals = [...$deals, ...$this->findDealsOnRoute($version, $origin, $destination, $criteria)];
            } catch (Throwable) {
                $failures++;
            }
        }

        if ($failures === count($routes)) {
            throw DealSourceUnavailable::forSource(
                self::NAME,
                sprintf('no usable response for any of the %d configured routes', $failures),
            );
        }

        return $deals;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function routesFor(SearchCriteria $criteria): array
    {
        $watched = $criteria->departureIataCodes();
        $routes = [];

        foreach ($this->routes as $origin => $destinations) {
            if (! in_array($origin, $watched, true)) {
                continue;
            }

            foreach ($destinations as $destination) {
                $routes[] = [$origin, $destination];
            }
        }

        return $routes;
    }

    /**
     * @return list<Deal>
     */
    private function findDealsOnRoute(
        string $version,
        string $origin,
        string $destination,
        SearchCriteria $criteria,
    ): array {
        $outbound = [];
        $inbound = [];

        // The endpoint refuses a window wider than about a month, so the
        // search is walked month by month and the legs pooled - pairing (and
        // the price a route usually goes for) then sees the whole window.
        foreach ($this->monthsIn($criteria) as [$from, $to]) {
            $payload = $this->timetable($version, $origin, $destination, $from, $to, $criteria);

            $outbound = [...$outbound, ...$this->bookableLegs($payload['outboundFlights'])];
            $inbound = [...$inbound, ...$this->bookableLegs($payload['returnFlights'])];
        }

        return $this->pair($outbound, $inbound, $criteria);
    }

    /**
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function monthsIn(SearchCriteria $criteria): array
    {
        $months = [];
        $cursor = $criteria->departureFrom;

        while (! $cursor->greaterThan($criteria->departureTo)) {
            $endOfMonth = $cursor->endOfMonth()->startOfDay();
            $to = $endOfMonth->greaterThan($criteria->departureTo) ? $criteria->departureTo : $endOfMonth;

            // Never ask for more days than the endpoint will answer for.
            $cap = $cursor->addDays($this->maxWindowDays);
            $months[] = [$cursor, $to->greaterThan($cap) ? $cap : $to];

            $cursor = $to->addDay()->startOfDay();
        }

        return $months;
    }

    /**
     * @return array{outboundFlights: array<mixed>, returnFlights: array<mixed>}
     */
    private function timetable(
        string $version,
        string $origin,
        string $destination,
        CarbonImmutable $from,
        CarbonImmutable $to,
        SearchCriteria $criteria,
    ): array {
        $response = $this->http
            ->timeout($this->timeoutSeconds)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Origin' => $this->bookingUrl,
                'Referer' => $this->bookingUrl.'/',
            ])
            ->post(sprintf('%s/%s/Api/search/timetable', $this->apiUrl, $version), [
                'flightList' => [
                    [
                        'departureStation' => $origin,
                        'arrivalStation' => $destination,
                        'from' => $from->toDateString(),
                        'to' => $to->toDateString(),
                    ],
                    [
                        'departureStation' => $destination,
                        'arrivalStation' => $origin,
                        'from' => $criteria->stay->earliestReturn($from)->toDateString(),
                        'to' => $criteria->stay->latestReturn($to)->toDateString(),
                    ],
                ],
                'priceType' => 'regular',
                'adultCount' => 1,
                'childCount' => 0,
                'infantCount' => 0,
            ]);

        if ($response->failed()) {
            throw DealSourceUnavailable::forSource(
                self::NAME,
                sprintf('HTTP %d for route %s-%s', $response->status(), $origin, $destination),
            );
        }

        $outbound = $response->json('outboundFlights');

        if (! is_array($outbound)) {
            throw DealSourceUnavailable::forSource(self::NAME, 'unexpected payload without outbound flights');
        }

        $inbound = $response->json('returnFlights');

        return [
            'outboundFlights' => $outbound,
            'returnFlights' => is_array($inbound) ? $inbound : [],
        ];
    }

    /**
     * Turns the timetable into the flights that can actually be taken.
     *
     * The daily entry carries only a date; the real departure times sit in
     * departureDates, and they matter - "Friday" is not a weekend offer if the
     * only flight leaves at six in the morning. Days without a flight come
     * back priced at zero with no times at all.
     *
     * @param  array<mixed>  $flights
     * @return list<FlightLeg>
     */
    private function bookableLegs(array $flights): array
    {
        $legs = [];

        foreach ($flights as $flight) {
            if (! is_array($flight)) {
                continue;
            }

            $price = $flight['price'] ?? null;
            $departureStation = $flight['departureStation'] ?? null;
            $arrivalStation = $flight['arrivalStation'] ?? null;

            if (! is_array($price) || ! is_numeric($price['amount'] ?? null) || ! is_string($price['currencyCode'] ?? null)) {
                continue;
            }

            if (! is_string($departureStation) || ! is_string($arrivalStation) || (float) $price['amount'] <= 0.0) {
                continue;
            }

            // The timetable answers with codes alone; the directory is what
            // turns them into somewhere a person recognises.
            $origin = $this->stations->lookUp($departureStation);
            $destination = $this->stations->lookUp($arrivalStation);
            $money = Money::fromDecimal($price['amount'], $price['currencyCode']);

            foreach ($this->departureTimes($flight) as $departsAt) {
                $legs[] = new FlightLeg($origin, $destination, $departsAt, $money);
            }
        }

        return $legs;
    }

    /**
     * @param  array<string, mixed>  $flight
     * @return list<CarbonImmutable>
     */
    private function departureTimes(array $flight): array
    {
        $times = $flight['departureDates'] ?? null;
        $moments = [];

        foreach (is_array($times) ? $times : [] as $time) {
            if (is_string($time)) {
                $moments[] = CarbonImmutable::parse($time);
            }
        }

        return $moments;
    }

    /**
     * @param  list<FlightLeg>  $outbound
     * @param  list<FlightLeg>  $inbound
     * @return list<Deal>
     */
    private function pair(array $outbound, array $inbound, SearchCriteria $criteria): array
    {
        $pairs = $this->pairing->pair(
            $outbound,
            $inbound,
            $criteria->stay,
            fn (FlightLeg $out, FlightLeg $back): Deal => Deal::roundTrip(
                source: self::NAME,
                title: sprintf('%s ⇄ %s', $out->origin->iataCode, $out->destination->iataCode),
                totalPrice: $out->price->plus($back->price),
                url: $this->bookingUrl($out->origin, $out->destination, $out->departsAt, $back->departsAt),
                origin: $out->origin,
                destination: $out->destination,
                departsAt: $out->departsAt,
                returnsAt: $back->departsAt,
            ),
        );

        return [...$pairs, ...$this->unpairedOneWays($outbound, $pairs)];
    }

    /**
     * A leg with no way back inside the window is still a cheap seat.
     *
     * @param  list<FlightLeg>  $outbound
     * @param  list<Deal>  $pairs
     * @return list<Deal>
     */
    private function unpairedOneWays(array $outbound, array $pairs): array
    {
        $paired = array_map(
            static fn (Deal $deal): string => (string) $deal->departsAt?->toIso8601String(),
            $pairs,
        );

        $deals = [];

        foreach ($outbound as $leg) {
            if (in_array($leg->departsAt->toIso8601String(), $paired, true)) {
                continue;
            }

            $deals[] = Deal::flight(
                source: self::NAME,
                title: sprintf('%s → %s', $leg->origin->iataCode, $leg->destination->iataCode),
                price: $leg->price,
                url: $this->bookingUrl($leg->origin, $leg->destination, $leg->departsAt),
                origin: $leg->origin,
                destination: $leg->destination,
                departsAt: $leg->departsAt,
            );
        }

        return $deals;
    }

    private function bookingUrl(
        Airport $origin,
        Airport $destination,
        CarbonImmutable $departsAt,
        ?CarbonImmutable $returnsAt = null,
    ): string {
        // No "#/" and no missing locale: the hash-bang form is the old site and
        // lands on the homepage with an empty search form, and the path needs
        // the locale segment to route at all.
        $path = sprintf(
            '%s/en-gb/booking/select-flight/%s/%s/%s',
            $this->bookingUrl,
            $origin->iataCode,
            $destination->iataCode,
            $departsAt->toDateString(),
        );

        return $returnsAt instanceof CarbonImmutable
            ? $path.'/'.$returnsAt->toDateString()
            : $path;
    }
}
