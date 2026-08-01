<?php

declare(strict_types=1);

namespace App\Infrastructure\DealSource\Ryanair;

use App\Domain\Deal\ValueObject\Airport;
use App\Domain\Deal\ValueObject\Money;
use Carbon\CarbonImmutable;

/**
 * Ryanair describes a leg the same way in every fare finder response, so both
 * the one-way and the round-trip adapter read it through here.
 */
final readonly class FareReader
{
    public function airport(mixed $airport): ?Airport
    {
        if (! is_array($airport) || ! is_string($airport['iataCode'] ?? null)) {
            return null;
        }

        return Airport::fromIataCode(
            $airport['iataCode'],
            is_string($airport['name'] ?? null) ? $airport['name'] : null,
            is_string($airport['countryName'] ?? null) ? $airport['countryName'] : null,
        );
    }

    public function money(mixed $price): ?Money
    {
        if (! is_array($price) || ! is_numeric($price['value'] ?? null) || ! is_string($price['currencyCode'] ?? null)) {
            return null;
        }

        return Money::fromDecimal($price['value'], $price['currencyCode']);
    }

    public function moment(mixed $date): ?CarbonImmutable
    {
        return is_string($date) ? CarbonImmutable::parse($date) : null;
    }

    public function bookingUrl(
        string $bookingUrl,
        Airport $origin,
        Airport $destination,
        CarbonImmutable $departsAt,
        ?CarbonImmutable $returnsAt = null,
    ): string {
        $query = [
            'adults' => 1,
            'dateOut' => $departsAt->toDateString(),
            'originIata' => $origin->iataCode,
            'destinationIata' => $destination->iataCode,
        ];

        if ($returnsAt instanceof CarbonImmutable) {
            $query['dateIn'] = $returnsAt->toDateString();
            $query['isReturn'] = 'true';
        }

        return $bookingUrl.'?'.http_build_query($query);
    }
}
