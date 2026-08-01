<?php

declare(strict_types=1);

namespace App\Domain\Deal\ValueObject;

use App\Domain\Deal\ScoreBasis;
use InvalidArgumentException;

/**
 * How good an offer is, on a 0-100 scale, with the figure it was judged on so
 * the dashboard can explain the verdict.
 */
final readonly class DealScore
{
    public function __construct(
        public int $value,
        public Money $ratedPrice,
        public ScoreBasis $basis,
    ) {
        if ($value < 0 || $value > 100) {
            throw new InvalidArgumentException('A score falls between 0 and 100.');
        }
    }

    public function isAtLeast(int $minimum): bool
    {
        return $this->value >= $minimum;
    }

    public function pricePerDay(): ?Money
    {
        return $this->basis === ScoreBasis::PricePerDay ? $this->ratedPrice : null;
    }
}
