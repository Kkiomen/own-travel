<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Deal;

use App\Application\Deal\PruneStaleDeals;
use App\Domain\Deal\Deal;
use App\Domain\Deal\ValueObject\Airport;
use App\Domain\Deal\ValueObject\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\TestCase;
use Tests\Doubles\InMemoryDealRepository;

final class PruneStaleDealsTest extends TestCase
{
    private InMemoryDealRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new InMemoryDealRepository;
        Date::setTestNow(CarbonImmutable::parse('2026-08-01 12:00'));
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    public function test_it_forgets_flights_that_have_departed(): void
    {
        $this->repository->store($this->flight('2026-07-25 06:00'));
        $this->repository->store($this->flight('2026-09-25 06:00'));

        $removed = (new PruneStaleDeals($this->repository, 45))->execute();

        $this->assertSame(1, $removed);
        $this->assertSame(1, $this->repository->count());
    }

    public function test_it_leaves_a_flight_departing_later_today_alone(): void
    {
        $this->repository->store($this->flight('2026-08-01 23:30'));

        $this->assertSame(0, (new PruneStaleDeals($this->repository, 45))->execute());
        $this->assertSame(1, $this->repository->count());
    }

    private function flight(string $departsAt): Deal
    {
        return Deal::flight(
            source: 'ryanair',
            title: 'Kraków → Malaga',
            price: Money::fromDecimal(99, 'PLN'),
            url: 'https://example.test/'.$departsAt,
            origin: Airport::fromIataCode('KRK'),
            destination: Airport::fromIataCode('AGP'),
            departsAt: CarbonImmutable::parse($departsAt),
        );
    }
}
