<?php

declare(strict_types=1);

namespace App\Application\Deal;

use App\Domain\Deal\Port\DealRepository;
use Illuminate\Support\Facades\Date;

/**
 * Throws away offers that stopped being offers: flights that have departed and
 * anything found long enough ago that it is no longer news.
 *
 * Without this the dashboard slowly fills with the past.
 */
final readonly class PruneStaleDeals
{
    public function __construct(
        private DealRepository $repository,
        private int $retentionDays,
    ) {}

    public function execute(): int
    {
        $now = Date::now()->toImmutable();

        return $this->repository->purgeExpired(
            departedBefore: $now,
            foundBefore: $now->subDays($this->retentionDays),
        );
    }
}
