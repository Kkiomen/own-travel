<?php

declare(strict_types=1);

namespace App\Domain\Deal\Service;

use App\Domain\Deal\Deal;
use App\Domain\Deal\ValueObject\FlightLeg;
use App\Domain\Deal\ValueObject\Money;
use App\Domain\Deal\ValueObject\StayWindow;

/**
 * Builds round trips out of individual legs, on our terms.
 *
 * Airlines pair legs their own way - Ryanair returns whatever pairing is
 * cheapest, Wizz Air does not pair at all - and the cheapest pairing is almost
 * never the one that fits a weekend. Given both directions day by day, this
 * decides what goes with what: the cheapest way back for a normal trip, and
 * separately the cheapest way back that turns it into a weekend.
 */
final readonly class RoundTripPairing
{
    private const MINIMUM_SAMPLE = 5;

    public function __construct(private WeekendGetaway $weekends) {}

    /**
     * @param  list<FlightLeg>  $outbound
     * @param  list<FlightLeg>  $inbound
     * @param  callable(FlightLeg, FlightLeg): Deal  $buildDeal
     * @return list<Deal>
     */
    public function pair(array $outbound, array $inbound, StayWindow $stay, callable $buildDeal): array
    {
        $deals = $this->buildPairs($outbound, $inbound, $stay, $buildDeal);
        $typical = $this->typicalPrice($deals);

        if ($typical === null) {
            return $deals;
        }

        return array_map(
            static fn (Deal $deal): Deal => $deal->comparedAgainst($typical),
            $deals,
        );
    }

    /**
     * What this route normally costs, taken as the middle of everything a
     * month of it offers. The median rather than the average, so a single
     * absurd fare cannot drag the yardstick with it.
     *
     * @param  list<Deal>  $deals
     */
    private function typicalPrice(array $deals): ?Money
    {
        // Too few pairings to know what "normal" even means here.
        if (count($deals) < self::MINIMUM_SAMPLE) {
            return null;
        }

        $prices = array_map(static fn (Deal $deal): int => $deal->price->minorUnits, $deals);
        sort($prices);

        $middle = intdiv(count($prices), 2);

        $median = count($prices) % 2 === 1
            ? $prices[$middle]
            : intdiv($prices[$middle - 1] + $prices[$middle], 2);

        return Money::fromMinorUnits($median, $deals[0]->price->currency);
    }

    /**
     * @param  list<FlightLeg>  $outbound
     * @param  list<FlightLeg>  $inbound
     * @param  callable(FlightLeg, FlightLeg): Deal  $buildDeal
     * @return list<Deal>
     */
    private function buildPairs(array $outbound, array $inbound, StayWindow $stay, callable $buildDeal): array
    {
        $deals = [];

        foreach ($outbound as $leg) {
            $withinWindow = $this->returnsFor($leg, $inbound, $stay);

            if ($withinWindow === []) {
                continue;
            }

            $cheapest = $withinWindow[0];
            $deals[] = $buildDeal($leg, $cheapest);

            $weekend = $this->cheapestWeekendReturn($leg, $withinWindow, $buildDeal);

            // Only worth adding when it is a different flight - otherwise the
            // cheapest way back already made a weekend of it.
            if ($weekend instanceof FlightLeg && ! $weekend->departsAt->equalTo($cheapest->departsAt)) {
                $deals[] = $buildDeal($leg, $weekend);
            }
        }

        return $deals;
    }

    /**
     * @param  list<FlightLeg>  $inbound
     * @return list<FlightLeg> cheapest first
     */
    private function returnsFor(FlightLeg $outbound, array $inbound, StayWindow $stay): array
    {
        $candidates = array_values(array_filter(
            $inbound,
            static fn (FlightLeg $leg): bool => $stay->allows($outbound->departsAt, $leg->departsAt),
        ));

        usort(
            $candidates,
            static fn (FlightLeg $a, FlightLeg $b): int => $a->price->minorUnits <=> $b->price->minorUnits,
        );

        return $candidates;
    }

    /**
     * @param  list<FlightLeg>  $candidates  cheapest first
     * @param  callable(FlightLeg, FlightLeg): Deal  $buildDeal
     */
    private function cheapestWeekendReturn(FlightLeg $outbound, array $candidates, callable $buildDeal): ?FlightLeg
    {
        foreach ($candidates as $candidate) {
            if ($this->weekends->matches($buildDeal($outbound, $candidate))) {
                return $candidate;
            }
        }

        return null;
    }
}
