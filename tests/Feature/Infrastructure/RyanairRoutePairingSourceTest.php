<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Domain\Deal\DealType;
use App\Domain\Deal\Service\RoundTripPairing;
use App\Domain\Deal\Service\WeekendGetaway;
use App\Domain\Deal\ValueObject\Airport;
use App\Domain\Deal\ValueObject\Money;
use App\Domain\Deal\ValueObject\SearchCriteria;
use App\Domain\Deal\ValueObject\StayWindow;
use App\Infrastructure\DealSource\Ryanair\FareReader;
use App\Infrastructure\DealSource\RyanairRoutePairingSource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The legs are paired here rather than by the airline, so these tests are
 * about our rules, not Ryanair's.
 */
final class RyanairRoutePairingSourceTest extends TestCase
{
    public function test_it_pairs_a_cheap_way_out_with_the_cheapest_way_back(): void
    {
        $this->fakeRoute(
            outbound: [['2026-09-11 18:30', 89.0]],
            inbound: [['2026-09-13 20:00', 250.0], ['2026-09-14 09:00', 110.0]],
        );

        $deals = $this->source()->findDeals($this->criteria());

        $this->assertSame(DealType::RoundTrip, $deals[0]->type);
        $this->assertSame(19900, $deals[0]->price->minorUnits, '89 out plus the cheaper 110 back.');
        $this->assertSame('2026-09-14', $deals[0]->returnsAt?->toDateString());
    }

    /**
     * The cheapest way back is on Monday, so a weekend pairing would never
     * surface if we only kept the cheapest one.
     */
    public function test_it_also_offers_the_cheapest_way_back_that_makes_a_weekend(): void
    {
        $this->fakeRoute(
            outbound: [['2026-09-11 18:30', 89.0]],
            inbound: [['2026-09-13 20:00', 250.0], ['2026-09-14 09:00', 110.0]],
        );

        $deals = $this->source(new WeekendGetaway)->findDeals($this->criteria());

        $weekends = array_values(array_filter(
            $deals,
            fn ($deal): bool => (new WeekendGetaway)->matches($deal),
        ));

        $this->assertCount(1, $weekends);
        $this->assertSame('2026-09-13', $weekends[0]->returnsAt?->toDateString());
        $this->assertSame(33900, $weekends[0]->price->minorUnits);
    }

    public function test_it_ignores_days_that_are_sold_out(): void
    {
        Http::fake([
            'services-api.ryanair.com/farfnd/v4/oneWayFares?*' => Http::response($this->routeListing()),
            'services-api.ryanair.com/*cheapestPerDay*' => Http::response(['outbound' => ['fares' => [
                ['departureDate' => '2026-09-11T18:30:00', 'price' => ['value' => 89.0, 'currencyCode' => 'PLN'], 'soldOut' => true, 'unavailable' => false],
                ['departureDate' => '2026-09-13T20:00:00', 'price' => ['value' => 99.0, 'currencyCode' => 'PLN'], 'soldOut' => false, 'unavailable' => true],
            ]]]),
        ]);

        $this->assertSame([], $this->source()->findDeals($this->criteria()));
    }

    public function test_it_only_pairs_from_the_airports_it_was_given(): void
    {
        $this->fakeRoute(
            outbound: [['2026-09-11 18:30', 89.0]],
            inbound: [['2026-09-13 20:00', 99.0]],
        );

        $deals = $this->source(homeAirports: ['GDN'])->findDeals($this->criteria());

        $this->assertSame([], $deals);
        Http::assertNothingSent();
    }

    public function test_it_asks_only_about_the_routes_worth_pairing(): void
    {
        $this->fakeRoute(
            outbound: [['2026-09-11 18:30', 89.0]],
            inbound: [['2026-09-13 20:00', 99.0]],
        );

        $this->source(routesPerAirport: 1)->findDeals($this->criteria());

        Http::assertSent(fn ($request): bool => ! str_contains((string) $request->url(), 'cheapestPerDay')
            || str_contains((string) $request->url(), '/WRO/BGY/')
            || str_contains((string) $request->url(), '/BGY/WRO/'));
    }

    /**
     * @param  list<array{0: string, 1: float}>  $outbound
     * @param  list<array{0: string, 1: float}>  $inbound
     */
    private function fakeRoute(array $outbound, array $inbound): void
    {
        Http::fake([
            'services-api.ryanair.com/farfnd/v4/oneWayFares?*' => Http::response($this->routeListing()),
            'services-api.ryanair.com/*/WRO/BGY/cheapestPerDay*' => Http::response($this->perDay($outbound)),
            'services-api.ryanair.com/*/BGY/WRO/cheapestPerDay*' => Http::response($this->perDay($inbound)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function routeListing(): array
    {
        return ['fares' => [[
            'outbound' => [
                'departureAirport' => ['iataCode' => 'WRO', 'name' => 'Wrocław'],
                'arrivalAirport' => ['iataCode' => 'BGY', 'name' => 'Mediolan-Bergamo'],
                'departureDate' => '2026-09-11T18:30:00',
                'price' => ['value' => 89.0, 'currencyCode' => 'PLN'],
            ],
        ]]];
    }

    /**
     * @param  list<array{0: string, 1: float}>  $days
     * @return array<string, mixed>
     */
    private function perDay(array $days): array
    {
        return ['outbound' => ['fares' => array_map(
            static fn (array $day): array => [
                'departureDate' => str_replace(' ', 'T', $day[0]).':00',
                'price' => ['value' => $day[1], 'currencyCode' => 'PLN'],
                'soldOut' => false,
                'unavailable' => false,
            ],
            $days,
        )]];
    }

    /**
     * @param  list<string>|null  $homeAirports
     */
    private function source(
        ?WeekendGetaway $weekends = null,
        ?array $homeAirports = null,
        int $routesPerAirport = 10,
    ): RyanairRoutePairingSource {
        return new RyanairRoutePairingSource(
            $this->app->make(HttpClient::class),
            new FareReader,
            new RoundTripPairing($weekends ?? new WeekendGetaway),
            'https://services-api.ryanair.com',
            'https://www.ryanair.com/pl/pl/trip/flights/select',
            'pl-pl',
            5,
            $homeAirports ?? ['WRO'],
            $routesPerAirport,
        );
    }

    private function criteria(): SearchCriteria
    {
        // Kept inside a single month: the per-day endpoint is asked one month
        // at a time, and a wider window would double the faked responses.
        $from = CarbonImmutable::parse('2026-09-01');

        return new SearchCriteria(
            [Airport::fromIataCode('WRO')],
            $from,
            $from->addDays(14),
            Money::fromDecimal(300, 'PLN'),
            Money::fromDecimal(600, 'PLN'),
            Money::fromDecimal(2500, 'PLN'),
            new StayWindow(2, 10),
        );
    }
}
