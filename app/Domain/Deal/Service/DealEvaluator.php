<?php

declare(strict_types=1);

namespace App\Domain\Deal\Service;

use App\Domain\Deal\Deal;
use App\Domain\Deal\DealType;
use App\Domain\Deal\ValueObject\Money;

/**
 * Two gates, deliberately separate.
 *
 * Price decides what is kept at all, so the dashboard shows everything within
 * budget. The score decides what is worth being told about, so a cheap but
 * mediocre trip does not earn an alert.
 */
final readonly class DealEvaluator
{
    public function __construct(
        private Money $maxFlightPrice,
        private Money $maxRoundTripPrice,
        private Money $maxTripPrice,
        private int $minimumTripScore,
    ) {}

    public function isWorthKeeping(Deal $deal): bool
    {
        return $deal->price->isAtMost($this->thresholdFor($deal->type));
    }

    public function isWorthAlerting(Deal $deal): bool
    {
        if (! $this->isWorthKeeping($deal)) {
            return false;
        }

        // An unrated offer - a flight, or a trip whose length we could not
        // read - is judged on price alone.
        if ($deal->score === null) {
            return true;
        }

        return $deal->score->isAtLeast($this->minimumTripScore);
    }

    public function thresholdFor(DealType $type): Money
    {
        return match ($type) {
            DealType::Flight => $this->maxFlightPrice,
            DealType::RoundTrip => $this->maxRoundTripPrice,
            DealType::Trip => $this->maxTripPrice,
        };
    }

    public function minimumTripScore(): int
    {
        return $this->minimumTripScore;
    }
}
