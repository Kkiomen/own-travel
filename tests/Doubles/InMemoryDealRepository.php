<?php

declare(strict_types=1);

namespace Tests\Doubles;

use App\Domain\Deal\Deal;
use App\Domain\Deal\DealSort;
use App\Domain\Deal\Port\DealRepository;
use App\Domain\Deal\ValueObject\DealListing;
use App\Domain\Deal\ValueObject\DealSummary;
use Carbon\CarbonImmutable;

final class InMemoryDealRepository implements DealRepository
{
    /** @var array<string, Deal> */
    private array $deals = [];

    public function store(Deal $deal): bool
    {
        $fingerprint = $deal->fingerprint();

        if (isset($this->deals[$fingerprint])) {
            return false;
        }

        $this->deals[$fingerprint] = $deal;

        return true;
    }

    public function list(DealListing $listing): array
    {
        $deals = array_values(array_filter(
            $this->deals,
            static fn (Deal $deal): bool => ($deal->departsAt === null
                || ! $deal->departsAt->lessThan($listing->now))
                && ($listing->type === null || $deal->type === $listing->type)
                && (! $listing->weekendsOnly || $deal->weekendGetaway)
                && (! $listing->stealsOnly || $deal->typicalPrice !== null)
                && ($listing->origin === null || $deal->origin?->equals($listing->origin) === true)
                && ($listing->destination === null || $deal->destination?->equals($listing->destination) === true),
        ));

        match ($listing->sort) {
            DealSort::Score => usort($deals, static fn (Deal $a, Deal $b): int => ($b->score?->value ?? -1) <=> ($a->score?->value ?? -1)),
            DealSort::Price => usort($deals, static fn (Deal $a, Deal $b): int => $a->price->minorUnits <=> $b->price->minorUnits),
            DealSort::Newest => $deals = array_reverse($deals),
        };

        return array_slice($deals, 0, $listing->limit);
    }

    public function summarise(CarbonImmutable $now): DealSummary
    {
        $counts = [];
        $cheapest = [];

        foreach ($this->deals as $deal) {
            if ($deal->departsAt !== null && $deal->departsAt->lessThan($now)) {
                continue;
            }

            $type = $deal->type->value;
            $counts[$type] = ($counts[$type] ?? 0) + 1;

            if (! isset($cheapest[$type]) || $deal->price->isLessThan($cheapest[$type])) {
                $cheapest[$type] = $deal->price;
            }
        }

        return new DealSummary($counts, $cheapest);
    }

    public function availableAirports(CarbonImmutable $now): array
    {
        $origins = [];
        $destinations = [];

        foreach ($this->deals as $deal) {
            if ($deal->origin !== null) {
                $origins[$deal->origin->iataCode] = $deal->origin;
            }

            if ($deal->destination !== null) {
                $destinations[$deal->destination->iataCode] = $deal->destination;
            }
        }

        return [
            'origins' => array_values($origins),
            'destinations' => array_values($destinations),
        ];
    }

    public function purgeExpired(CarbonImmutable $departedBefore, CarbonImmutable $foundBefore): int
    {
        $before = count($this->deals);

        $this->deals = array_filter(
            $this->deals,
            static fn (Deal $deal): bool => $deal->departsAt === null
                || ! $deal->departsAt->lessThan($departedBefore),
        );

        return $before - count($this->deals);
    }

    public function count(): int
    {
        return count($this->deals);
    }

    /**
     * @return list<Deal>
     */
    public function all(): array
    {
        return array_values($this->deals);
    }
}
