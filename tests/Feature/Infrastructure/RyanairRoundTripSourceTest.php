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
use App\Infrastructure\DealSource\RyanairRoundTripSource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Runs against a response captured from the live round-trip fare finder.
 */
final class RyanairRoundTripSourceTest extends TestCase
{
    public function test_it_pairs_both_legs_and_prices_them_together(): void
    {
        Http::fake([
            'services-api.ryanair.com/*' => Http::response($this->fixture('Ryanair/round-trip-fares.json')),
        ]);

        $deals = $this->source()->findDeals($this->criteria());

        $this->assertNotEmpty($deals);

        $first = $deals[0];
        $this->assertSame(DealType::RoundTrip, $first->type);
        $this->assertSame('ryanair-return', $first->source);
        $this->assertSame('Kraków ⇄ Aberdeen', $first->title);
        // 136 out + 119 back, charged as the 255 the summary reports.
        $this->assertSame(25500, $first->price->minorUnits);
        $this->assertSame('2026-10-27', $first->departsAt?->toDateString());
        $this->assertSame('2026-10-30', $first->returnsAt?->toDateString());
        $this->assertSame(3, $first->nights());
    }

    public function test_the_booking_link_covers_both_legs(): void
    {
        Http::fake([
            'services-api.ryanair.com/*' => Http::response($this->fixture('Ryanair/round-trip-fares.json')),
        ]);

        $url = $this->source()->findDeals($this->criteria())[0]->url;

        $this->assertStringContainsString('dateOut=2026-10-27', $url);
        $this->assertStringContainsString('dateIn=2026-10-30', $url);
        $this->assertStringContainsString('isReturn=true', $url);
    }

    public function test_it_asks_for_a_stay_that_could_be_taken_as_leave(): void
    {
        Http::fake([
            'services-api.ryanair.com/*' => Http::response($this->fixture('Ryanair/round-trip-fares.json')),
        ]);

        $this->source()->findDeals($this->criteria());

        Http::assertSent(function ($request): bool {
            $query = [];
            parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);

            return $query['durationFrom'] === '2'
                && $query['durationTo'] === '10'
                && $query['priceValueTo'] === '600';
        });
    }

    /**
     * The endpoint answers "InvalidLimit" above 20 - and since it sorts
     * cheapest-first, 20 is the best twenty pairings, not a random slice.
     */
    public function test_it_stays_within_the_limit_the_endpoint_accepts(): void
    {
        Http::fake([
            'services-api.ryanair.com/*' => Http::response($this->fixture('Ryanair/round-trip-fares.json')),
        ]);

        $this->source()->findDeals($this->criteria());

        Http::assertSent(function ($request): bool {
            $query = [];
            parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);

            return (int) $query['limit'] <= 20;
        });
    }

    /**
     * The API is asked for a sensible stay, but the answer is checked anyway -
     * a cheap seat out with the way back a month later is not a holiday.
     */
    public function test_it_drops_a_pairing_outside_the_stay_window(): void
    {
        Http::fake([
            'services-api.ryanair.com/*' => Http::response(['fares' => [[
                'outbound' => [
                    'departureAirport' => ['iataCode' => 'KRK', 'name' => 'Kraków'],
                    'arrivalAirport' => ['iataCode' => 'AGP', 'name' => 'Malaga'],
                    'departureDate' => '2026-08-10T06:00:00',
                    'price' => ['value' => 40.0, 'currencyCode' => 'PLN'],
                ],
                'inbound' => [
                    'departureAirport' => ['iataCode' => 'AGP', 'name' => 'Malaga'],
                    'arrivalAirport' => ['iataCode' => 'KRK', 'name' => 'Kraków'],
                    'departureDate' => '2026-09-15T06:00:00',
                    'price' => ['value' => 60.0, 'currencyCode' => 'PLN'],
                ],
                'summary' => ['price' => ['value' => 100.0, 'currencyCode' => 'PLN']],
            ]]]),
        ]);

        $this->assertSame([], $this->source()->findDeals($this->criteria()));
    }

    public function test_it_reports_the_source_as_unavailable_when_every_airport_fails(): void
    {
        Http::fake(['services-api.ryanair.com/*' => Http::response('', 503)]);

        $this->expectException(DealSourceUnavailable::class);

        $this->source()->findDeals($this->criteria());
    }

    public function test_it_skips_fares_it_cannot_understand(): void
    {
        Http::fake([
            'services-api.ryanair.com/*' => Http::response(['fares' => [
                ['outbound' => ['departureAirport' => ['iataCode' => 'KRK']]],
                'nonsense',
            ]]),
        ]);

        $this->assertSame([], $this->source()->findDeals($this->criteria()));
    }

    private function source(): RyanairRoundTripSource
    {
        return new RyanairRoundTripSource(
            $this->app->make(HttpClient::class),
            new FareReader,
            RyanairRoundTripSource::NAME,
            'https://services-api.ryanair.com',
            'https://www.ryanair.com/pl/pl/trip/flights/select',
            'pl-pl',
            5,
        );
    }

    private function criteria(): SearchCriteria
    {
        $from = CarbonImmutable::parse('2026-08-01');

        return new SearchCriteria(
            [Airport::fromIataCode('KRK')],
            $from,
            $from->addDays(90),
            Money::fromDecimal(300, 'PLN'),
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
