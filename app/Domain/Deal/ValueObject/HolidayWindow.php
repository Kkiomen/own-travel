<?php

declare(strict_types=1);

namespace App\Domain\Deal\ValueObject;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The days the owner is actually free to travel on.
 *
 * This is not the same idea as a TravelWindow: that one is a date an offer can
 * be taken on, read off somebody's blog. This one is leave already booked, and
 * an offer is only interesting if the whole journey fits inside it - a flight
 * out on the last day is not a holiday, and a return a week after work starts
 * again is unbookable.
 *
 * The bounds are whole days: leave granted for the 12th covers a flight at
 * seven in the evening, and one ending on the 20th covers a landing at
 * midnight.
 */
final readonly class HolidayWindow
{
    public CarbonImmutable $from;

    public CarbonImmutable $to;

    public function __construct(CarbonImmutable $from, CarbonImmutable $to)
    {
        if ($to->lessThan($from)) {
            throw new InvalidArgumentException('A holiday cannot end before it starts.');
        }

        $this->from = $from->startOfDay();
        $this->to = $to->endOfDay();
    }

    /**
     * Whether a journey leaving and returning on these moments can be taken on
     * this leave. A one-way flight has no return, and is judged on its
     * departure alone.
     */
    public function covers(CarbonImmutable $departure, ?CarbonImmutable $return = null): bool
    {
        if ($departure->lessThan($this->from)) {
            return false;
        }

        return ! ($return ?? $departure)->greaterThan($this->to);
    }

    /**
     * How many days off this is, counting both ends - the 12th to the 20th is
     * nine days, not eight.
     */
    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to->startOfDay()) + 1;
    }
}
