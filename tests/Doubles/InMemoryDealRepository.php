<?php

declare(strict_types=1);

namespace Tests\Doubles;

use App\Domain\Deal\Deal;
use App\Domain\Deal\DealSort;
use App\Domain\Deal\DealType;
use App\Domain\Deal\Port\DealRepository;
use App\Domain\Deal\ValueObject\DealListing;
use App\Domain\Deal\ValueObject\DealSummary;
use App\Domain\Deal\ValueObject\HolidayWindow;
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
            fn (Deal $deal): bool => ($deal->departsAt === null
                || ! $deal->departsAt->lessThan($listing->now))
                && ($listing->type === null || $deal->type === $listing->type)
                && (! $listing->weekendsOnly || $deal->weekendGetaway)
                && (! $listing->stealsOnly || $deal->typicalPrice !== null)
                && ($listing->origin === null || $deal->origin?->equals($listing->origin) === true)
                && ($listing->destination === null || $deal->destination?->equals($listing->destination) === true)
                && ($listing->undatedTripsOnly
                    ? $this->isAnUndatedTrip($deal)
                    : ! $listing->holiday instanceof HolidayWindow || $this->fitsTheHoliday($deal, $listing->holiday)),
        ));

        match ($listing->sort) {
            DealSort::Score => usort($deals, static fn (Deal $a, Deal $b): int => ($b->score?->value ?? -1) <=> ($a->score?->value ?? -1)),
            DealSort::Price => usort($deals, static fn (Deal $a, Deal $b): int => $a->price->minorUnits <=> $b->price->minorUnits),
            DealSort::Newest => $deals = array_reverse($deals),
        };

        return array_slice($deals, 0, $listing->limit);
    }

    /**
     * The same rule the real one puts in SQL: a journey qualifies when it can
     * be shown to fall inside the leave, whether it carries its dates as a
     * departure or as the terms an article named.
     */
    private function fitsTheHoliday(Deal $deal, HolidayWindow $holiday): bool
    {
        if ($deal->departsAt !== null && $holiday->covers($deal->departsAt, $deal->returnsAt)) {
            return true;
        }

        foreach ($deal->trip->dates ?? [] as $window) {
            if ($holiday->covers($window->from, $window->to)) {
                return true;
            }
        }

        return false;
    }

    private function isAnUndatedTrip(Deal $deal): bool
    {
        return $deal->type === DealType::Trip && ($deal->trip->dates ?? []) === [];
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

    public function availableAirports(DealListing $listing): array
    {
        $origins = [];
        $destinations = [];

        foreach ($this->deals as $deal) {
            // Each side ignores its own filter, exactly as the real one does.
            if ($deal->origin !== null && $this->matches($deal, $listing, ignoringOrigin: true)) {
                $origins[$deal->origin->iataCode] = $deal->origin;
            }

            if ($deal->destination !== null && $this->matches($deal, $listing, ignoringDestination: true)) {
                $destinations[$deal->destination->iataCode] = $deal->destination;
            }
        }

        return [
            'origins' => array_values($origins),
            'destinations' => array_values($destinations),
        ];
    }

    private function matches(
        Deal $deal,
        DealListing $listing,
        bool $ignoringOrigin = false,
        bool $ignoringDestination = false,
    ): bool {
        if ($deal->departsAt !== null && $deal->departsAt->lessThan($listing->now)) {
            return false;
        }

        if ($listing->type !== null && $deal->type !== $listing->type) {
            return false;
        }

        if ($listing->weekendsOnly && ! $deal->weekendGetaway) {
            return false;
        }

        if (! $ignoringOrigin && $listing->origin !== null
            && $deal->origin?->iataCode !== $listing->origin->iataCode) {
            return false;
        }

        if (! $ignoringDestination && $listing->destination !== null
            && $deal->destination?->iataCode !== $listing->destination->iataCode) {
            return false;
        }

        return true;
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
