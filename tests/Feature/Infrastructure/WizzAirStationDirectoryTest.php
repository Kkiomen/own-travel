<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Infrastructure\DealSource\WizzAirApiVersionResolver;
use App\Infrastructure\DealSource\WizzAirStationDirectory;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Runs against the station list captured from be.wizzair.com.
 */
final class WizzAirStationDirectoryTest extends TestCase
{
    public function test_it_puts_a_name_and_a_country_to_a_code(): void
    {
        $this->fakeApi();

        $airport = $this->directory()->lookUp('LTN');

        $this->assertSame('LTN', $airport->iataCode);
        $this->assertSame('Londyn-Luton', $airport->label());
        $this->assertSame('Wielka Brytania', $airport->countryName);
    }

    public function test_it_trims_what_the_directory_serves(): void
    {
        $this->fakeApi();

        // Several entries come back with a trailing line break - "Warszawa
        // Chopin\r\n" - which would show up in the middle of the page.
        $this->assertSame('Warszawa Chopin', $this->directory()->lookUp('WAW')->label());
    }

    public function test_an_airport_it_does_not_know_keeps_its_code(): void
    {
        $this->fakeApi();

        $airport = $this->directory()->lookUp('XXX');

        $this->assertSame('XXX', $airport->iataCode);
        $this->assertSame('XXX', $airport->label());
        $this->assertNull($airport->countryName);
    }

    public function test_a_directory_that_is_down_is_not_an_outage(): void
    {
        Http::fake([
            'wizzair.com/en-gb' => Http::response('<script src="https://be.wizzair.com/29.9.0/Api/"></script>'),
            'be.wizzair.com/*' => Http::response('', 503),
        ]);

        // The offer is still worth showing, just under its code.
        $this->assertSame('LTN', $this->directory()->lookUp('LTN')->label());
    }

    public function test_the_list_is_fetched_once_however_many_codes_are_looked_up(): void
    {
        $this->fakeApi();

        $directory = $this->directory();
        $directory->lookUp('LTN');
        $directory->lookUp('WAW');
        $directory->lookUp('CTA');

        // It is the better part of a megabyte, and a scan asks about dozens of
        // airports.
        $this->assertCount(1, Http::recorded(
            static fn ($request): bool => str_contains((string) $request->url(), '/Api/asset/map'),
        ));
    }

    public function test_it_asks_for_the_names_in_polish(): void
    {
        $this->fakeApi();

        $this->directory()->lookUp('CTA');

        Http::assertSent(function ($request): bool {
            if (! str_contains((string) $request->url(), '/Api/asset/map')) {
                return true;
            }

            return str_contains((string) $request->url(), 'languageCode=pl-pl');
        });
    }

    private function fakeApi(): void
    {
        Http::fake([
            'wizzair.com/en-gb' => Http::response('<script src="https://be.wizzair.com/29.9.0/Api/"></script>'),
            'be.wizzair.com/*' => Http::response(
                (string) file_get_contents(base_path('tests/Fixtures/WizzAir/stations.json')),
            ),
        ]);
    }

    private function directory(): WizzAirStationDirectory
    {
        $http = $this->app->make(HttpClient::class);

        return new WizzAirStationDirectory(
            $http,
            Cache::store('array'),
            new WizzAirApiVersionResolver($http, Cache::store('array'), 'https://wizzair.com/en-gb', '1.2.3', 5, 60),
            'https://be.wizzair.com',
            'pl-pl',
            5,
            60,
        );
    }
}
