<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Deal\BoardType;
use App\Domain\Deal\Deal;
use App\Domain\Deal\DealType;
use App\Domain\Deal\Port\DealRepository;
use App\Domain\Deal\ScoreBasis;
use App\Domain\Deal\ValueObject\Airport;
use App\Domain\Deal\ValueObject\DealScore;
use App\Domain\Deal\ValueObject\Money;
use App\Domain\Deal\ValueObject\TripDetails;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-08-01 12:00'));

        // Pinned so the assertions describe the page, not whatever .env holds.
        config()->set('deals.max_flight_price', 300);
        config()->set('deals.max_trip_price', 2500);
        config()->set('deals.scoring.minimum_score', 60);
    }

    public function test_it_renders_without_any_deals(): void
    {
        $page = $this->inertiaPage($this->get(route('dashboard'))->assertOk());

        $this->assertSame('Dashboard', $page['component']);
        $this->assertSame([], $page['props']['deals']);
        $this->assertSame(300, $page['props']['thresholds']['flight']);
        $this->assertSame(2500, $page['props']['thresholds']['trip']);
    }

    public function test_it_presents_a_flight_ready_for_the_page(): void
    {
        $this->repository()->store($this->flight(99, score: 100));

        $deals = $this->deals();

        $this->assertCount(1, $deals);
        $this->assertSame('ryanair', $deals[0]['source']);
        $this->assertSame('flight', $deals[0]['type']);
        $this->assertSame(99, $deals[0]['price']);
        $this->assertSame('PLN', $deals[0]['currency']);
        $this->assertSame('KRK', $deals[0]['origin']['code']);
        $this->assertSame('AGP', $deals[0]['destination']['code']);
        $this->assertSame('Hiszpania', $deals[0]['destination']['country']);
        $this->assertSame(100, $deals[0]['score']);
        $this->assertStringStartsWith('2026-10-30', (string) $deals[0]['departs_at']);
    }

    public function test_it_presents_a_feed_trip_with_what_the_article_gave_up(): void
    {
        $this->repository()->store(Deal::fromFeed(
            'fly4free',
            DealType::Trip,
            'Tydzień na Rodos za 2518 PLN',
            Money::fromDecimal(2518, 'PLN'),
            'https://example.test/trip',
            publishedAt: CarbonImmutable::parse('2026-07-30 09:00'),
            trip: new TripDetails(7, BoardType::AllInclusive, 4),
            score: new DealScore(58, Money::fromDecimal(359.71, 'PLN'), ScoreBasis::PricePerDay),
        ));

        $deal = $this->deals()[0];

        $this->assertSame('trip', $deal['type']);
        $this->assertNull($deal['origin']);
        $this->assertNull($deal['destination']);
        $this->assertSame(7, $deal['days']);
        $this->assertSame('all_inclusive', $deal['board']);
        $this->assertSame(4, $deal['hotel_stars']);
        $this->assertSame(359.71, $deal['price_per_day']);
        $this->assertStringStartsWith('2026-07-30', (string) $deal['published_at']);
    }

    public function test_it_filters_by_kind_in_the_query_not_on_the_page(): void
    {
        config()->set('deals.dashboard_limit', 1);

        // Cheaper, so it wins any ordering and would be the only row sent.
        $this->repository()->store($this->flight(99, score: 100));
        $this->repository()->store($this->roundTrip(250, score: 100));

        $page = $this->inertiaPage(
            $this->get(route('dashboard', ['type' => 'round_trip']))->assertOk(),
        );

        $this->assertSame('round_trip', $page['props']['type']);
        $this->assertCount(1, $page['props']['deals']);
        $this->assertSame('round_trip', $page['props']['deals'][0]['type']);
    }

    public function test_it_presents_both_legs_of_a_round_trip(): void
    {
        $this->repository()->store($this->roundTrip(250, score: 100));

        $deal = $this->deals()[0];

        $this->assertSame('round_trip', $deal['type']);
        $this->assertStringStartsWith('2026-09-10', (string) $deal['departs_at']);
        $this->assertStringStartsWith('2026-09-14', (string) $deal['returns_at']);
        $this->assertSame(4, $deal['days']);
        $this->assertSame(250, $deal['price']);
    }

    public function test_the_tiles_count_everything_not_just_the_page(): void
    {
        config()->set('deals.dashboard_limit', 1);

        $this->repository()->store($this->flight(99));
        $this->repository()->store($this->flight(120));
        $this->repository()->store($this->roundTrip(250));

        $totals = $this->inertiaProps($this->get(route('dashboard'))->assertOk())['totals'];

        $this->assertSame(2, $totals['flight']['count']);
        $this->assertSame(99, $totals['flight']['cheapest']);
        $this->assertSame(1, $totals['round_trip']['count']);
        $this->assertSame(250, $totals['round_trip']['cheapest']);
        $this->assertSame(0, $totals['trip']['count']);
        $this->assertNull($totals['trip']['cheapest']);
    }

    public function test_it_ranks_by_score_by_default(): void
    {
        $this->repository()->store($this->flight(250, score: 40));
        $this->repository()->store($this->flight(99, score: 100));

        $page = $this->inertiaPage($this->get(route('dashboard'))->assertOk());

        $this->assertSame('score', $page['props']['sort']);
        $this->assertSame(100, $page['props']['deals'][0]['score']);
    }

    public function test_it_ranks_by_price_when_asked(): void
    {
        $this->repository()->store($this->flight(250, score: 100));
        $this->repository()->store($this->flight(99, score: 40));

        $page = $this->inertiaPage($this->get(route('dashboard', ['sort' => 'price']))->assertOk());

        $this->assertSame('price', $page['props']['sort']);
        $this->assertSame(99, $page['props']['deals'][0]['price']);
    }

    public function test_an_unknown_sort_falls_back_to_the_best_deals(): void
    {
        $page = $this->inertiaPage($this->get(route('dashboard', ['sort' => 'nonsense']))->assertOk());

        $this->assertSame('score', $page['props']['sort']);
    }

    public function test_it_hides_flights_that_have_already_departed(): void
    {
        $this->repository()->store($this->flight(99, departsAt: '2026-07-20 06:00'));

        $this->assertSame([], $this->deals());
    }

    public function test_it_respects_the_configured_limit(): void
    {
        config()->set('deals.dashboard_limit', 1);

        $this->repository()->store($this->flight(99));
        $this->repository()->store($this->flight(79));

        $this->assertCount(1, $this->deals());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function deals(): array
    {
        return $this->inertiaProps($this->get(route('dashboard'))->assertOk())['deals'];
    }

    private function repository(): DealRepository
    {
        return $this->app->make(DealRepository::class);
    }

    private function roundTrip(float $totalPrice, int $score = 90): Deal
    {
        return Deal::roundTrip(
            source: 'ryanair-return',
            title: 'Kraków ⇄ Malaga',
            totalPrice: Money::fromDecimal($totalPrice, 'PLN'),
            url: 'https://example.test/return',
            origin: Airport::fromIataCode('KRK', 'Kraków', 'Polska'),
            destination: Airport::fromIataCode('AGP', 'Malaga', 'Hiszpania'),
            departsAt: CarbonImmutable::parse('2026-09-10 06:00'),
            returnsAt: CarbonImmutable::parse('2026-09-14 20:00'),
        )->scoredWith(new DealScore($score, Money::fromDecimal($totalPrice, 'PLN'), ScoreBasis::TotalPrice));
    }

    private function flight(float $price, string $departsAt = '2026-10-30 21:00', int $score = 80): Deal
    {
        return Deal::flight(
            source: 'ryanair',
            title: 'Kraków → Malaga',
            price: Money::fromDecimal($price, 'PLN'),
            url: 'https://example.test/'.$price,
            origin: Airport::fromIataCode('KRK', 'Kraków', 'Polska'),
            destination: Airport::fromIataCode('AGP', 'Malaga', 'Hiszpania'),
            departsAt: CarbonImmutable::parse($departsAt),
        )->scoredWith(new DealScore($score, Money::fromDecimal($price, 'PLN'), ScoreBasis::TotalPrice));
    }
}
