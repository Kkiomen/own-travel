<?php

declare(strict_types=1);

namespace App\Domain\Deal\ValueObject;

use App\Domain\Deal\DealType;

/**
 * How much is on offer overall, per kind.
 *
 * Counted in the database rather than over the page being shown, so the tiles
 * keep telling the truth while a filter is applied.
 */
final readonly class DealSummary
{
    /**
     * @param  array<string, int>  $counts  keyed by deal type
     * @param  array<string, Money>  $cheapest  keyed by deal type
     */
    public function __construct(
        private array $counts,
        private array $cheapest,
    ) {}

    public function countOf(DealType $type): int
    {
        return $this->counts[$type->value] ?? 0;
    }

    public function cheapestOf(DealType $type): ?Money
    {
        return $this->cheapest[$type->value] ?? null;
    }
}
