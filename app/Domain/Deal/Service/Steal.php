<?php

declare(strict_types=1);

namespace App\Domain\Deal\Service;

use App\Domain\Deal\Deal;
use App\Domain\Deal\ValueObject\Money;

/**
 * Tells a bargain from a genuine steal.
 *
 * "Cheap" is relative to the route: 308 PLN to London is a good weekend, 308
 * PLN to Gdańsk is not. Since a scan prices a whole month of a route in both
 * directions, we know what it normally costs - and an offer far below that is
 * the one worth shouting about, whatever the absolute number.
 *
 * A hard ceiling still applies, so an unusually cheap but still expensive
 * route never qualifies.
 */
final readonly class Steal
{
    /**
     * @param  float  $minimumDiscount  how far below the usual price it must be
     */
    public function __construct(
        private float $minimumDiscount,
        private Money $ceiling,
    ) {}

    public function matches(Deal $deal): bool
    {
        $discount = $deal->discount();

        return $discount !== null
            && $discount >= $this->minimumDiscount
            && $deal->price->isAtMost($this->ceiling);
    }
}
