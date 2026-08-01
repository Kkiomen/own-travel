<?php

declare(strict_types=1);

namespace App\Infrastructure\DealSource;

use App\Domain\Deal\Deal;
use App\Domain\Deal\Exception\DealSourceUnavailable;
use App\Domain\Deal\Port\DealSource;
use App\Domain\Deal\ValueObject\SearchCriteria;
use App\Infrastructure\DealSource\Ryanair\FareReader;
use Illuminate\Http\Client\Factory as HttpClient;
use Throwable;

/**
 * Ryanair's public fare finder, one leg at a time. It already filters by price
 * and date window, so a single request per departure airport returns
 * everything we want.
 *
 * Worth keeping alongside the round-trip source: a one-way bargain is still
 * interesting when the way back is covered some other way.
 */
final readonly class RyanairFareFinderSource implements DealSource
{
    public const NAME = 'ryanair';

    public function __construct(
        private HttpClient $http,
        private FareReader $fares,
        private string $baseUrl,
        private string $bookingUrl,
        private string $market,
        private int $timeoutSeconds,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function findDeals(SearchCriteria $criteria): array
    {
        $deals = [];
        $failures = 0;
        $airports = $criteria->departureIataCodes();

        foreach ($airports as $iataCode) {
            try {
                $deals = [...$deals, ...$this->findDealsFrom($iataCode, $criteria)];
            } catch (Throwable) {
                $failures++;
            }
        }

        // A single unreachable airport is noise; all of them means the source is down.
        if ($failures === count($airports)) {
            throw DealSourceUnavailable::forSource(
                self::NAME,
                sprintf('no usable response for any of the %d departure airports', $failures),
            );
        }

        return $deals;
    }

    /**
     * @return list<Deal>
     */
    private function findDealsFrom(string $departureIataCode, SearchCriteria $criteria): array
    {
        $response = $this->http
            ->timeout($this->timeoutSeconds)
            ->get($this->baseUrl.'/farfnd/v4/oneWayFares', [
                'departureAirportIataCode' => $departureIataCode,
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
                sprintf('HTTP %d for departure airport %s', $response->status(), $departureIataCode),
            );
        }

        $fares = $response->json('fares');

        if (! is_array($fares)) {
            throw DealSourceUnavailable::forSource(self::NAME, 'unexpected payload without a fares list');
        }

        $deals = [];

        foreach ($fares as $fare) {
            $deal = $this->toDeal(is_array($fare) ? $fare : []);

            if ($deal instanceof Deal) {
                $deals[] = $deal;
            }
        }

        return $deals;
    }

    /**
     * @param  array<string, mixed>  $fare
     */
    private function toDeal(array $fare): ?Deal
    {
        $outbound = $fare['outbound'] ?? null;

        if (! is_array($outbound)) {
            return null;
        }

        $origin = $this->fares->airport($outbound['departureAirport'] ?? null);
        $destination = $this->fares->airport($outbound['arrivalAirport'] ?? null);
        $price = $this->fares->money($outbound['price'] ?? null);
        $departsAt = $this->fares->moment($outbound['departureDate'] ?? null);

        if ($origin === null || $destination === null || $price === null || $departsAt === null) {
            return null;
        }

        return Deal::flight(
            source: self::NAME,
            title: sprintf('%s → %s', $origin->label(), $destination->label()),
            price: $price,
            url: $this->fares->bookingUrl($this->bookingUrl, $origin, $destination, $departsAt),
            origin: $origin,
            destination: $destination,
            departsAt: $departsAt,
        );
    }
}
