<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Deal;

use App\Domain\Deal\Deal;
use App\Domain\Deal\DealType;
use App\Domain\Deal\ScoreBasis;
use App\Domain\Deal\Service\DealEvaluator;
use App\Domain\Deal\ValueObject\Airport;
use App\Domain\Deal\ValueObject\DealScore;
use App\Domain\Deal\ValueObject\Money;
use PHPUnit\Framework\TestCase;

final class DealEvaluatorTest extends TestCase
{
    private DealEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = new DealEvaluator(
            Money::fromDecimal(150, 'PLN'),
            Money::fromDecimal(600, 'PLN'),
            Money::fromDecimal(2500, 'PLN'),
            minimumTripScore: 70,
        );
    }

    public function test_a_flight_at_the_threshold_is_kept(): void
    {
        $this->assertTrue($this->evaluator->isWorthKeeping($this->flight(150)));
    }

    public function test_a_flight_over_the_threshold_is_not(): void
    {
        $this->assertFalse($this->evaluator->isWorthKeeping($this->flight(150.01)));
    }

    public function test_trips_are_judged_against_their_own_price_threshold(): void
    {
        $this->assertTrue($this->evaluator->isWorthKeeping($this->trip(2400, score: null)));
        $this->assertFalse($this->evaluator->isWorthKeeping($this->trip(9000, score: null)));
    }

    public function test_a_cheap_but_mediocre_trip_is_kept_without_earning_an_alert(): void
    {
        $trip = $this->trip(2400, score: 45);

        $this->assertTrue($this->evaluator->isWorthKeeping($trip));
        $this->assertFalse($this->evaluator->isWorthAlerting($trip));
    }

    public function test_a_cheap_and_well_rated_trip_earns_an_alert(): void
    {
        $trip = $this->trip(2400, score: 70);

        $this->assertTrue($this->evaluator->isWorthAlerting($trip));
    }

    public function test_a_well_rated_trip_over_budget_earns_nothing(): void
    {
        $this->assertFalse($this->evaluator->isWorthAlerting($this->trip(9000, score: 95)));
    }

    public function test_an_unrated_offer_is_judged_on_price_alone(): void
    {
        $this->assertTrue($this->evaluator->isWorthAlerting($this->flight(99)));
        $this->assertTrue($this->evaluator->isWorthAlerting($this->trip(2400, score: null)));
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
        );
    }

    private function trip(float $price, ?int $score): Deal
    {
        $money = Money::fromDecimal($price, 'PLN');

        return Deal::fromFeed(
            source: 'fly4free',
            type: DealType::Trip,
            title: 'Wyjazd',
            price: $money,
            url: 'https://example.test',
            score: $score === null ? null : new DealScore($score, Money::fromDecimal(300, 'PLN'), ScoreBasis::PricePerDay),
        );
    }
}
