<?php

declare(strict_types=1);

namespace App\Domain\Deal\Port;

use App\Domain\Deal\Deal;
use App\Domain\Deal\Exception\DealSourceUnavailable;
use App\Domain\Deal\ValueObject\SearchCriteria;

/**
 * Outbound port: somewhere deals can be found.
 *
 * Implementations live in the infrastructure layer and are the only place that
 * knows about HTTP, HTML, feeds or vendor payload shapes.
 */
interface DealSource
{
    /**
     * Stable machine name, stored with every deal it produces.
     */
    public function name(): string;

    /**
     * @return list<Deal>
     *
     * @throws DealSourceUnavailable when the source cannot be reached or understood
     */
    public function findDeals(SearchCriteria $criteria): array;
}
