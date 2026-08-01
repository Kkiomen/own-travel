<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Deal;

use App\Domain\Deal\Deal;
use App\Domain\Deal\DealType;
use App\Domain\Deal\Service\WeekendGetaway;
use App\Domain\Deal\ValueObject\Airport;
use App\Domain\Deal\ValueObject\Money;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WeekendGetawayTest extends TestCase
{
    #[DataProvider('pairings')]
    public function test_it_recognises_a_weekend_anyone_could_take(
        string $departure,
        string $return,
        bool $expected,
    ): void {
        $deal = $this->roundTrip($departure, $return);

        $this->assertSame($expected, (new WeekendGetaway)->matches($deal));
    }

    /**
     * 2026-08-07 is a Friday, 08-08 a Saturday, 08-09 a Sunday.
     *
     * @return iterable<string, array{string, string, bool}>
     */
    public static function pairings(): iterable
    {
        yield 'friday evening out, sunday back' => ['2026-08-07 18:30', '2026-08-09 20:00', true];
        yield 'friday exactly at the cut-off' => ['2026-08-07 15:00', '2026-08-09 20:00', true];
        yield 'friday morning costs a day off' => ['2026-08-07 06:10', '2026-08-09 20:00', false];
        yield 'saturday morning out, sunday back' => ['2026-08-08 07:45', '2026-08-09 21:30', true];
        yield 'saturday evening wastes the trip' => ['2026-08-08 21:00', '2026-08-09 21:30', false];
        yield 'back on monday needs leave' => ['2026-08-07 18:30', '2026-08-10 08:00', false];
        yield 'midweek is not a weekend' => ['2026-08-05 18:30', '2026-08-09 20:00', false];
        yield 'a whole week is not a weekend' => ['2026-08-07 18:30', '2026-08-16 20:00', false];
    }

    public function test_the_hours_are_configurable(): void
    {
        $earlyBird = new WeekendGetaway(fridayFromHour: 6, saturdayUntilHour: 12);

        $this->assertTrue($earlyBird->matches($this->roundTrip('2026-08-07 06:10', '2026-08-09 20:00')));
    }

    public function test_a_one_way_flight_is_never_a_weekend_getaway(): void
    {
        $flight = Deal::flight(
            source: 'ryanair',
            title: 'Wrocław → Oslo',
            price: Money::fromDecimal(62, 'PLN'),
            url: 'https://example.test',
            origin: Airport::fromIataCode('WRO'),
            destination: Airport::fromIataCode('TRF'),
            departsAt: CarbonImmutable::parse('2026-08-07 18:30'),
        );

        $this->assertFalse((new WeekendGetaway)->matches($flight));
    }

    public function test_a_blog_trip_is_never_a_weekend_getaway(): void
    {
        $trip = Deal::fromFeed(
            'fly4free',
            DealType::Trip,
            'Rzym za 168 PLN',
            Money::fromDecimal(168, 'PLN'),
            'https://example.test',
        );

        $this->assertFalse((new WeekendGetaway)->matches($trip));
    }

    private function roundTrip(string $departure, string $return): Deal
    {
        return Deal::roundTrip(
            source: 'ryanair-return',
            title: 'Wrocław ⇄ Mediolan',
            totalPrice: Money::fromDecimal(199, 'PLN'),
            url: 'https://example.test',
            origin: Airport::fromIataCode('WRO'),
            destination: Airport::fromIataCode('BGY'),
            departsAt: CarbonImmutable::parse($departure),
            returnsAt: CarbonImmutable::parse($return),
        );
    }
}
