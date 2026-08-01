<?php

declare(strict_types=1);

namespace App\Application\Deal;

use App\Domain\Deal\Exception\DealSourceUnavailable;
use App\Domain\Deal\Port\DealNotifier;
use App\Domain\Deal\Port\DealRepository;
use App\Domain\Deal\Port\DealSource;
use App\Domain\Deal\Service\DealEvaluator;
use App\Domain\Deal\Service\DealScorer;
use App\Domain\Deal\Service\WeekendGetaway;
use App\Domain\Deal\ValueObject\SearchCriteria;
use Psr\Log\LoggerInterface;

/**
 * Asks every configured source for offers, rates them, keeps what fits the
 * budget and alerts on what is actually good.
 *
 * One failing source never stops the scan - a blog going down should not cost
 * us the airline fares.
 */
final readonly class ScanForDeals
{
    /**
     * @param  iterable<DealSource>  $sources
     */
    public function __construct(
        private iterable $sources,
        private DealEvaluator $evaluator,
        private DealScorer $scorer,
        private WeekendGetaway $weekends,
        private DealRepository $repository,
        private DealNotifier $notifier,
        private LoggerInterface $logger,
    ) {}

    public function execute(SearchCriteria $criteria): ScanReport
    {
        $found = 0;
        $kept = 0;
        $alerted = 0;
        $failedSources = [];

        foreach ($this->sources as $source) {
            try {
                $deals = $source->findDeals($criteria);
            } catch (DealSourceUnavailable $exception) {
                $failedSources[] = $source->name();
                $this->logger->warning('Deal source failed during scan.', [
                    'source' => $source->name(),
                    'reason' => $exception->getMessage(),
                ]);

                continue;
            }

            $found += count($deals);

            foreach ($deals as $deal) {
                // The source has already told us what the route usually costs,
                // if it could; everything else is judged here.
                $deal = $deal
                    ->scoredWith($this->scorer->score($deal))
                    ->markedAsWeekendGetaway($this->weekends->matches($deal));

                if (! $this->evaluator->isWorthKeeping($deal) || ! $this->repository->store($deal)) {
                    continue;
                }

                $kept++;

                if ($this->evaluator->isWorthAlerting($deal)) {
                    $alerted++;
                    $this->notifier->notify($deal);
                }
            }
        }

        return new ScanReport($found, $kept, $alerted, $failedSources);
    }
}
