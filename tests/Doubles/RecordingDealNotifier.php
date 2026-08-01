<?php

declare(strict_types=1);

namespace Tests\Doubles;

use App\Domain\Deal\Deal;
use App\Domain\Deal\Port\DealNotifier;

final class RecordingDealNotifier implements DealNotifier
{
    /** @var list<Deal> */
    public array $notified = [];

    public function notify(Deal $deal): void
    {
        $this->notified[] = $deal;
    }
}
