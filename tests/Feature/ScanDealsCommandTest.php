<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Infrastructure\Persistence\Eloquent\DealRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Everything wired together: the command resolves the real adapters and only
 * the network is faked.
 */
final class ScanDealsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Pinned so the assertions describe the scan, not whatever .env holds.
        config()->set('deals.max_flight_price', 300);
        config()->set('deals.max_trip_price', 2500);
        config()->set('deals.departure_airports', ['KRK']);
        config()->set('deals.wizzair.routes', ['KRK' => ['CTA']]);
        config()->set('deals.feeds', [['name' => 'fly4free', 'url' => 'https://www.fly4free.pl/feed/', 'enabled' => true]]);
    }

    public function test_it_stores_the_flights_that_are_cheap_enough(): void
    {
        $this->fakeEverySource();

        $this->artisan('deals:scan')->assertSuccessful();

        // All three fares in the Ryanair fixture are under the threshold.
        $this->assertSame(3, DealRecord::query()->where('source', 'ryanair')->count());
        $this->assertDatabaseHas('deals', ['source' => 'ryanair', 'price_minor_units' => 11900]);

        // The captured Wizz Air timetable starts at 179 PLN, so the cheapest
        // days of it qualify too.
        $this->assertGreaterThan(0, DealRecord::query()->where('source', 'wizzair')->count());
        $this->assertSame(0, DealRecord::query()
            ->where('source', 'wizzair')
            ->where('price_minor_units', '>', 30000)
            ->count());
    }

    public function test_it_never_stores_anything_above_the_thresholds(): void
    {
        $this->fakeEverySource();

        $this->artisan('deals:scan')->assertSuccessful();

        $this->assertSame(0, DealRecord::query()
            ->where('type', 'flight')
            ->where('price_minor_units', '>', 30000)
            ->count());

        $this->assertSame(0, DealRecord::query()
            ->where('type', 'trip')
            ->where('price_minor_units', '>', 250000)
            ->count());
    }

    public function test_it_picks_up_trips_from_the_blog_feed(): void
    {
        $this->fakeEverySource();

        $this->artisan('deals:scan')->assertSuccessful();

        $feedDeals = DealRecord::query()->where('source', 'fly4free')->get();

        $this->assertGreaterThan(0, $feedDeals->count());
        $this->assertTrue($feedDeals->every(fn (DealRecord $deal): bool => $deal->url !== ''));
    }

    public function test_a_second_run_stores_nothing_new(): void
    {
        $this->fakeEverySource();

        $this->artisan('deals:scan')->assertSuccessful();
        $countAfterFirstRun = DealRecord::query()->count();

        $this->artisan('deals:scan')->assertSuccessful();

        $this->assertSame($countAfterFirstRun, DealRecord::query()->count());
    }

    public function test_it_keeps_going_when_a_source_is_down(): void
    {
        Http::fake([
            'services-api.ryanair.com/*' => Http::response($this->fixture('Ryanair/one-way-fares.json')),
            'wizzair.com/*' => Http::response('', 503),
            'be.wizzair.com/*' => Http::response('', 503),
            'fly4free.pl/*' => Http::response('', 503),
        ]);

        $this->artisan('deals:scan')
            ->expectsOutputToContain('Sources that could not be read')
            ->assertSuccessful();

        $this->assertSame(3, DealRecord::query()->count());
    }

    private function fakeEverySource(): void
    {
        Http::fake([
            'services-api.ryanair.com/*' => Http::response($this->fixture('Ryanair/one-way-fares.json')),
            'wizzair.com/en-gb' => Http::response('be.wizzair.com/29.9.0'),
            'be.wizzair.com/*' => Http::response($this->fixture('WizzAir/timetable.json')),
            'fly4free.pl/*' => Http::response($this->fixture('Rss/fly4free.xml')),
        ]);
    }

    private function fixture(string $path): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/'.$path));
    }
}
