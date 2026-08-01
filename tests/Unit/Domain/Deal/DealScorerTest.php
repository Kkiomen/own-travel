<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Deal;

use App\Domain\Deal\BoardType;
use App\Domain\Deal\Deal;
use App\Domain\Deal\DealType;
use App\Domain\Deal\ScoreBasis;
use App\Domain\Deal\Service\DealScorer;
use App\Domain\Deal\ValueObject\Airport;
use App\Domain\Deal\ValueObject\Money;
use App\Domain\Deal\ValueObject\PriceBand;
use App\Domain\Deal\ValueObject\TripDetails;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class DealScorerTest extends TestCase
{
    private DealScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scorer = new DealScorer(
            // Flights: 100 PLN or less is great, 350 or more is poor.
            new PriceBand(Money::fromDecimal(100, 'PLN'), Money::fromDecimal(350, 'PLN')),
            // Both legs: 250 PLN or less is great, 700 or more is poor.
            new PriceBand(Money::fromDecimal(250, 'PLN'), Money::fromDecimal(700, 'PLN')),
            // Trips: 150 PLN a day or less is great, 600 or more is poor.
            new PriceBand(Money::fromDecimal(150, 'PLN'), Money::fromDecimal(600, 'PLN')),
            // Trips of unknown length: 400 PLN in total is great, 2500 is poor.
            new PriceBand(Money::fromDecimal(400, 'PLN'), Money::fromDecimal(2500, 'PLN')),
        );
    }

    public function test_a_bargain_flight_scores_full_marks(): void
    {
        $score = $this->scorer->score($this->flight(89));

        $this->assertSame(100, $score->value);
        $this->assertSame(ScoreBasis::TotalPrice, $score->basis);
        $this->assertNull($score->pricePerDay());
    }

    public function test_an_expensive_flight_scores_nothing(): void
    {
        $this->assertSame(0, $this->scorer->score($this->flight(350))->value);
    }

    public function test_a_flight_in_between_scores_in_between(): void
    {
        // 225 PLN sits halfway between 100 and 350.
        $this->assertSame(50, $this->scorer->score($this->flight(225))->value);
    }

    public function test_a_cheap_pair_of_legs_scores_full_marks(): void
    {
        $score = $this->scorer->score($this->roundTrip(220));

        $this->assertSame(100, $score->value);
        $this->assertSame(ScoreBasis::TotalPrice, $score->basis);
    }

    public function test_a_round_trip_is_judged_on_both_legs_together(): void
    {
        // 475 PLN sits halfway between 250 and 700.
        $this->assertSame(50, $this->scorer->score($this->roundTrip(475))->value);
    }

    /**
     * The same 200 PLN is a bargain one way and merely fine as a whole trip -
     * which is the point of scoring them against different bands.
     */
    public function test_a_price_that_is_great_one_way_is_ordinary_for_a_pair(): void
    {
        $oneWay = $this->scorer->score($this->flight(200));
        $bothLegs = $this->scorer->score($this->roundTrip(200));

        $this->assertSame(60, $oneWay->value);
        $this->assertSame(100, $bothLegs->value);
    }

    public function test_a_weekend_getaway_scores_well(): void
    {
        // 380 PLN for two days is 190 a day.
        $score = $this->scorer->score($this->trip(380, days: 2));

        $this->assertSame(ScoreBasis::PricePerDay, $score->basis);
        $this->assertSame(19000, $score->ratedPrice->minorUnits);
        $this->assertGreaterThan(80, $score->value);
    }

    public function test_the_same_price_scores_differently_depending_on_length(): void
    {
        $long = $this->scorer->score($this->trip(2500, days: 10));
        $short = $this->scorer->score($this->trip(2500, days: 3));

        $this->assertGreaterThan($short->value, $long->value);
    }

    public function test_a_great_daily_rate_scores_full_marks(): void
    {
        $score = $this->scorer->score($this->trip(1000, days: 10));

        $this->assertSame(100, $score->value);
        $this->assertSame(10000, $score->pricePerDay()?->minorUnits);
    }

    public function test_a_poor_daily_rate_scores_nothing(): void
    {
        $this->assertSame(0, $this->scorer->score($this->trip(6000, days: 5))->value);
    }

    public function test_a_trip_of_unknown_length_is_judged_on_its_total(): void
    {
        $score = $this->scorer->score($this->trip(400, days: null));

        $this->assertSame(ScoreBasis::TotalPrice, $score->basis);
        $this->assertSame(100, $score->value);
    }

    public function test_an_expensive_trip_of_unknown_length_scores_nothing(): void
    {
        $this->assertSame(0, $this->scorer->score($this->trip(2500, days: null))->value);
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

    private function roundTrip(float $totalPrice): Deal
    {
        return Deal::roundTrip(
            source: 'ryanair-return',
            title: 'Kraków ⇄ Malaga',
            totalPrice: Money::fromDecimal($totalPrice, 'PLN'),
            url: 'https://example.test',
            origin: Airport::fromIataCode('KRK'),
            destination: Airport::fromIataCode('AGP'),
            departsAt: CarbonImmutable::parse('2026-08-10 06:00'),
            returnsAt: CarbonImmutable::parse('2026-08-14 20:00'),
        );
    }

    private function trip(float $price, ?int $days): Deal
    {
        return Deal::fromFeed(
            source: 'fly4free',
            type: DealType::Trip,
            title: 'Wyjazd',
            price: Money::fromDecimal($price, 'PLN'),
            url: 'https://example.test',
            trip: new TripDetails($days, BoardType::AllInclusive, 4),
        );
    }
}
