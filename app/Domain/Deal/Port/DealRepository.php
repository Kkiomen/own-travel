<?php

declare(strict_types=1);

namespace App\Domain\Deal\Port;

use App\Domain\Deal\Deal;
use App\Domain\Deal\ValueObject\Airport;
use App\Domain\Deal\ValueObject\DealListing;
use App\Domain\Deal\ValueObject\DealSummary;
use Carbon\CarbonImmutable;

interface DealRepository
{
    /**
     * Stores the deal unless an identical one is already known.
     *
     * @return bool true when the deal was new
     */
    public function store(Deal $deal): bool;

    /**
     * Deals worth showing, ordered as asked. Flights that have already
     * departed are never returned, whether or not they have been pruned yet.
     *
     * @return list<Deal>
     */
    public function list(DealListing $listing): array;

    /**
     * How many deals of each kind are on offer, and the cheapest of each.
     */
    public function summarise(CarbonImmutable $now): DealSummary;

    /**
     * The airports actually on offer *under the filters already chosen*, so the
     * dashboard can only ever ask for somewhere there are deals.
     *
     * Each side ignores its own filter: the destinations are what can be
     * reached from the chosen origin, and the origins are where the chosen
     * destination can be reached from. Narrowing a list by itself would leave
     * it holding the one value already picked.
     *
     * @return array{origins: list<Airport>, destinations: list<Airport>}
     */
    public function availableAirports(DealListing $listing): array;

    /**
     * Drops flights that departed before the given moment, and anything found
     * before the other - a blog offer from two months ago is not news.
     *
     * @return int how many were removed
     */
    public function purgeExpired(CarbonImmutable $departedBefore, CarbonImmutable $foundBefore): int;
}
