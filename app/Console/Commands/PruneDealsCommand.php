<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Deal\PruneStaleDeals;
use Illuminate\Console\Command;

final class PruneDealsCommand extends Command
{
    protected $signature = 'deals:prune';

    protected $description = 'Forget departed flights and offers old enough to be stale';

    public function handle(PruneStaleDeals $prune): int
    {
        $this->info(sprintf('Removed %d stale deals.', $prune->execute()));

        return self::SUCCESS;
    }
}
