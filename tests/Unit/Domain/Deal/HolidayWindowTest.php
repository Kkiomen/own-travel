<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Deal;

use App\Domain\Deal\ValueObject\HolidayWindow;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class HolidayWindowTest extends TestCase
{
    public function test_it_refuses_leave_that_ends_before_it_starts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HolidayWindow(
            CarbonImmutable::parse('2026-09-20'),
            CarbonImmutable::parse('2026-09-12'),
        );
    }

    public function test_a_single_day_off_is_a_holiday(): void
    {
        $window = $this->holiday('2026-09-12', '2026-09-12');

        $this->assertSame(1, $window->days());
        $this->assertTrue($window->covers(CarbonImmutable::parse('2026-09-12 06:00')));
    }

    public function test_it_counts_both_ends_of_the_leave(): void
    {
        // The 12th to the 20th is nine days off, not eight.
        $this->assertSame(9, $this->holiday('2026-09-12', '2026-09-20')->days());
    }

    /**
     * Leave is granted in whole days: nobody books the 12th and means "from
     * midnight". An evening departure on the first day is the normal case, and
     * treating the bound as a moment would throw it away.
     */
    public function test_the_bounds_are_whole_days(): void
    {
        $window = $this->holiday('2026-09-12', '2026-09-20');

        $this->assertTrue($window->covers(CarbonImmutable::parse('2026-09-12 19:05')));
        $this->assertTrue($window->covers(
            CarbonImmutable::parse('2026-09-13 06:00'),
            CarbonImmutable::parse('2026-09-20 23:55'),
        ));
    }

    public function test_a_journey_leaving_before_the_leave_does_not_fit(): void
    {
        $window = $this->holiday('2026-09-12', '2026-09-20');

        $this->assertFalse($window->covers(
            CarbonImmutable::parse('2026-09-11 18:30'),
            CarbonImmutable::parse('2026-09-14 20:00'),
        ));
    }

    /**
     * The whole journey has to fit. A cheap way out on the first day is no use
     * if the way back lands after work has started again.
     */
    public function test_a_journey_returning_after_the_leave_does_not_fit(): void
    {
        $window = $this->holiday('2026-09-12', '2026-09-20');

        $this->assertFalse($window->covers(
            CarbonImmutable::parse('2026-09-12 06:00'),
            CarbonImmutable::parse('2026-09-21 09:00'),
        ));
    }

    public function test_a_one_way_flight_is_judged_on_its_departure_alone(): void
    {
        $window = $this->holiday('2026-09-12', '2026-09-20');

        $this->assertTrue($window->covers(CarbonImmutable::parse('2026-09-20 22:00')));
        $this->assertFalse($window->covers(CarbonImmutable::parse('2026-09-21 07:00')));
    }

    private function holiday(string $from, string $to): HolidayWindow
    {
        return new HolidayWindow(
            CarbonImmutable::parse($from),
            CarbonImmutable::parse($to),
        );
    }
}
