<?php

declare(strict_types=1);

namespace App\Domain\Deal\Service;

use App\Domain\Deal\Deal;
use App\Domain\Deal\DealType;
use App\Domain\Deal\ScoreBasis;
use App\Domain\Deal\ValueObject\DealScore;
use App\Domain\Deal\ValueObject\Money;
use App\Domain\Deal\ValueObject\PriceBand;

/**
 * Rates every offer on one 0-100 scale, so a cheap flight and a cheap trip can
 * sit in the same sorted list.
 *
 * Flights and round trips are judged on what they cost outright. Blog trips
 * are judged on what a day of them costs, because a headline price says
 * nothing on its own - 2500 PLN is a steal for ten days and a rip-off for
 * three. A trip whose length the article never gave up falls back to its
 * total price.
 */
final readonly class DealScorer
{
    public function __construct(
        private PriceBand $flights,
        private PriceBand $roundTrips,
        private PriceBand $tripsPerDay,
        private PriceBand $tripsInTotal,
    ) {}

    public function score(Deal $deal): DealScore
    {
        if ($deal->type === DealType::Flight) {
            return new DealScore($this->flights->rate($deal->price), $deal->price, ScoreBasis::TotalPrice);
        }

        // Both legs together, judged on what the whole thing costs.
        if ($deal->type === DealType::RoundTrip) {
            return new DealScore($this->roundTrips->rate($deal->price), $deal->price, ScoreBasis::TotalPrice);
        }

        $days = $deal->trip?->days;

        if ($days === null) {
            return new DealScore($this->tripsInTotal->rate($deal->price), $deal->price, ScoreBasis::TotalPrice);
        }

        $perDay = Money::fromMinorUnits(
            (int) round($deal->price->minorUnits / $days),
            $deal->price->currency,
        );

        return new DealScore($this->tripsPerDay->rate($perDay), $perDay, ScoreBasis::PricePerDay);
    }
}
