<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Domain\Deal\Deal;
use App\Domain\Deal\Port\DealNotifier;
use Psr\Log\LoggerInterface;

/**
 * Placeholder channel: deals land in the database and on the dashboard, and
 * the log records that they were worth shouting about. Swapping in Telegram or
 * e-mail later means adding an adapter and changing one binding.
 */
final readonly class LogDealNotifier implements DealNotifier
{
    public function __construct(private LoggerInterface $logger) {}

    public function notify(Deal $deal): void
    {
        $this->logger->info('Deal worth buying found.', [
            'source' => $deal->source,
            'type' => $deal->type->value,
            'title' => $deal->title,
            'price' => $deal->price->format(),
            'route' => $deal->routeLabel(),
            'departs_at' => $deal->departsAt?->toDateTimeString(),
            'url' => $deal->url,
        ]);
    }
}
