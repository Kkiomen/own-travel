<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Deal;

use App\Domain\Deal\Deal;
use App\Domain\Deal\DealType;
use App\Domain\Deal\ValueObject\Airport;
use App\Domain\Deal\ValueObject\Money;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class DealTest extends TestCase
{
    public function test_the_same_flight_twice_has_the_same_fingerprint(): void
    {
        $this->assertSame(
            $this->flight(99)->fingerprint(),
            $this->flight(99)->fingerprint(),
        );
    }

    public function test_a_price_change_makes_it_a_different_deal(): void
    {
        $this->assertNotSame(
            $this->flight(99)->fingerprint(),
            $this->flight(79)->fingerprint(),
        );
    }

    public function test_feed_items_are_identified_by_their_link(): void
    {
        $first = Deal::fromFeed('fly4free', DealType::Trip, 'Rodos', Money::fromDecimal(2518, 'PLN'), 'https://example.test/a');
        $second = Deal::fromFeed('fly4free', DealType::Trip, 'Rodos', Money::fromDecimal(2518, 'PLN'), 'https://example.test/b');

        $this->assertNotSame($first->fingerprint(), $second->fingerprint());
    }

    public function test_flights_expose_a_route_label(): void
    {
        $this->assertSame('KRK → AGP', $this->flight(99)->routeLabel());
    }

    public function test_feed_items_have_no_route(): void
    {
        $deal = Deal::fromFeed('fly4free', DealType::Trip, 'Rodos', Money::fromDecimal(2518, 'PLN'), 'https://example.test');

        $this->assertNull($deal->routeLabel());
    }

    private function flight(float $price): Deal
    {
        return Deal::flight(
            source: 'ryanair',
            title: 'Kraków → Malaga',
            price: Money::fromDecimal($price, 'PLN'),
            url: 'https://example.test',
            origin: Airport::fromIataCode('KRK'),
            destination: Airport::fromIataCode('AGP'),
            departsAt: CarbonImmutable::parse('2026-10-30 21:00'),
        );
    }
}
