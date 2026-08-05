<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Deal\Deal;
use App\Domain\Deal\DealType;
use App\Domain\Deal\Port\DealRepository;
use App\Domain\Deal\ScoreBasis;
use App\Domain\Deal\ValueObject\Airport;
use App\Domain\Deal\ValueObject\DealScore;
use App\Domain\Deal\ValueObject\Money;
use App\Domain\Deal\ValueObject\TravelWindow;
use App\Domain\Deal\ValueObject\TripDetails;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DealApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-08-01 12:00'));

        // Pinned so the assertions describe the API, not whatever .env holds.
        config()->set('deals.max_flight_price', 300);
        config()->set('deals.max_trip_price', 2500);
        config()->set('deals.scoring.minimum_score', 60);
        config()->set('deals.dashboard_limit', 60);
    }

    public function test_it_answers_with_no_deals_at_all(): void
    {
        $body = $this->getJson('/api/v1/deals')->assertOk()->json();

        $this->assertSame([], $body['data']);
        $this->assertSame([], $body['undated_trips']);
        $this->assertSame(0, $body['meta']['count']);
        $this->assertSame('PLN', $body['meta']['currency']);
        $this->assertSame('score', $body['meta']['filters']['sort']);
    }

    public function test_a_deal_reads_the_same_over_the_api_as_on_the_dashboard(): void
    {
        $this->repository()->store($this->flight(99, score: 100));

        $overTheApi = $this->getJson('/api/v1/deals')->assertOk()->json('data');
        $onThePage = $this->inertiaProps($this->get(route('dashboard'))->assertOk())['deals'];

        // The point of the API is to be the dashboard's data, not a lookalike.
        $this->assertSame($onThePage, $overTheApi);
        $this->assertSame('KRK', $overTheApi[0]['origin']['code']);
        $this->assertSame(99, $overTheApi[0]['price']);
        $this->assertSame(100, $overTheApi[0]['score']);
    }

    public function test_it_filters_and_sorts_in_the_query(): void
    {
        $this->repository()->store($this->flight(99, score: 40));
        $this->repository()->store($this->roundTrip(250, score: 100));

        $body = $this->getJson('/api/v1/deals?type=round_trip&sort=price')->assertOk()->json();

        $this->assertCount(1, $body['data']);
        $this->assertSame('round_trip', $body['data'][0]['type']);
        $this->assertSame('round_trip', $body['meta']['filters']['type']);
        $this->assertSame('price', $body['meta']['filters']['sort']);
    }

    public function test_an_unusable_filter_is_no_filter_at_all(): void
    {
        $this->repository()->store($this->flight(99));

        $body = $this->getJson('/api/v1/deals?sort=nonsense&origin=nope&from=2026-09-12')->assertOk()->json();

        $this->assertCount(1, $body['data']);
        $this->assertSame('score', $body['meta']['filters']['sort']);
        $this->assertNull($body['meta']['filters']['origin']);
        $this->assertNull($body['meta']['filters']['from']);
    }

    public function test_it_caps_how_many_deals_one_request_may_ask_for(): void
    {
        $this->assertSame(200, $this->getJson('/api/v1/deals?limit=5000')->assertOk()->json('meta.limit'));
        $this->assertSame(5, $this->getJson('/api/v1/deals?limit=5')->assertOk()->json('meta.limit'));
        // Nonsense falls back to the configured page rather than failing.
        $this->assertSame(60, $this->getJson('/api/v1/deals?limit=0')->assertOk()->json('meta.limit'));
    }

    public function test_it_matches_a_journey_against_booked_leave(): void
    {
        $this->repository()->store($this->roundTrip(250, departsAt: '2026-09-13 18:30', returnsAt: '2026-09-18 20:00'));
        $this->repository()->store($this->roundTrip(240, departsAt: '2026-10-13 18:30', returnsAt: '2026-10-18 20:00'));

        $body = $this->getJson('/api/v1/deals?from=2026-09-12&to=2026-09-20')->assertOk()->json();

        $this->assertCount(1, $body['data']);
        $this->assertStringStartsWith('2026-09-13', (string) $body['data'][0]['departs_at']);
        $this->assertSame('2026-09-12', $body['meta']['filters']['from']);
    }

    public function test_undated_trips_come_back_apart_from_the_matches(): void
    {
        $this->repository()->store($this->trip('Minorka', []));
        $this->repository()->store($this->trip('Kreta', [
            new TravelWindow(
                CarbonImmutable::parse('2026-09-12'),
                CarbonImmutable::parse('2026-09-19'),
                '12-19 września',
            ),
        ]));

        $body = $this->getJson('/api/v1/deals?from=2026-09-12&to=2026-09-20')->assertOk()->json();

        $this->assertSame(['Kreta'], array_column($body['data'], 'title'));
        $this->assertSame(['Minorka'], array_column($body['undated_trips'], 'title'));
    }

    public function test_the_airports_offered_answer_to_the_filters_already_chosen(): void
    {
        $this->repository()->store($this->flight(99, origin: 'WRO', destination: 'BGY'));
        $this->repository()->store($this->flight(120, origin: 'KRK', destination: 'AGP'));

        $body = $this->getJson('/api/v1/deals/airports?origin=WRO')->assertOk()->json();

        $this->assertSame(['BGY'], array_column($body['destinations'], 'code'));
        $this->assertSame(['KRK', 'WRO'], array_column($body['origins'], 'code'));
    }

    public function test_the_summary_counts_everything_not_one_page(): void
    {
        config()->set('deals.dashboard_limit', 1);

        $this->repository()->store($this->flight(99));
        $this->repository()->store($this->flight(120));
        $this->repository()->store($this->roundTrip(250));

        $body = $this->getJson('/api/v1/deals/summary')->assertOk()->json();

        $this->assertSame(2, $body['totals']['flight']['count']);
        $this->assertSame(99, $body['totals']['flight']['cheapest']);
        $this->assertSame(1, $body['totals']['round_trip']['count']);
        $this->assertSame(0, $body['totals']['trip']['count']);
        $this->assertNull($body['totals']['trip']['cheapest']);
        $this->assertSame(300, $body['thresholds']['flight']);
        $this->assertSame(2500, $body['thresholds']['trip']);
        $this->assertSame('PLN', $body['currency']);
    }

    /**
     * The whole point of the combined endpoint: another app rebuilds the view
     * from it, so it has to be the view - not a selection of it.
     */
    public function test_the_dashboard_endpoint_sends_exactly_what_the_page_is_rendered_from(): void
    {
        $this->repository()->store($this->flight(99, score: 100));
        $this->repository()->store($this->roundTrip(250, score: 80));

        $overTheApi = $this->getJson('/api/v1/dashboard?type=round_trip')->assertOk()->json();
        $onThePage = $this->inertiaProps($this->get(route('dashboard', ['type' => 'round_trip']))->assertOk());

        // Inertia adds its own shared props (`name`, `errors`); everything the
        // board is made of has to match, key for key.
        $this->assertSame($overTheApi, array_intersect_key($onThePage, $overTheApi));
        $this->assertSame(['KRK'], array_column($overTheApi['airports']['origins'], 'code'));
        $this->assertSame(2, $overTheApi['totals']['flight']['count'] + $overTheApi['totals']['round_trip']['count']);
        $this->assertSame(300, $overTheApi['thresholds']['flight']);
    }

    public function test_the_dashboard_endpoint_takes_the_same_filters(): void
    {
        $this->repository()->store($this->flight(99, origin: 'WRO', destination: 'BGY'));
        $this->repository()->store($this->flight(120, origin: 'KRK', destination: 'AGP'));

        $body = $this->getJson('/api/v1/dashboard?origin=WRO&limit=5')->assertOk()->json();

        $this->assertSame('WRO', $body['origin']);
        $this->assertCount(1, $body['deals']);
        $this->assertSame(['BGY'], array_column($body['airports']['destinations'], 'code'));
    }

    public function test_a_single_deal_can_be_linked_to(): void
    {
        $flight = $this->flight(99, score: 100);
        $this->repository()->store($flight);

        $body = $this->getJson('/api/v1/deals/'.$flight->fingerprint())->assertOk()->json();

        $this->assertSame($flight->fingerprint(), $body['data']['id']);
        $this->assertSame(99, $body['data']['price']);
    }

    public function test_a_deal_that_is_gone_answers_404_rather_than_showing_it(): void
    {
        $departed = $this->flight(99, departsAt: '2026-07-20 06:00');
        $this->repository()->store($departed);

        $this->getJson('/api/v1/deals/'.$departed->fingerprint())->assertNotFound();
        $this->getJson('/api/v1/deals/never-existed')->assertNotFound();
    }

    public function test_the_meta_endpoint_names_every_value_a_filter_accepts(): void
    {
        config()->set('deals.stay.minimum_nights', 2);
        config()->set('deals.stay.maximum_nights', 10);
        config()->set('deals.preferred_origin', 'WRO');

        $body = $this->getJson('/api/v1/meta')->assertOk()->json();

        $this->assertSame(['newest', 'score', 'price'], $body['sorts']);
        $this->assertSame(['flight', 'round_trip', 'trip'], $body['types']);
        $this->assertNotContains('unknown', $body['boards']);
        $this->assertSame(2, $body['stay']['minimum_nights']);
        $this->assertSame(10, $body['stay']['maximum_nights']);
        $this->assertSame('WRO', $body['preferred_origin']);
        $this->assertSame(200, $body['limits']['maximum']);
        $this->assertSame(300, $body['thresholds']['flight']);
    }

    public function test_it_never_offers_a_flight_that_has_already_departed(): void
    {
        $this->repository()->store($this->flight(99, departsAt: '2026-07-20 06:00'));

        $this->assertSame([], $this->getJson('/api/v1/deals')->assertOk()->json('data'));
    }

    /**
     * A browser-side client is the whole point of a proxy app, so the API has
     * to answer a cross-origin request.
     */
    public function test_it_may_be_read_from_another_origin(): void
    {
        $this->getJson('/api/v1/deals', ['Origin' => 'https://proxy.test'])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*');
    }

    private function repository(): DealRepository
    {
        return $this->app->make(DealRepository::class);
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

    private function roundTrip(
        float $totalPrice,
        int $score = 90,
        string $departsAt = '2026-09-10 06:00',
        string $returnsAt = '2026-09-14 20:00',
    ): Deal {
        return Deal::roundTrip(
            source: 'ryanair-return',
            title: 'Kraków ⇄ Malaga',
            totalPrice: Money::fromDecimal($totalPrice, 'PLN'),
            url: 'https://example.test/return'.$totalPrice,
            origin: Airport::fromIataCode('KRK', 'Kraków', 'Polska'),
            destination: Airport::fromIataCode('AGP', 'Malaga', 'Hiszpania'),
            departsAt: CarbonImmutable::parse($departsAt),
            returnsAt: CarbonImmutable::parse($returnsAt),
        )->scoredWith(new DealScore($score, Money::fromDecimal($totalPrice, 'PLN'), ScoreBasis::TotalPrice));
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
}
