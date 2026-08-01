<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Domain\Deal\DealType;
use App\Domain\Deal\Exception\DealSourceUnavailable;
use App\Domain\Deal\ValueObject\Airport;
use App\Domain\Deal\ValueObject\Money;
use App\Domain\Deal\ValueObject\SearchCriteria;
use App\Domain\Deal\ValueObject\StayWindow;
use App\Infrastructure\DealSource\Ryanair\FareReader;
use App\Infrastructure\DealSource\RyanairFareFinderSource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Runs against a response captured from the live fare finder.
 */
final class RyanairFareFinderSourceTest extends TestCase
{
    public function test_it_maps_fares_into_deals(): void
    {
        Http::fake([
            'services-api.ryanair.com/*' => Http::response($this->fixture('Ryanair/one-way-fares.json')),
        ]);

        $deals = $this->source()->findDeals($this->criteria());

        $this->assertCount(3, $deals);

        $cheapest = $deals[0];
        $this->assertSame('ryanair', $cheapest->source);
        $this->assertSame(DealType::Flight, $cheapest->type);
        $this->assertSame('Kraków → Aberdeen', $cheapest->title);
        $this->assertSame(11900, $cheapest->price->minorUnits);
        $this->assertSame('PLN', $cheapest->price->currency);
        $this->assertSame('KRK → ABZ', $cheapest->routeLabel());
        $this->assertSame('Wielka Brytania', $cheapest->destination?->countryName);
        $this->assertSame('2026-10-30', $cheapest->departsAt?->toDateString());
        $this->assertStringContainsString('originIata=KRK', $cheapest->url);
        $this->assertStringContainsString('destinationIata=ABZ', $cheapest->url);
    }

    public function test_it_asks_only_for_fares_inside_the_criteria(): void
    {
        Http::fake([
            'services-api.ryanair.com/*' => Http::response($this->fixture('Ryanair/one-way-fares.json')),
        ]);

        $this->source()->findDeals($this->criteria());

        Http::assertSent(function ($request): bool {
            $query = [];
            parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);

            return $query['departureAirportIataCode'] === 'KRK'
                && $query['outboundDepartureDateFrom'] === '2026-08-01'
                && $query['outboundDepartureDateTo'] === '2026-10-30'
                && $query['priceValueTo'] === '150'
                && $query['currency'] === 'PLN';
        });
    }

    public function test_it_reports_the_source_as_unavailable_when_every_airport_fails(): void
    {
        Http::fake([
            'services-api.ryanair.com/*' => Http::response('', 503),
        ]);

        $this->expectException(DealSourceUnavailable::class);

        $this->source()->findDeals($this->criteria());
    }

    public function test_it_survives_a_single_failing_airport(): void
    {
        Http::fakeSequence()
            ->push('', 503)
            ->push($this->fixture('Ryanair/one-way-fares.json'), 200);

        $deals = $this->source()->findDeals($this->criteria([
            Airport::fromIataCode('WAW'),
            Airport::fromIataCode('KRK'),
        ]));

        $this->assertCount(3, $deals);
    }

    public function test_it_skips_fares_it_cannot_understand(): void
    {
        Http::fake([
            'services-api.ryanair.com/*' => Http::response([
                'fares' => [
                    ['outbound' => ['departureAirport' => ['iataCode' => 'KRK']]],
                    'nonsense',
                ],
            ]),
        ]);

        $this->assertSame([], $this->source()->findDeals($this->criteria()));
    }

    private function source(): RyanairFareFinderSource
    {
        return new RyanairFareFinderSource(
            $this->app->make(HttpClient::class),
            new FareReader,
            'https://services-api.ryanair.com',
            'https://www.ryanair.com/pl/pl/trip/flights/select',
            'pl-pl',
            5,
        );
    }

    /**
     * @param  list<Airport>|null  $airports
     */
    private function criteria(?array $airports = null): SearchCriteria
    {
        $from = CarbonImmutable::parse('2026-08-01');

        return new SearchCriteria(
            $airports ?? [Airport::fromIataCode('KRK')],
            $from,
            $from->addDays(90),
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
