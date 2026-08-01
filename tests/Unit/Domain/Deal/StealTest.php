<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Deal;

use App\Domain\Deal\Deal;
use App\Domain\Deal\Service\Steal;
use App\Domain\Deal\ValueObject\Airport;
use App\Domain\Deal\ValueObject\Money;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class StealTest extends TestCase
{
    private Steal $steal;

    protected function setUp(): void
    {
        parent::setUp();

        // At least 40% below the usual price, and never above 400 PLN.
        $this->steal = new Steal(0.4, Money::fromDecimal(400, 'PLN'));
    }

    public function test_a_weekend_far_below_the_usual_price_is_a_steal(): void
    {
        // London normally 520, this one 308 - 41% off.
        $this->assertTrue($this->steal->matches($this->roundTrip(308, typical: 520)));
    }

    public function test_merely_cheap_is_not_a_steal(): void
    {
        // 308 against a usual 400 is only 23% off.
        $this->assertFalse($this->steal->matches($this->roundTrip(308, typical: 400)));
    }

    /**
     * Cheap is relative to the route, but not infinitely so - a long-haul at
     * half price is still not a weekend anyone here is booking.
     */
    public function test_a_big_discount_on_an_expensive_route_is_still_not_a_steal(): void
    {
        $this->assertFalse($this->steal->matches($this->roundTrip(900, typical: 2400)));
    }

    public function test_a_deal_with_nothing_to_compare_against_is_not_a_steal(): void
    {
        $this->assertFalse($this->steal->matches($this->roundTrip(199, typical: null)));
    }

    public function test_it_reports_how_far_below_the_usual_price_an_offer_is(): void
    {
        $this->assertSame(0.5, $this->roundTrip(260, typical: 520)->discount());
        $this->assertNull($this->roundTrip(260, typical: null)->discount());
    }

    public function test_a_price_above_the_usual_one_is_not_a_negative_discount(): void
    {
        $this->assertSame(0.0, $this->roundTrip(600, typical: 520)->discount());
    }

    private function roundTrip(float $price, ?float $typical): Deal
    {
        $deal = Deal::roundTrip(
            source: 'wizzair',
            title: 'Wrocław ⇄ Londyn-Luton',
            totalPrice: Money::fromDecimal($price, 'PLN'),
            url: 'https://example.test',
            origin: Airport::fromIataCode('WRO'),
            destination: Airport::fromIataCode('LTN'),
            departsAt: CarbonImmutable::parse('2026-09-18 18:30'),
            returnsAt: CarbonImmutable::parse('2026-09-20 20:00'),
        );

        return $typical === null
            ? $deal
            : $deal->comparedAgainst(Money::fromDecimal($typical, 'PLN'));
    }
}
