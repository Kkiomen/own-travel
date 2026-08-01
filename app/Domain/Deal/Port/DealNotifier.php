<?php

declare(strict_types=1);

namespace App\Domain\Deal\Port;

use App\Domain\Deal\Deal;

/**
 * Outbound port for alerting. Only newly found deals that pass the price gate
 * reach it, so implementations do not decide what is worth sending.
 */
interface DealNotifier
{
    public function notify(Deal $deal): void;
}
