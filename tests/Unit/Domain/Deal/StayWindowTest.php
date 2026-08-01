<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Deal;

use App\Domain\Deal\ValueObject\StayWindow;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class StayWindowTest extends TestCase
{
    public function test_it_accepts_a_stay_inside_the_window(): void
    {
        $window = new StayWindow(2, 10);

        $this->assertTrue($window->allows($this->day('2026-08-10'), $this->day('2026-08-13')));
    }

    public function test_it_accepts_the_edges_of_the_window(): void
    {
        $window = new StayWindow(2, 10);

        $this->assertTrue($window->allows($this->day('2026-08-10'), $this->day('2026-08-12')));
        $this->assertTrue($window->allows($this->day('2026-08-10'), $this->day('2026-08-20')));
    }

    public function test_it_rejects_a_return_a_month_later(): void
    {
        $window = new StayWindow(2, 10);

        $this->assertFalse($window->allows($this->day('2026-08-10'), $this->day('2026-09-10')));
    }

    public function test_it_rejects_a_return_that_is_too_soon(): void
    {
        $window = new StayWindow(2, 10);

        $this->assertFalse($window->allows($this->day('2026-08-10'), $this->day('2026-08-11')));
    }

    public function test_it_works_out_the_dates_a_return_could_fall_on(): void
    {
        $window = new StayWindow(3, 7);

        $this->assertSame('2026-08-13', $window->earliestReturn($this->day('2026-08-10'))->toDateString());
        $this->assertSame('2026-08-17', $window->latestReturn($this->day('2026-08-10'))->toDateString());
    }

    public function test_it_refuses_an_impossible_window(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StayWindow(10, 2);
    }

    public function test_it_refuses_a_stay_without_a_night(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StayWindow(0, 5);
    }

    private function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date.' 06:00');
    }
}
