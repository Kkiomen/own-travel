<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Domain\Deal\DealType;
use App\Domain\Deal\Exception\DealSourceUnavailable;
use App\Domain\Deal\Service\RoundTripPairing;
use App\Domain\Deal\Service\WeekendGetaway;
use App\Domain\Deal\ValueObject\Airport;
use App\Domain\Deal\ValueObject\Money;
use App\Domain\Deal\ValueObject\SearchCriteria;
use App\Domain\Deal\ValueObject\StayWindow;
use App\Infrastructure\DealSource\WizzAirApiVersionResolver;
use App\Infrastructure\DealSource\WizzAirStationDirectory;
use App\Infrastructure\DealSource\WizzAirTimetableSource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Runs against a response captured from the live timetable endpoint.
 */
final class WizzAirTimetableSourceTest extends TestCase
{
    public function test_it_maps_the_daily_timetable_into_deals(): void
    {
        $this->fakeApi();

        $deals = $this->source()->findDeals($this->criteria());

        // One deal per actual flight, not per day: the timetable lists several
        // departure times for most days and the time is what decides whether
        // an offer is worth taking.
        $this->assertCount(93, $deals);

        $first = $deals[0];
        $this->assertSame('wizzair', $first->source);
        $this->assertSame('WAW → LTN', $first->routeLabel());
        $this->assertSame(22900, $first->price->minorUnits);
        $this->assertSame('2026-08-05 06:00', $first->departsAt?->format('Y-m-d H:i'));
        $this->assertStringContainsString('/booking/select-flight/WAW/LTN/2026-08-05', $first->url);
    }

    public function test_it_names_the_airports_the_timetable_only_codes(): void
    {
        $this->fakeApi();

        $first = $this->source()->findDeals($this->criteria())[0];

        // The timetable says "WAW" and "LTN" and nothing else, which put every
        // Wizz Air offer on the board as a bare code.
        $this->assertSame('Warszawa Chopin', $first->origin?->label());
        $this->assertSame('Londyn-Luton', $first->destination?->label());
        $this->assertSame('Wielka Brytania', $first->destination?->countryName);
    }

    public function test_the_booking_link_opens_the_search_rather_than_the_homepage(): void
    {
        $this->fakeApi();

        $deals = $this->source()->findDeals($this->criteria());

        // The hash-bang form belongs to the old site: it redirects and leaves
        // the search form empty, which is indistinguishable from a dead link.
        $this->assertStringNotContainsString('#', $deals[0]->url);
        $this->assertStringContainsString('/en-gb/booking/select-flight/', $deals[0]->url);
    }

    public function test_it_asks_only_about_configured_routes_from_watched_airports(): void
    {
        $this->fakeApi();

        $this->source(['WAW' => ['LTN'], 'GDN' => ['BCN']])->findDeals($this->criteria());

        Http::assertSent(function ($request): bool {
            if (! str_contains((string) $request->url(), '/Api/search/timetable')) {
                return true;
            }

            $flight = $request->data()['flightList'][0];

            return $flight['departureStation'] === 'WAW' && $flight['arrivalStation'] === 'LTN';
        });

        // GDN is configured but not watched, so only one route was queried.
        // Counted against the timetable alone: the version and the station list
        // are looked up as well, and neither is a route.
        $this->assertCount(1, Http::recorded(
            static fn ($request): bool => str_contains((string) $request->url(), '/Api/search/timetable'),
        ));
    }

    public function test_it_caps_the_window_the_endpoint_accepts(): void
    {
        $this->fakeApi();

        $this->source()->findDeals($this->criteria());

        Http::assertSent(function ($request): bool {
            if (! str_contains((string) $request->url(), '/Api/search/timetable')) {
                return true;
            }

            $flight = $request->data()['flightList'][0];

            return $flight['from'] === '2026-08-01' && $flight['to'] === '2026-08-21';
        });
    }

    public function test_it_discovers_the_api_version_from_the_public_site(): void
    {
        $this->fakeApi();

        $this->source()->findDeals($this->criteria());

        Http::assertSent(fn ($request): bool => ! str_contains((string) $request->url(), '/Api/search/timetable')
            || str_contains((string) $request->url(), '/29.9.0/Api/search/timetable'));
    }

    public function test_it_falls_back_to_the_configured_version_when_discovery_fails(): void
    {
        Http::fake([
            'wizzair.com/en-gb' => Http::response('', 403),
            'be.wizzair.com/*' => Http::response(['outboundFlights' => []]),
        ]);

        $this->source()->findDeals($this->criteria());

        Http::assertSent(fn ($request): bool => ! str_contains((string) $request->url(), '/Api/search/timetable')
            || str_contains((string) $request->url(), '/1.2.3/Api/search/timetable'));
    }

    public function test_it_reports_the_source_as_unavailable_when_every_route_fails(): void
    {
        Http::fake([
            'wizzair.com/en-gb' => Http::response('be.wizzair.com/29.9.0'),
            'be.wizzair.com/*' => Http::response('', 500),
        ]);

        $this->expectException(DealSourceUnavailable::class);

        $this->source()->findDeals($this->criteria());
    }

    /**
     * The live timetable prices days with no flight at zero. Treating those as
     * free seats floods the dashboard with offers that cannot be bought.
     */
    public function test_it_ignores_days_the_timetable_prices_at_zero(): void
    {
        Http::fake([
            'wizzair.com/en-gb' => Http::response('be.wizzair.com/29.9.0'),
            'be.wizzair.com/*' => Http::response(['outboundFlights' => [
                [
                    'departureStation' => 'WAW',
                    'arrivalStation' => 'LTN',
                    'departureDate' => '2026-08-05T00:00:00',
                    'price' => ['amount' => 0.0, 'currencyCode' => 'PLN'],
                    'departureDates' => [],
                ],
                [
                    'departureStation' => 'WAW',
                    'arrivalStation' => 'LTN',
                    'departureDate' => '2026-08-06T00:00:00',
                    'price' => ['amount' => 129.0, 'currencyCode' => 'PLN'],
                    'departureDates' => ['2026-08-06T06:00:00'],
                ],
            ]]),
        ]);

        $deals = $this->source()->findDeals($this->criteria());

        $this->assertCount(1, $deals);
        $this->assertSame(12900, $deals[0]->price->minorUnits);
    }

    public function test_it_pairs_each_outbound_day_with_the_cheapest_way_back(): void
    {
        Http::fake([
            'wizzair.com/en-gb' => Http::response('be.wizzair.com/29.9.0'),
            'be.wizzair.com/*' => Http::response($this->fixture('WizzAir/timetable-return.json')),
        ]);

        $deals = $this->source()->findDeals($this->criteria());

        $paired = array_values(array_filter(
            $deals,
            static fn ($deal): bool => $deal->type === DealType::RoundTrip,
        ));

        $this->assertNotEmpty($paired);

        foreach ($paired as $deal) {
            $this->assertNotNull($deal->returnsAt);
            $this->assertTrue(
                $deal->departsAt?->lessThan($deal->returnsAt) ?? false,
                'The way back cannot precede the way out.',
            );
            $this->assertGreaterThanOrEqual(2, $deal->nights());
            $this->assertLessThanOrEqual(10, $deal->nights());
            $this->assertStringContainsString('/booking/select-flight/WAW/LTN/', $deal->url);
        }
    }

    public function test_it_asks_about_both_directions_in_one_request(): void
    {
        Http::fake([
            'wizzair.com/en-gb' => Http::response('be.wizzair.com/29.9.0'),
            'be.wizzair.com/*' => Http::response($this->fixture('WizzAir/timetable-return.json')),
        ]);

        $this->source()->findDeals($this->criteria());

        Http::assertSent(function ($request): bool {
            if (! str_contains((string) $request->url(), '/Api/search/timetable')) {
                return true;
            }

            $legs = $request->data()['flightList'];

            return count($legs) === 2
                && $legs[0]['departureStation'] === 'WAW'
                && $legs[1]['departureStation'] === 'LTN'
                && $legs[1]['arrivalStation'] === 'WAW';
        });
    }

    public function test_it_charges_for_both_legs_together(): void
    {
        Http::fake([
            'wizzair.com/en-gb' => Http::response('be.wizzair.com/29.9.0'),
            'be.wizzair.com/*' => Http::response([
                'outboundFlights' => [$this->day('WAW', 'LTN', '2026-08-10', 129.0)],
                'returnFlights' => [
                    $this->day('LTN', 'WAW', '2026-08-13', 249.0),
                    $this->day('LTN', 'WAW', '2026-08-14', 151.0),
                    // Outside the stay window, however cheap it looks.
                    $this->day('LTN', 'WAW', '2026-09-20', 39.0),
                ],
            ]),
        ]);

        $deals = $this->source()->findDeals($this->criteria());

        $this->assertCount(1, $deals);
        $this->assertSame(DealType::RoundTrip, $deals[0]->type);
        $this->assertSame(28000, $deals[0]->price->minorUnits, '129 out plus the cheapest 151 back.');
        $this->assertSame('2026-08-14', $deals[0]->returnsAt?->toDateString());
        $this->assertSame(4, $deals[0]->nights());
    }

    public function test_an_outbound_day_with_no_way_back_stays_a_one_way_offer(): void
    {
        Http::fake([
            'wizzair.com/en-gb' => Http::response('be.wizzair.com/29.9.0'),
            'be.wizzair.com/*' => Http::response([
                'outboundFlights' => [$this->day('WAW', 'LTN', '2026-08-10', 129.0)],
                'returnFlights' => [$this->day('LTN', 'WAW', '2026-09-20', 99.0)],
            ]),
        ]);

        $deals = $this->source()->findDeals($this->criteria());

        $this->assertCount(1, $deals);
        $this->assertSame(DealType::Flight, $deals[0]->type);
        $this->assertNull($deals[0]->returnsAt);
        $this->assertSame(12900, $deals[0]->price->minorUnits);
    }

    /**
     * @return array<string, mixed>
     */
    private function day(string $from, string $to, string $date, float $price): array
    {
        return [
            'departureStation' => $from,
            'arrivalStation' => $to,
            'departureDate' => $date.'T00:00:00',
            'price' => ['amount' => $price, 'currencyCode' => 'PLN'],
            'departureDates' => [$date.'T06:00:00'],
        ];
    }

    public function test_it_returns_nothing_when_no_watched_airport_has_routes(): void
    {
        Http::fake();

        $deals = $this->source(['GDN' => ['BCN']])->findDeals($this->criteria());

        $this->assertSame([], $deals);
        Http::assertNothingSent();
    }

    private function fakeApi(): void
    {
        Http::fake([
            'wizzair.com/en-gb' => Http::response('<script src="https://be.wizzair.com/29.9.0/Api/"></script>'),
            'be.wizzair.com/*/Api/asset/map*' => Http::response($this->fixture('WizzAir/stations.json')),
            'be.wizzair.com/*' => Http::response($this->fixture('WizzAir/timetable.json')),
        ]);
    }

    /**
     * @param  array<string, list<string>>|null  $routes
     */
    private function source(?array $routes = null): WizzAirTimetableSource
    {
        $http = $this->app->make(HttpClient::class);

        $versionResolver = new WizzAirApiVersionResolver(
            $http,
            Cache::store('array'),
            'https://wizzair.com/en-gb',
            '1.2.3',
            5,
            60,
        );

        return new WizzAirTimetableSource(
            $http,
            $versionResolver,
            new WizzAirStationDirectory(
                $http,
                Cache::store('array'),
                $versionResolver,
                'https://be.wizzair.com',
                'pl-pl',
                5,
                60,
            ),
            new RoundTripPairing(new WeekendGetaway),
            'https://be.wizzair.com',
            'https://wizzair.com',
            $routes ?? ['WAW' => ['LTN']],
            5,
            30,
        );
    }

    private function criteria(): SearchCriteria
    {
        $from = CarbonImmutable::parse('2026-08-01');

        return new SearchCriteria(
            [Airport::fromIataCode('WAW')],
            $from,
            // Kept inside a single month: the timetable is asked one month at
            // a time, and a wider window would replay the faked response.
            $from->addDays(20),
            Money::fromDecimal(150, 'PLN'),
            Money::fromDecimal(600, 'PLN'),
            Money::fromDecimal(2500, 'PLN'),
            new StayWindow(2, 10),
        );
    }

    private function fixture(string $path): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/'.$path));
    }
}
