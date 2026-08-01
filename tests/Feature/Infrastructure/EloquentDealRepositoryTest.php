<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Domain\Deal\Deal;
use App\Domain\Deal\DealSort;
use App\Domain\Deal\DealType;
use App\Domain\Deal\ScoreBasis;
use App\Domain\Deal\Service\Steal;
use App\Domain\Deal\ValueObject\Airport;
use App\Domain\Deal\ValueObject\DealListing;
use App\Domain\Deal\ValueObject\DealScore;
use App\Domain\Deal\ValueObject\Money;
use App\Infrastructure\Persistence\EloquentDealRepository;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EloquentDealRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private EloquentDealRepository $repository;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new EloquentDealRepository(
            new Steal(0.4, Money::fromDecimal(400, 'PLN')),
        );
        $this->now = CarbonImmutable::parse('2026-08-01 12:00');
        $this->travelTo($this->now);
    }

    public function test_it_stores_a_flight_and_reads_it_back_whole(): void
    {
        $this->assertTrue($this->repository->store($this->flight(99)));

        $stored = $this->list();

        $this->assertCount(1, $stored);
        $this->assertSame('ryanair', $stored[0]->source);
        $this->assertSame(DealType::Flight, $stored[0]->type);
        $this->assertSame(9900, $stored[0]->price->minorUnits);
        $this->assertSame('KRK → AGP', $stored[0]->routeLabel());
        $this->assertSame('Malaga', $stored[0]->destination?->name);
        $this->assertSame('Hiszpania', $stored[0]->destination?->countryName);
        $this->assertSame('2026-10-30', $stored[0]->departsAt?->toDateString());
        $this->assertSame(80, $stored[0]->score?->value);
    }

    public function test_it_stores_a_feed_item_with_its_publication_date_not_a_departure(): void
    {
        $this->repository->store(Deal::fromFeed(
            'fly4free',
            DealType::Trip,
            'Rodos',
            Money::fromDecimal(2518, 'PLN'),
            'https://example.test',
            publishedAt: CarbonImmutable::parse('2026-07-20 08:00'),
        ));

        $stored = $this->list()[0];

        $this->assertSame(DealType::Trip, $stored->type);
        $this->assertNull($stored->departsAt);
        $this->assertSame('2026-07-20', $stored->publishedAt?->toDateString());
    }

    public function test_it_refuses_to_store_the_same_deal_twice(): void
    {
        $this->assertTrue($this->repository->store($this->flight(99)));
        $this->assertFalse($this->repository->store($this->flight(99)));

        $this->assertDatabaseCount('deals', 1);
    }

    public function test_it_never_lists_a_flight_that_has_already_departed(): void
    {
        $this->repository->store($this->flight(99, departsAt: '2026-07-30 06:00'));
        $this->repository->store($this->flight(120, departsAt: '2026-09-30 06:00'));

        $listed = $this->list();

        $this->assertCount(1, $listed);
        $this->assertSame(12000, $listed[0]->price->minorUnits);
    }

    public function test_a_blog_offer_published_in_the_past_is_still_listed(): void
    {
        $this->repository->store(Deal::fromFeed(
            'fly4free',
            DealType::Trip,
            'Rzym',
            Money::fromDecimal(168, 'PLN'),
            'https://example.test',
            publishedAt: CarbonImmutable::parse('2026-07-01 08:00'),
        ));

        $this->assertCount(1, $this->list());
    }

    public function test_it_ranks_by_score_then_by_price(): void
    {
        $this->repository->store($this->flight(99, score: 60));
        $this->repository->store($this->flight(250, score: 90));
        $this->repository->store($this->flight(120, score: 90));

        $listed = $this->list(DealSort::Score);

        $this->assertSame([12000, 25000, 9900], array_map(
            static fn (Deal $deal): int => $deal->price->minorUnits,
            $listed,
        ));
    }

    public function test_it_ranks_by_price(): void
    {
        $this->repository->store($this->flight(250));
        $this->repository->store($this->flight(99));

        $listed = $this->list(DealSort::Price);

        $this->assertSame(9900, $listed[0]->price->minorUnits);
    }

    public function test_it_ranks_by_when_it_was_found(): void
    {
        $this->travelTo($this->now->subHours(2));
        $this->repository->store($this->flight(99));

        $this->travelTo($this->now);
        $this->repository->store($this->flight(250));

        $listed = $this->list(DealSort::Newest);

        $this->assertSame(25000, $listed[0]->price->minorUnits);
    }

    public function test_it_lists_only_weekend_getaways_when_asked(): void
    {
        $this->repository->store($this->roundTrip(199, weekend: true, url: 'https://example.test/weekend'));
        $this->repository->store($this->roundTrip(150, weekend: false, url: 'https://example.test/midweek'));

        $listed = $this->repository->list(new DealListing(
            10,
            DealSort::Price,
            $this->now,
            weekendsOnly: true,
        ));

        $this->assertCount(1, $listed);
        $this->assertSame(19900, $listed[0]->price->minorUnits);
        $this->assertTrue($listed[0]->weekendGetaway);
    }

    public function test_it_filters_by_where_a_deal_leaves_from_and_lands(): void
    {
        $this->repository->store($this->flight(99, origin: 'WRO', destination: 'BGY'));
        $this->repository->store($this->flight(89, origin: 'KRK', destination: 'BGY'));
        $this->repository->store($this->flight(79, origin: 'WRO', destination: 'TRF'));

        $fromWroclaw = $this->repository->list(new DealListing(
            10,
            DealSort::Price,
            $this->now,
            origin: Airport::fromIataCode('WRO'),
        ));

        $this->assertCount(2, $fromWroclaw);

        $toBergamo = $this->repository->list(new DealListing(
            10,
            DealSort::Price,
            $this->now,
            origin: Airport::fromIataCode('WRO'),
            destination: Airport::fromIataCode('BGY'),
        ));

        $this->assertCount(1, $toBergamo);
        $this->assertSame(9900, $toBergamo[0]->price->minorUnits);
    }

    public function test_the_home_airport_is_listed_first_whatever_the_ordering(): void
    {
        $this->repository->store($this->flight(59, origin: 'KRK', destination: 'BGY'));
        $this->repository->store($this->flight(99, origin: 'WRO', destination: 'TRF'));
        $this->repository->store($this->flight(69, origin: 'GDN', destination: 'CPH'));

        $listed = $this->repository->list(new DealListing(
            10,
            DealSort::Price,
            $this->now,
            preferredOrigin: Airport::fromIataCode('WRO'),
        ));

        // Dearest of the three, but it leaves from home.
        $this->assertSame('WRO', $listed[0]->origin?->iataCode);
        $this->assertSame(5900, $listed[1]->price->minorUnits);
    }

    public function test_it_reports_which_airports_have_deals(): void
    {
        $this->repository->store($this->flight(99, origin: 'WRO', destination: 'BGY'));
        $this->repository->store($this->flight(89, origin: 'KRK', destination: 'BGY'));
        $this->repository->store($this->flight(79, origin: 'WRO', destination: 'TRF', departsAt: '2026-07-01 06:00'));

        $airports = $this->repository->availableAirports($this->now);

        $origins = array_map(static fn (Airport $a): string => $a->iataCode, $airports['origins']);
        $destinations = array_map(static fn (Airport $a): string => $a->iataCode, $airports['destinations']);

        sort($origins);
        sort($destinations);

        // TRF only appears on a flight that has already departed.
        $this->assertSame(['KRK', 'WRO'], $origins);
        $this->assertSame(['BGY'], $destinations);
    }

    public function test_it_honours_the_limit(): void
    {
        $this->repository->store($this->flight(99));
        $this->repository->store($this->flight(79));

        $this->assertCount(1, $this->list(limit: 1));
    }

    public function test_it_purges_departed_flights(): void
    {
        $this->repository->store($this->flight(99, departsAt: '2026-07-30 06:00'));
        $this->repository->store($this->flight(120, departsAt: '2026-09-30 06:00'));

        $removed = $this->repository->purgeExpired($this->now, $this->now->subDays(45));

        $this->assertSame(1, $removed);
        $this->assertDatabaseCount('deals', 1);
    }

    public function test_it_purges_anything_found_long_enough_ago(): void
    {
        $this->travelTo($this->now->subDays(60));
        $this->repository->store(Deal::fromFeed('fly4free', DealType::Trip, 'Stara okazja', Money::fromDecimal(500, 'PLN'), 'https://example.test/old'));

        $this->travelTo($this->now);
        $this->repository->store(Deal::fromFeed('fly4free', DealType::Trip, 'Świeża okazja', Money::fromDecimal(500, 'PLN'), 'https://example.test/new'));

        $removed = $this->repository->purgeExpired($this->now, $this->now->subDays(45));

        $this->assertSame(1, $removed);
        $this->assertDatabaseHas('deals', ['title' => 'Świeża okazja']);
    }

    /**
     * @return list<Deal>
     */
    private function list(DealSort $sort = DealSort::Newest, int $limit = 10): array
    {
        return $this->repository->list(new DealListing($limit, $sort, $this->now));
    }

    private function flight(
        float $price,
        string $departsAt = '2026-10-30 21:00',
        int $score = 80,
        string $origin = 'KRK',
        string $destination = 'AGP',
    ): Deal {
        return Deal::flight(
            source: 'ryanair',
            title: $origin.' → '.$destination,
            price: Money::fromDecimal($price, 'PLN'),
            url: 'https://example.test/'.$origin.$destination.$price,
            origin: Airport::fromIataCode($origin, 'Kraków', 'Polska'),
            destination: Airport::fromIataCode($destination, 'Malaga', 'Hiszpania'),
            departsAt: CarbonImmutable::parse($departsAt),
        )->scoredWith(new DealScore($score, Money::fromDecimal($price, 'PLN'), ScoreBasis::TotalPrice));
    }

    private function roundTrip(float $totalPrice, bool $weekend, string $url): Deal
    {
        return Deal::roundTrip(
            source: 'ryanair-return',
            title: 'Wrocław ⇄ Mediolan',
            totalPrice: Money::fromDecimal($totalPrice, 'PLN'),
            url: $url,
            origin: Airport::fromIataCode('WRO'),
            destination: Airport::fromIataCode('BGY'),
            departsAt: CarbonImmutable::parse('2026-09-11 18:30'),
            returnsAt: CarbonImmutable::parse('2026-09-13 20:00'),
        )->markedAsWeekendGetaway($weekend);
    }
}
