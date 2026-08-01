<?php

declare(strict_types=1);

namespace App\Domain\Deal\ValueObject;

use InvalidArgumentException;

/**
 * The range a price is judged against: at or under the great price scores 100,
 * at or over the poor price scores 0, linear in between so small differences
 * stay visible.
 */
final readonly class PriceBand
{
    public function __construct(
        public Money $great,
        public Money $poor,
    ) {
        if (! $great->isLessThan($poor)) {
            throw new InvalidArgumentException('The great price must be cheaper than the poor one.');
        }
    }

    public function rate(Money $price): int
    {
        if ($price->isAtMost($this->great)) {
            return 100;
        }

        if (! $price->isLessThan($this->poor)) {
            return 0;
        }

        return (int) round(
            100 * ($this->poor->minorUnits - $price->minorUnits)
            / ($this->poor->minorUnits - $this->great->minorUnits),
        );
    }
}
