<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Deal\PruneStaleDeals;
use App\Application\Deal\ScanForDeals;
use App\Application\Deal\SearchCriteriaFactory;
use App\Domain\Deal\Port\DealNotifier;
use App\Domain\Deal\Port\DealRepository;
use App\Domain\Deal\Service\DealEvaluator;
use App\Domain\Deal\Service\DealScorer;
use App\Domain\Deal\Service\Steal;
use App\Domain\Deal\Service\WeekendGetaway;
use App\Domain\Deal\ValueObject\Money;
use App\Domain\Deal\ValueObject\PriceBand;
use App\Domain\Deal\ValueObject\StayWindow;
use App\Infrastructure\DealSource\Article\ArticleParser;
use App\Infrastructure\DealSource\Article\Fly4freeArticleParser;
use App\Infrastructure\DealSource\ConfiguredDealSources;
use App\Infrastructure\Notification\LogDealNotifier;
use App\Infrastructure\Persistence\EloquentDealRepository;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Binds the domain's ports to their adapters. This is the only place that
 * knows which implementation the application actually runs with.
 */
final class DealServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DealRepository::class, EloquentDealRepository::class);
        $this->app->singleton(DealNotifier::class, LogDealNotifier::class);
        // Default when a feed names no parser of its own.
        $this->app->singleton(ArticleParser::class, Fly4freeArticleParser::class);

        $this->app->singleton(DealEvaluator::class, fn (): DealEvaluator => new DealEvaluator(
            $this->money('deals.max_flight_price'),
            $this->money('deals.max_round_trip_price'),
            $this->money('deals.max_trip_price'),
            (int) config('deals.scoring.minimum_score'),
        ));

        $this->app->singleton(DealScorer::class, fn (): DealScorer => new DealScorer(
            $this->band('deals.scoring.flight'),
            $this->band('deals.scoring.round_trip'),
            $this->band('deals.scoring.trip_per_day'),
            $this->band('deals.scoring.trip_total'),
        ));

        $this->app->singleton(Steal::class, fn (): Steal => new Steal(
            (float) config('deals.steal.minimum_discount'),
            $this->money('deals.steal.ceiling'),
        ));

        $this->app->singleton(WeekendGetaway::class, fn (): WeekendGetaway => new WeekendGetaway(
            (int) config('deals.weekend.friday_from_hour'),
            (int) config('deals.weekend.saturday_until_hour'),
        ));

        $this->app->singleton(StayWindow::class, fn (): StayWindow => new StayWindow(
            (int) config('deals.stay.minimum_nights'),
            (int) config('deals.stay.maximum_nights'),
        ));

        $this->app->singleton(SearchCriteriaFactory::class, function (): SearchCriteriaFactory {
            /** @var list<string> $airports */
            $airports = config('deals.departure_airports', []);

            return new SearchCriteriaFactory(
                $airports,
                (int) config('deals.window_days'),
                $this->money('deals.max_flight_price'),
                $this->money('deals.max_round_trip_price'),
                $this->money('deals.max_trip_price'),
                $this->app->make(StayWindow::class),
            );
        });

        $this->app->when(PruneStaleDeals::class)
            ->needs('$retentionDays')
            ->give(fn (): int => (int) config('deals.retention_days'));

        $this->app->when(ScanForDeals::class)
            ->needs('$sources')
            ->give(fn (): array => $this->app->make(ConfiguredDealSources::class)->all());

        $this->app->when(LogDealNotifier::class)
            ->needs(LoggerInterface::class)
            ->give(fn (): LoggerInterface => $this->app->make('log'));
    }

    private function money(string $configKey): Money
    {
        return Money::fromDecimal((float) config($configKey), (string) config('deals.currency'));
    }

    private function band(string $configKey): PriceBand
    {
        return new PriceBand(
            $this->money($configKey.'.great'),
            $this->money($configKey.'.poor'),
        );
    }
}
