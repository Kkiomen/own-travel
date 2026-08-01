<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Deal\Deal;
use App\Domain\Deal\DealType;
use App\Domain\Deal\Port\DealRepository;
use App\Domain\Deal\ValueObject\Airport;
use App\Domain\Deal\ValueObject\Money;
use App\Domain\Deal\ValueObject\TravelWindow;
use App\Domain\Deal\ValueObject\TripDetails;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard asked to show only what fits a booked holiday.
 */
final class HolidayDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-08-01 12:00'));
    }

    public function test_it_narrows_the_board_to_what_fits_the_leave(): void
    {
        $this->repository()->store($this->roundTrip('2026-09-13 18:30', '2026-09-18 20:00', 'BCN'));
        $this->repository()->store($this->roundTrip('2026-10-13 18:30', '2026-10-18 20:00', 'AGP'));

        $props = $this->dashboard(['from' => '2026-09-12', 'to' => '2026-09-20']);

        $this->assertCount(1, $props['deals']);
        $this->assertSame('BCN', $props['deals'][0]['destination']['code']);
        $this->assertSame('2026-09-12', $props['from']);
        $this->assertSame('2026-09-20', $props['to']);
    }

    public function test_undated_trips_come_back_under_their_own_heading(): void
    {
        $this->repository()->store($this->trip('Minorka', []));
        $this->repository()->store($this->trip('Kreta', [
            new TravelWindow(
                CarbonImmutable::parse('2026-09-12'),
                CarbonImmutable::parse('2026-09-19'),
                '12-19 września',
            ),
        ]));

        $props = $this->dashboard(['from' => '2026-09-12', 'to' => '2026-09-20']);

        $this->assertSame(['Kreta'], array_column($props['deals'], 'title'));
        $this->assertSame(['Minorka'], array_column($props['undated_trips'], 'title'));
    }

    /**
     * Without a holiday there is nothing to set them apart, and every trip
     * would show up twice.
     */
    public function test_undated_trips_are_not_split_out_until_a_holiday_is_asked_for(): void
    {
        $this->repository()->store($this->trip('Minorka', []));

        $props = $this->dashboard();

        $this->assertSame([], $props['undated_trips']);
        $this->assertSame(['Minorka'], array_column($props['deals'], 'title'));
    }

    /**
     * The dashboard is a set of links, and a hand-edited one should show
     * everything rather than fail.
     */
    public function test_a_half_given_or_nonsensical_range_filters_nothing(): void
    {
        $this->repository()->store($this->roundTrip('2026-10-13 18:30', '2026-10-18 20:00', 'AGP'));

        foreach ([
            ['from' => '2026-09-12'],
            ['to' => '2026-09-20'],
            ['from' => 'wczoraj', 'to' => 'jutro'],
            ['from' => '2026-09-20', 'to' => '2026-09-12'],
        ] as $query) {
            $props = $this->dashboard($query);

            $this->assertCount(1, $props['deals']);
            $this->assertNull($props['from']);
            $this->assertNull($props['to']);
        }
    }

    public function test_the_holiday_combines_with_the_other_filters(): void
    {
        $this->repository()->store($this->roundTrip('2026-09-13 18:30', '2026-09-18 20:00', 'BCN'));
        $this->repository()->store($this->roundTrip('2026-09-14 18:30', '2026-09-17 20:00', 'AGP'));

        $props = $this->dashboard([
            'from' => '2026-09-12',
            'to' => '2026-09-20',
            'destination' => 'AGP',
        ]);

        $this->assertCount(1, $props['deals']);
        $this->assertSame('AGP', $props['deals'][0]['destination']['code']);
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function dashboard(array $query = []): array
    {
        return $this->inertiaProps($this->get(route('dashboard', $query))->assertOk());
    }

    private function repository(): DealRepository
    {
        return $this->app->make(DealRepository::class);
    }

    private function roundTrip(string $departsAt, string $returnsAt, string $destination): Deal
    {
        return Deal::roundTrip(
            source: 'ryanair-return',
            title: 'WRO ⇄ '.$destination,
            totalPrice: Money::fromDecimal(320, 'PLN'),
            url: 'https://example.test/'.$destination,
            origin: Airport::fromIataCode('WRO', 'Wrocław', 'Polska'),
            destination: Airport::fromIataCode($destination, 'Barcelona', 'Hiszpania'),
            departsAt: CarbonImmutable::parse($departsAt),
            returnsAt: CarbonImmutable::parse($returnsAt),
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
