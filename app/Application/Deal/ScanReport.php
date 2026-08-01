<?php

declare(strict_types=1);

namespace App\Application\Deal;

final readonly class ScanReport
{
    /**
     * @param  int  $found  offers returned by the sources
     * @param  int  $kept  new offers that fit the budget
     * @param  int  $alerted  kept offers that also cleared the score gate
     * @param  list<string>  $failedSources
     */
    public function __construct(
        public int $found,
        public int $kept,
        public int $alerted,
        public array $failedSources,
    ) {}

    public function hasFailures(): bool
    {
        return $this->failedSources !== [];
    }
}
