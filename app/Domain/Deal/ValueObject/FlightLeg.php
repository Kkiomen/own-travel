<?php

declare(strict_types=1);

namespace App\Domain\Deal\ValueObject;

use Carbon\CarbonImmutable;

/**
 * One direction, on one day, at one price.
 *
 * Airlines sell legs; whether two of them add up to a trip worth taking is our
 * decision, not theirs.
 */
final readonly class FlightLeg
{
    public function __construct(
        public Airport $origin,
        public Airport $destination,
        public CarbonImmutable $departsAt,
        public Money $price,
    ) {}
}
