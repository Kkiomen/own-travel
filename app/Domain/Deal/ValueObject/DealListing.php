<?php

declare(strict_types=1);

namespace App\Domain\Deal\ValueObject;

use App\Domain\Deal\DealSort;
use App\Domain\Deal\DealType;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * What the dashboard is asking for.
 *
 * Ordering and every filter belong in the query, not in the page: with
 * hundreds of offers kept and only a page of them sent, filtering afterwards
 * would show an empty tab while the database is full of matches.
 */
final readonly class DealListing
{
    public function __construct(
        public int $limit,
        public DealSort $sort,
        public CarbonImmutable $now,
        public ?DealType $type = null,
        public bool $weekendsOnly = false,
        public bool $stealsOnly = false,
        public ?Airport $origin = null,
        public ?Airport $destination = null,
        /** Departures from here are listed first, whatever the sort. */
        public ?Airport $preferredOrigin = null,
    ) {
        if ($limit < 1) {
            throw new InvalidArgumentException('A listing needs room for at least one deal.');
        }
    }
}
