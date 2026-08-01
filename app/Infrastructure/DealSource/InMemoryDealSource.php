<?php

declare(strict_types=1);

namespace App\Infrastructure\DealSource;

use App\Domain\Deal\Deal;
use App\Domain\Deal\Exception\DealSourceUnavailable;
use App\Domain\Deal\Port\DealSource;
use App\Domain\Deal\ValueObject\SearchCriteria;

/**
 * A source that returns whatever it was handed. Lets the use case be tested
 * without touching the network, and lets a failing source be simulated.
 */
final class InMemoryDealSource implements DealSource
{
    /**
     * @param  list<Deal>  $deals
     */
    public function __construct(
        private readonly string $name,
        private readonly array $deals = [],
        private readonly bool $unavailable = false,
    ) {}

    public static function failing(string $name): self
    {
        return new self($name, [], true);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function findDeals(SearchCriteria $criteria): array
    {
        if ($this->unavailable) {
            throw DealSourceUnavailable::forSource($this->name, 'simulated failure');
        }

        return $this->deals;
    }
}
