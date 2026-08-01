<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Deal\ScanForDeals;
use App\Application\Deal\SearchCriteriaFactory;
use Illuminate\Console\Command;

final class ScanDealsCommand extends Command
{
    protected $signature = 'deals:scan';

    protected $description = 'Ask every configured source for cheap flights and trips';

    public function handle(ScanForDeals $scan, SearchCriteriaFactory $criteriaFactory): int
    {
        $criteria = $criteriaFactory->create();

        $this->info(sprintf(
            'Scanning %s between %s and %s...',
            implode(', ', $criteria->departureIataCodes()),
            $criteria->departureFrom->toDateString(),
            $criteria->departureTo->toDateString(),
        ));

        $report = $scan->execute($criteria);

        $this->table(
            ['Found', 'New within budget', 'Worth an alert'],
            [[$report->found, $report->kept, $report->alerted]],
        );

        if ($report->hasFailures()) {
            $this->warn('Sources that could not be read: '.implode(', ', $report->failedSources));
        }

        return self::SUCCESS;
    }
}
