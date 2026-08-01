<?php

declare(strict_types=1);

namespace App\Infrastructure\DealSource;

use App\Domain\Deal\Deal;
use App\Domain\Deal\Exception\DealSourceUnavailable;
use App\Domain\Deal\Port\DealSource;
use App\Domain\Deal\Service\RoundTripPairing;
use App\Domain\Deal\ValueObject\Airport;
use App\Domain\Deal\ValueObject\FlightLeg;
use App\Domain\Deal\ValueObject\SearchCriteria;
use App\Infrastructure\DealSource\Ryanair\FareReader;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpClient;
use Throwable;

/**
 * Prices a route day by day in both directions and pairs the legs ourselves.
 *
 * The fare finder will pair legs for us, but only its way: it returns whatever
 * pairing is cheapest, which is almost never the Friday-to-Sunday one. Here we
 * take the raw per-day prices - with their real departure times - and decide
 * what goes with what, so the rules that matter (how long the stay is, whether
 * it fits a weekend) stay ours.
 *
 * Routes are the ones the one-way search already found something cheap on, so
 * nothing is configured by hand and nothing is asked about twice.
 */
final readonly class RyanairRoutePairingSource implements DealSource
{
    public const NAME = 'ryanair-pairs';

    public function __construct(
        private HttpClient $http,
        private FareReader $fares,
        private RoundTripPairing $pairing,
        private string $baseUrl,
        private string $bookingUrl,
        private string $market,
        private int $timeoutSeconds,
        /** @var list<string> IATA codes of the airports worth pairing from */
        private array $homeAirports,
        private int $routesPerAirport,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function findDeals(SearchCriteria $criteria): array
    {
        $airports = array_values(array_intersect($this->homeAirports, $criteria->departureIataCodes()));

        if ($airports === [] || $this->routesPerAirport < 1) {
            return [];
        }

        $deals = [];
        $failures = 0;

        foreach ($airports as $iataCode) {
            try {
                $deals = [...$deals, ...$this->pairRoutesFrom($iataCode, $criteria)];
            } catch (Throwable) {
                $failures++;
            }
        }

        if ($failures === count($airports)) {
            throw DealSourceUnavailable::forSource(
                self::NAME,
                sprintf('no usable response for any of the %d home airports', $failures),
            );
        }

        return $deals;
    }

    /**
     * @return list<Deal>
     */
    private function pairRoutesFrom(string $iataCode, SearchCriteria $criteria): array
    {
        $deals = [];

        foreach ($this->routesWorthPairing($iataCode, $criteria) as [$origin, $destination]) {
            $outbound = $this->legsFor($origin, $destination, $criteria);
            $inbound = $this->legsFor($destination, $origin, $criteria);

            if ($outbound === [] || $inbound === []) {
                continue;
            }

            $deals = [...$deals, ...$this->pairing->pair(
                $outbound,
                $inbound,
                $criteria->stay,
                fn (FlightLeg $out, FlightLeg $back): Deal => $this->toDeal($out, $back),
            )];
        }

        return $deals;
    }

    /**
     * The one-way search already knows where it is cheap to fly from here.
     *
     * @return list<array{0: Airport, 1: Airport}>
     */
    private function routesWorthPairing(string $iataCode, SearchCriteria $criteria): array
    {
        $response = $this->http
            ->timeout($this->timeoutSeconds)
            ->get($this->baseUrl.'/farfnd/v4/oneWayFares', [
                'departureAirportIataCode' => $iataCode,
                'outboundDepartureDateFrom' => $criteria->departureFrom->toDateString(),
                'outboundDepartureDateTo' => $criteria->departureTo->toDateString(),
                'priceValueTo' => $criteria->maxFlightPrice->toDecimal(),
                'currency' => $criteria->maxFlightPrice->currency,
                'market' => $this->market,
                'adultPaxCount' => 1,
                'limit' => 100,
            ]);

        if ($response->failed()) {
            throw DealSourceUnavailable::forSource(
                self::NAME,
                sprintf('HTTP %d listing routes from %s', $response->status(), $iataCode),
            );
        }

        $fares = $response->json('fares');
        $routes = [];

        foreach (is_array($fares) ? $fares : [] as $fare) {
            $outbound = is_array($fare) ? ($fare['outbound'] ?? null) : null;

            if (! is_array($outbound)) {
                continue;
            }

            $origin = $this->fares->airport($outbound['departureAirport'] ?? null);
            $destination = $this->fares->airport($outbound['arrivalAirport'] ?? null);

            if ($origin === null || $destination === null) {
                continue;
            }

            // Cheapest first, so the first ones seen are the ones worth pairing.
            $routes[$destination->iataCode] ??= [$origin, $destination];
        }

        return array_slice(array_values($routes), 0, $this->routesPerAirport);
    }

    /**
     * @return list<FlightLeg>
     */
    private function legsFor(Airport $origin, Airport $destination, SearchCriteria $criteria): array
    {
        $legs = [];

        foreach ($this->monthsIn($criteria) as $month) {
            foreach ($this->legsInMonth($origin, $destination, $month, $criteria) as $leg) {
                $legs[] = $leg;
            }
        }

        return $legs;
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function monthsIn(SearchCriteria $criteria): array
    {
        $months = [];
        // Returns may fall after the last day we would leave on.
        $last = $criteria->latestReturn()->startOfMonth();

        for ($month = $criteria->departureFrom->startOfMonth(); ! $month->greaterThan($last); $month = $month->addMonth()) {
            $months[] = $month;
        }

        return $months;
    }

    /**
     * @return list<FlightLeg>
     */
    private function legsInMonth(
        Airport $origin,
        Airport $destination,
        CarbonImmutable $month,
        SearchCriteria $criteria,
    ): array {
        $response = $this->http
            ->timeout($this->timeoutSeconds)
            ->get(sprintf(
                '%s/farfnd/v4/oneWayFares/%s/%s/cheapestPerDay',
                $this->baseUrl,
                $origin->iataCode,
                $destination->iataCode,
            ), [
                'outboundMonthOfDate' => $month->toDateString(),
                'currency' => $criteria->maxRoundTripPrice->currency,
                'market' => $this->market,
            ]);

        if ($response->failed()) {
            return [];
        }

        $fares = $response->json('outbound.fares');
        $legs = [];

        foreach (is_array($fares) ? $fares : [] as $fare) {
            if (! is_array($fare) || ($fare['soldOut'] ?? false) === true || ($fare['unavailable'] ?? false) === true) {
                continue;
            }

            $departsAt = $this->fares->moment($fare['departureDate'] ?? null);
            $price = $this->fares->money($fare['price'] ?? null);

            if ($departsAt === null || $price === null) {
                continue;
            }

            $legs[] = new FlightLeg($origin, $destination, $departsAt, $price);
        }

        return $legs;
    }

    private function toDeal(FlightLeg $outbound, FlightLeg $inbound): Deal
    {
        return Deal::roundTrip(
            source: self::NAME,
            title: sprintf('%s ⇄ %s', $outbound->origin->label(), $outbound->destination->label()),
            totalPrice: $outbound->price->plus($inbound->price),
            url: $this->fares->bookingUrl(
                $this->bookingUrl,
                $outbound->origin,
                $outbound->destination,
                $outbound->departsAt,
                $inbound->departsAt,
            ),
            origin: $outbound->origin,
            destination: $outbound->destination,
            departsAt: $outbound->departsAt,
            returnsAt: $inbound->departsAt,
        );
    }
}
