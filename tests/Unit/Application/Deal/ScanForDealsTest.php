<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Deal;

use App\Application\Deal\ScanForDeals;
use App\Application\Deal\ScanReport;
use App\Domain\Deal\BoardType;
use App\Domain\Deal\Deal;
use App\Domain\Deal\DealType;
use App\Domain\Deal\Service\DealEvaluator;
use App\Domain\Deal\Service\DealScorer;
use App\Domain\Deal\Service\WeekendGetaway;
use App\Domain\Deal\ValueObject\Airport;
use App\Domain\Deal\ValueObject\Money;
use App\Domain\Deal\ValueObject\PriceBand;
use App\Domain\Deal\ValueObject\SearchCriteria;
use App\Domain\Deal\ValueObject\StayWindow;
use App\Domain\Deal\ValueObject\TripDetails;
use App\Infrastructure\DealSource\InMemoryDealSource;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Doubles\InMemoryDealRepository;
use Tests\Doubles\RecordingDealNotifier;

final class ScanForDealsTest extends TestCase
{
    private InMemoryDealRepository $repository;

    private RecordingDealNotifier $notifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new InMemoryDealRepository;
        $this->notifier = new RecordingDealNotifier;
    }

    public function test_it_keeps_and_announces_a_cheap_flight(): void
    {
        $report = $this->scan([new InMemoryDealSource('ryanair', [$this->flight(99)])]);

        $this->assertSame(1, $report->found);
        $this->assertSame(1, $report->kept);
        $this->assertSame(1, $report->alerted);
        $this->assertCount(1, $this->notifier->notified);
    }

    public function test_it_ignores_a_flight_over_the_threshold(): void
    {
        $report = $this->scan([new InMemoryDealSource('ryanair', [$this->flight(400)])]);

        $this->assertSame(1, $report->found);
        $this->assertSame(0, $report->kept);
        $this->assertSame([], $this->notifier->notified);
    }

    public function test_it_announces_a_known_deal_only_once(): void
    {
        $sources = [new InMemoryDealSource('ryanair', [$this->flight(99)])];

        $this->scan($sources);
        $secondReport = $this->scan($sources);

        $this->assertSame(0, $secondReport->kept);
        $this->assertCount(1, $this->notifier->notified);
        $this->assertSame(1, $this->repository->count());
    }

    public function test_a_price_drop_on_the_same_route_is_announced_again(): void
    {
        $this->scan([new InMemoryDealSource('ryanair', [$this->flight(99)])]);
        $report = $this->scan([new InMemoryDealSource('ryanair', [$this->flight(79)])]);

        $this->assertSame(1, $report->kept);
        $this->assertCount(2, $this->notifier->notified);
    }

    public function test_one_broken_source_does_not_stop_the_others(): void
    {
        $report = $this->scan([
            InMemoryDealSource::failing('wizzair'),
            new InMemoryDealSource('ryanair', [$this->flight(99)]),
        ]);

        $this->assertSame(['wizzair'], $report->failedSources);
        $this->assertSame(1, $report->kept);
        $this->assertTrue($report->hasFailures());
    }

    public function test_it_rates_a_trip_while_scanning(): void
    {
        $this->scan([new InMemoryDealSource('fly4free', [$this->trip(2000, days: 10)])]);

        $stored = $this->repository->all()[0];

        $this->assertNotNull($stored->score);
        $this->assertSame(20000, $stored->score->pricePerDay()?->minorUnits);
    }

    public function test_a_cheap_but_short_trip_is_kept_and_not_announced(): void
    {
        // 2400 PLN for three days is 800 a day - within budget, poorly rated.
        $report = $this->scan([new InMemoryDealSource('fly4free', [$this->trip(2400, days: 3)])]);

        $this->assertSame(1, $report->kept);
        $this->assertSame(0, $report->alerted);
        $this->assertSame([], $this->notifier->notified);
    }

    public function test_the_same_price_over_more_days_is_announced(): void
    {
        $report = $this->scan([new InMemoryDealSource('fly4free', [$this->trip(2400, days: 14)])]);

        $this->assertSame(1, $report->kept);
        $this->assertSame(1, $report->alerted);
    }

    public function test_a_trip_of_unknown_length_is_judged_on_its_total_price(): void
    {
        $cheapWeekend = $this->scan([new InMemoryDealSource('fly4free', [$this->trip(390, days: null)])]);

        $this->assertSame(1, $cheapWeekend->kept);
        $this->assertSame(1, $cheapWeekend->alerted);

        $expensive = $this->scan([new InMemoryDealSource('fly4free', [$this->trip(2400, days: null)])]);

        $this->assertSame(1, $expensive->kept);
        $this->assertSame(0, $expensive->alerted);
    }

    /**
     * @param  list<InMemoryDealSource>  $sources
     */
    private function scan(array $sources): ScanReport
    {
        $scan = new ScanForDeals(
            $sources,
            new DealEvaluator(
                Money::fromDecimal(150, 'PLN'),
                Money::fromDecimal(600, 'PLN'),
                Money::fromDecimal(2500, 'PLN'),
                70,
            ),
            new DealScorer(
                new PriceBand(Money::fromDecimal(100, 'PLN'), Money::fromDecimal(350, 'PLN')),
                new PriceBand(Money::fromDecimal(250, 'PLN'), Money::fromDecimal(700, 'PLN')),
                new PriceBand(Money::fromDecimal(150, 'PLN'), Money::fromDecimal(600, 'PLN')),
                new PriceBand(Money::fromDecimal(400, 'PLN'), Money::fromDecimal(2500, 'PLN')),
            ),
            new WeekendGetaway,
            $this->repository,
            $this->notifier,
            new NullLogger,
        );

        return $scan->execute($this->criteria());
    }

    private function criteria(): SearchCriteria
    {
        $today = CarbonImmutable::parse('2026-08-01');

        return new SearchCriteria(
            [Airport::fromIataCode('KRK')],
            $today,
            $today->addDays(90),
            Money::fromDecimal(150, 'PLN'),
            Money::fromDecimal(600, 'PLN'),
            Money::fromDecimal(2500, 'PLN'),
            new StayWindow(2, 10),
        );
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

    private function trip(float $price, ?int $days): Deal
    {
        return Deal::fromFeed(
            source: 'fly4free',
            type: DealType::Trip,
            title: 'Wyjazd',
            price: Money::fromDecimal($price, 'PLN'),
            url: 'https://example.test/'.$price.'-'.($days ?? 'x'),
            trip: new TripDetails($days, BoardType::AllInclusive, 4),
        );
    }
}
