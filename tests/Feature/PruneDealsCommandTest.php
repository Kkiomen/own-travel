<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Deal\Deal;
use App\Domain\Deal\DealType;
use App\Domain\Deal\Port\DealRepository;
use App\Domain\Deal\ValueObject\Airport;
use App\Domain\Deal\ValueObject\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PruneDealsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_removes_departed_flights_and_keeps_the_rest(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01 12:00'));

        $repository = $this->app->make(DealRepository::class);

        $repository->store(Deal::flight(
            source: 'ryanair',
            title: 'Kraków → Malaga (odleciał)',
            price: Money::fromDecimal(99, 'PLN'),
            url: 'https://example.test/gone',
            origin: Airport::fromIataCode('KRK'),
            destination: Airport::fromIataCode('AGP'),
            departsAt: CarbonImmutable::parse('2026-07-20 06:00'),
        ));

        $repository->store(Deal::flight(
            source: 'ryanair',
            title: 'Kraków → Malaga (jeszcze leci)',
            price: Money::fromDecimal(99, 'PLN'),
            url: 'https://example.test/upcoming',
            origin: Airport::fromIataCode('KRK'),
            destination: Airport::fromIataCode('AGP'),
            departsAt: CarbonImmutable::parse('2026-09-20 06:00'),
        ));

        $repository->store(Deal::fromFeed(
            'fly4free',
            DealType::Trip,
            'Rzym za 168 PLN',
            Money::fromDecimal(168, 'PLN'),
            'https://example.test/rome',
            publishedAt: CarbonImmutable::parse('2026-07-01 08:00'),
        ));

        $this->artisan('deals:prune')
            ->expectsOutputToContain('Removed 1 stale deals.')
            ->assertSuccessful();

        $this->assertDatabaseCount('deals', 2);
        $this->assertDatabaseMissing('deals', ['url' => 'https://example.test/gone']);
        $this->assertDatabaseHas('deals', ['url' => 'https://example.test/rome']);
    }

    public function test_it_removes_offers_older_than_the_retention_window(): void
    {
        config()->set('deals.retention_days', 30);

        $this->travelTo(CarbonImmutable::parse('2026-06-01 12:00'));
        $this->app->make(DealRepository::class)->store(Deal::fromFeed(
            'fly4free',
            DealType::Trip,
            'Bardzo stara okazja',
            Money::fromDecimal(500, 'PLN'),
            'https://example.test/ancient',
        ));

        $this->travelTo(CarbonImmutable::parse('2026-08-01 12:00'));

        $this->artisan('deals:prune')->assertSuccessful();

        $this->assertDatabaseCount('deals', 0);
    }
}
