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
 * Ryanair's fare finder can pair both legs itself and cap how long the stay
 * lasts, which is exactly what makes an offer bookable as leave: it returns
 * the total price and the length of the trip, not a cheap seat out with the
 * way back left as an exercise.
 */
final readonly class RyanairRoundTripSource implements DealSource
{
    public const NAME = 'ryanair-return';

    private const MAX_RESULTS = 20;

    public function __construct(
        private HttpClient $http,
        private FareReader $fares,
        private string $name,
        private string $baseUrl,
        private string $bookingUrl,
        private string $market,
        private int $timeoutSeconds,
    ) {}

    public function name(): string
    {
        return $this->name;
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

        if ($failures === count($airports)) {
            throw DealSourceUnavailable::forSource(
                $this->name,
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
        $stay = $criteria->stay;

        $response = $this->http
            ->timeout($this->timeoutSeconds)
            ->get($this->baseUrl.'/farfnd/v4/roundTripFares', [
                'departureAirportIataCode' => $departureIataCode,
                'outboundDepartureDateFrom' => $criteria->departureFrom->toDateString(),
                'outboundDepartureDateTo' => $criteria->departureTo->toDateString(),
                'inboundDepartureDateFrom' => $stay->earliestReturn($criteria->departureFrom)->toDateString(),
                'inboundDepartureDateTo' => $stay->latestReturn($criteria->departureTo)->toDateString(),
                'durationFrom' => $stay->minimumNights,
                'durationTo' => $stay->maximumNights,
                'priceValueTo' => $criteria->maxRoundTripPrice->toDecimal(),
                'currency' => $criteria->maxRoundTripPrice->currency,
                'market' => $this->market,
                'adultPaxCount' => 1,
                // The round-trip endpoint rejects anything above 20 with
                // "InvalidLimit". It answers cheapest-first, so this is the 20
                // best pairings from each airport rather than an arbitrary
                // slice.
                'limit' => self::MAX_RESULTS,
            ]);

        if ($response->failed()) {
            throw DealSourceUnavailable::forSource(
                $this->name,
                sprintf('HTTP %d for departure airport %s', $response->status(), $departureIataCode),
            );
        }

        $fares = $response->json('fares');

        if (! is_array($fares)) {
            throw DealSourceUnavailable::forSource($this->name, 'unexpected payload without a fares list');
        }

        $deals = [];

        foreach ($fares as $fare) {
            $deal = $this->toDeal(is_array($fare) ? $fare : [], $criteria);

            if ($deal instanceof Deal) {
                $deals[] = $deal;
            }
        }

        return $deals;
    }

    /**
     * @param  array<string, mixed>  $fare
     */
    private function toDeal(array $fare, SearchCriteria $criteria): ?Deal
    {
        $outbound = $fare['outbound'] ?? null;
        $inbound = $fare['inbound'] ?? null;
        $summary = $fare['summary'] ?? null;

        if (! is_array($outbound) || ! is_array($inbound) || ! is_array($summary)) {
            return null;
        }

        $origin = $this->fares->airport($outbound['departureAirport'] ?? null);
        $destination = $this->fares->airport($outbound['arrivalAirport'] ?? null);
        $departsAt = $this->fares->moment($outbound['departureDate'] ?? null);
        $returnsAt = $this->fares->moment($inbound['departureDate'] ?? null);
        $total = $this->fares->money($summary['price'] ?? null);

        if ($origin === null || $destination === null || $departsAt === null || $returnsAt === null || $total === null) {
            return null;
        }

        // Ryanair is asked for a sensible stay, but never trust the answer.
        if (! $criteria->stay->allows($departsAt, $returnsAt)) {
            return null;
        }

        return Deal::roundTrip(
            source: $this->name,
            title: sprintf('%s ⇄ %s', $origin->label(), $destination->label()),
            totalPrice: $total,
            url: $this->fares->bookingUrl($this->bookingUrl, $origin, $destination, $departsAt, $returnsAt),
            origin: $origin,
            destination: $destination,
            departsAt: $departsAt,
            returnsAt: $returnsAt,
        );
    }
}
