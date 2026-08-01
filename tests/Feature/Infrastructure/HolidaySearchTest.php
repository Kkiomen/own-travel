<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Domain\Deal\Deal;
use App\Domain\Deal\DealSort;
use App\Domain\Deal\DealType;
use App\Domain\Deal\Service\Steal;
use App\Domain\Deal\ValueObject\Airport;
use App\Domain\Deal\ValueObject\DealListing;
use App\Domain\Deal\ValueObject\HolidayWindow;
use App\Domain\Deal\ValueObject\Money;
use App\Domain\Deal\ValueObject\TravelWindow;
use App\Domain\Deal\ValueObject\TripDetails;
use App\Infrastructure\Persistence\EloquentDealRepository;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Searching the board against leave that is already booked.
 *
 * The filtering has to happen in the query: only a page of deals is ever sent,
 * so narrowing afterwards would leave the holiday tab empty while the database
 * is full of matches.
 */
final class HolidaySearchTest extends TestCase
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

    public function test_a_round_trip_inside_the_leave_is_on_offer(): void
    {
        $this->repository->store($this->roundTrip('2026-09-13 18:30', '2026-09-18 20:00'));

        $this->assertCount(1, $this->matching('2026-09-12', '2026-09-20'));
    }

    public function test_a_round_trip_leaving_before_the_leave_is_not(): void
    {
        $this->repository->store($this->roundTrip('2026-09-11 18:30', '2026-09-18 20:00'));

        $this->assertSame([], $this->matching('2026-09-12', '2026-09-20'));
    }

    /**
     * The way back is what makes an offer unbookable as leave, and it is the
     * half a price-sorted list will happily hide.
     */
    public function test_a_round_trip_returning_after_the_leave_is_not(): void
    {
        $this->repository->store($this->roundTrip('2026-09-13 18:30', '2026-09-24 20:00'));

        $this->assertSame([], $this->matching('2026-09-12', '2026-09-20'));
    }

    public function test_leave_bounds_count_as_whole_days(): void
    {
        // Out on the evening of the first day, back late on the last.
        $this->repository->store($this->roundTrip('2026-09-12 19:05', '2026-09-20 23:55'));

        $this->assertCount(1, $this->matching('2026-09-12', '2026-09-20'));
    }

    public function test_a_one_way_flight_is_judged_on_its_departure_alone(): void
    {
        $this->repository->store($this->flight('2026-09-20 22:00'));
        $this->repository->store($this->flight('2026-09-21 07:00'));

        $found = $this->matching('2026-09-12', '2026-09-20');

        $this->assertCount(1, $found);
        $this->assertSame('2026-09-20', $found[0]->departsAt?->toDateString());
    }

    public function test_a_trip_whose_article_named_a_term_inside_the_leave_is_on_offer(): void
    {
        $this->repository->store($this->trip('Kreta', [
            new TravelWindow(
                CarbonImmutable::parse('2026-09-12'),
                CarbonImmutable::parse('2026-09-19'),
                '12-19 września',
            ),
        ]));

        $found = $this->matching('2026-09-12', '2026-09-20');

        $this->assertCount(1, $found);
        $this->assertSame('Kreta', $found[0]->title);
    }

    public function test_a_trip_whose_only_term_falls_outside_the_leave_is_not(): void
    {
        $this->repository->store($this->trip('Kreta', [
            new TravelWindow(
                CarbonImmutable::parse('2026-09-12'),
                CarbonImmutable::parse('2026-09-26'),
                '12-26 września',
            ),
        ]));

        $this->assertSame([], $this->matching('2026-09-12', '2026-09-20'));
    }

    /**
     * An article naming several terms is naming alternatives - "4 lipca" or
     * "12-15 września" is two separate chances to go. Squashing them into one
     * span would invent a three-month trip and match leave in August that was
     * never on offer.
     */
    public function test_alternative_terms_are_matched_one_by_one(): void
    {
        $this->repository->store($this->trip('Turyn', [
            new TravelWindow(CarbonImmutable::parse('2026-07-04'), null, '4 lipca'),
            new TravelWindow(
                CarbonImmutable::parse('2026-09-12'),
                CarbonImmutable::parse('2026-09-15'),
                '12-15 września',
            ),
        ]));

        // The September term fits.
        $this->assertCount(1, $this->matching('2026-09-12', '2026-09-20'));

        // Leave that falls between the two terms matches neither.
        $this->assertSame([], $this->matching('2026-08-01', '2026-08-20'));
    }

    public function test_a_trip_the_article_never_dated_is_not_a_match(): void
    {
        $this->repository->store($this->trip('Minorka', []));

        $this->assertSame([], $this->matching('2026-09-12', '2026-09-20'));
    }

    /**
     * Only a minority of articles name their terms in the summary the parser
     * is allowed to read, so dropping the rest would hide most of the blog
     * offers. They are asked for separately instead, to be shown under their
     * own heading rather than passed off as matches.
     */
    public function test_undated_trips_can_be_asked_for_on_their_own(): void
    {
        $this->repository->store($this->trip('Minorka', []));
        $this->repository->store($this->trip('Kreta', [
            new TravelWindow(CarbonImmutable::parse('2026-09-12'), null, '12 września'),
        ]));
        $this->repository->store($this->flight('2026-09-14 08:00'));

        $undated = $this->repository->list(new DealListing(
            limit: 10,
            sort: DealSort::Newest,
            now: $this->now,
            undatedTripsOnly: true,
        ));

        $this->assertCount(1, $undated);
        $this->assertSame('Minorka', $undated[0]->title);
    }

    public function test_without_a_holiday_nothing_is_narrowed(): void
    {
        $this->repository->store($this->roundTrip('2026-09-13 18:30', '2026-09-18 20:00'));
        $this->repository->store($this->trip('Minorka', []));

        $listing = new DealListing(limit: 10, sort: DealSort::Newest, now: $this->now);

        $this->assertCount(2, $this->repository->list($listing));
    }

    /**
     * The airport lists run through the same filters as the deals, so the
     * dashboard can never offer a destination the holiday rules out.
     */
    public function test_the_airport_lists_answer_to_the_holiday_too(): void
    {
        $this->repository->store($this->roundTrip('2026-09-13 18:30', '2026-09-18 20:00', 'BCN'));
        $this->repository->store($this->roundTrip('2026-10-13 18:30', '2026-10-18 20:00', 'AGP'));

        $airports = $this->repository->availableAirports(new DealListing(
            limit: 10,
            sort: DealSort::Newest,
            now: $this->now,
            holiday: $this->holiday('2026-09-12', '2026-09-20'),
        ));

        $codes = array_map(static fn (Airport $a): string => $a->iataCode, $airports['destinations']);

        $this->assertSame(['BCN'], $codes);
    }

    public function test_the_terms_survive_a_round_trip_through_the_database(): void
    {
        $this->repository->store($this->trip('Kreta', [
            new TravelWindow(
                CarbonImmutable::parse('2026-09-12'),
                CarbonImmutable::parse('2026-09-19'),
                '12-19 września',
            ),
        ]));

        $stored = $this->matching('2026-09-12', '2026-09-20')[0];

        $this->assertCount(1, $stored->trip?->dates ?? []);
        $this->assertSame('12-19 września', ($stored->trip?->dates ?? [])[0]->label);
    }

    /**
     * @return list<Deal>
     */
    private function matching(string $from, string $to): array
    {
        return $this->repository->list(new DealListing(
            limit: 10,
            sort: DealSort::Newest,
            now: $this->now,
            holiday: $this->holiday($from, $to),
        ));
    }

    private function holiday(string $from, string $to): HolidayWindow
    {
        return new HolidayWindow(CarbonImmutable::parse($from), CarbonImmutable::parse($to));
    }

    private function roundTrip(string $departsAt, string $returnsAt, string $destination = 'BCN'): Deal
    {
        return Deal::roundTrip(
            source: 'ryanair-return',
            title: 'WRO ⇄ '.$destination,
            totalPrice: Money::fromDecimal(320, 'PLN'),
            url: 'https://example.test/'.$destination.$departsAt,
            origin: Airport::fromIataCode('WRO'),
            destination: Airport::fromIataCode($destination),
            departsAt: CarbonImmutable::parse($departsAt),
            returnsAt: CarbonImmutable::parse($returnsAt),
        );
    }

    private function flight(string $departsAt): Deal
    {
        return Deal::flight(
            source: 'ryanair',
            title: 'WRO → BCN',
            price: Money::fromDecimal(99, 'PLN'),
            url: 'https://example.test/one-way/'.$departsAt,
            origin: Airport::fromIataCode('WRO'),
            destination: Airport::fromIataCode('BCN'),
            departsAt: CarbonImmutable::parse($departsAt),
        );
    }

    /**
     * @param  list<TravelWindow>  $dates
     */
    private function trip(string $title, array $dates): Deal
    {
        return Deal::fromFeed(
            'fly4free',
            DealType::Trip,
            $title,
            Money::fromDecimal(1290, 'PLN'),
            'https://example.test/'.$title,
            publishedAt: CarbonImmutable::parse('2026-07-20 08:00'),
            trip: new TripDetails(days: 7, dates: $dates),
        );
    }
}
