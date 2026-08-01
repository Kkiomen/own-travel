<?php

declare(strict_types=1);

namespace App\Domain\Deal\Service;

use App\Domain\Deal\Deal;
use Carbon\CarbonImmutable;

/**
 * Decides whether both legs fit into a weekend nobody has to book leave for:
 * out on Friday afternoon or Saturday morning, back on Sunday.
 *
 * The hours matter. A Friday flight at 06:00 means taking the day off, and a
 * Saturday flight at 21:00 wastes the only full day of the trip.
 */
final readonly class WeekendGetaway
{
    /**
     * @param  int  $fridayFromHour  the earliest a Friday departure may leave
     * @param  int  $saturdayUntilHour  the latest a Saturday departure may leave
     * @param  list<int>  $returnDays  ISO weekdays a return may fall on (7 = Sunday)
     */
    public function __construct(
        private int $fridayFromHour = 15,
        private int $saturdayUntilHour = 12,
        private array $returnDays = [CarbonImmutable::SUNDAY],
    ) {}

    public function matches(Deal $deal): bool
    {
        $departure = $deal->departsAt;
        $return = $deal->returnsAt;

        if ($departure === null || $return === null) {
            return false;
        }

        return $this->leavesInTime($departure)
            && $this->comesBackInTime($return)
            && $this->isTheSameWeekend($departure, $return);
    }

    /**
     * Leaving on Friday and coming back on a Sunday nine days later still
     * lands on the right weekdays - it is simply not a weekend.
     */
    private function isTheSameWeekend(CarbonImmutable $departure, CarbonImmutable $return): bool
    {
        return (int) $departure->startOfDay()->diffInDays($return->startOfDay()) <= 3;
    }

    private function leavesInTime(CarbonImmutable $departure): bool
    {
        if ($departure->isFriday()) {
            return $departure->hour >= $this->fridayFromHour;
        }

        if ($departure->isSaturday()) {
            return $departure->hour < $this->saturdayUntilHour;
        }

        return false;
    }

    private function comesBackInTime(CarbonImmutable $return): bool
    {
        return in_array($return->dayOfWeek, $this->returnDays, true);
    }
}
