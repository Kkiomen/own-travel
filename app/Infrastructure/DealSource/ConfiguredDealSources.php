<?php

declare(strict_types=1);

namespace App\Infrastructure\DealSource;

use App\Domain\Deal\Port\DealSource;
use App\Domain\Deal\Service\RoundTripPairing;
use App\Infrastructure\DealSource\Article\ArticleFetcher;
use App\Infrastructure\DealSource\Article\ArticleParser;
use App\Infrastructure\DealSource\Parser\OfferPriceParser;
use App\Infrastructure\DealSource\Parser\OfferTypeClassifier;
use App\Infrastructure\DealSource\Ryanair\FareReader;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory as HttpClient;

/**
 * Builds the set of sources a scan runs against, from configuration.
 *
 * Adding a site means adding an adapter and one entry here - and because the
 * list is assembled when a scan asks for it rather than when the container
 * boots, configuration can be changed (or overridden in a test) at any point.
 */
final readonly class ConfiguredDealSources
{
    public function __construct(
        private Config $config,
        private HttpClient $http,
        private Cache $cache,
        private OfferPriceParser $priceParser,
        private OfferTypeClassifier $typeClassifier,
        private ArticleParser $articleParser,
        private RoundTripPairing $pairing,
        private Container $container,
    ) {}

    /**
     * @return list<DealSource>
     */
    public function all(): array
    {
        return [
            ...$this->airlines(),
            ...$this->feeds(),
        ];
    }

    /**
     * @return list<DealSource>
     */
    private function airlines(): array
    {
        $sources = [];

        if ((bool) $this->config->get('deals.ryanair.enabled')) {
            $reader = new FareReader;

            $sources[] = new RyanairFareFinderSource(
                $this->http,
                $reader,
                (string) $this->config->get('deals.ryanair.base_url'),
                (string) $this->config->get('deals.ryanair.booking_url'),
                (string) $this->config->get('deals.ryanair.market'),
                $this->timeout(),
            );

            $sources[] = new RyanairRoundTripSource(
                $this->http,
                $reader,
                RyanairRoundTripSource::NAME,
                (string) $this->config->get('deals.ryanair.base_url'),
                (string) $this->config->get('deals.ryanair.booking_url'),
                (string) $this->config->get('deals.ryanair.market'),
                $this->timeout(),
            );

            // Real per-day prices on the routes we fly, paired here rather
            // than by the airline: the cheapest pairing it would return is
            // almost never the Friday-to-Sunday one, and asking it to filter
            // by weekday only ever returns its twenty cheapest.

            /** @var list<string> $pairingAirports */
            $pairingAirports = $this->config->get('deals.ryanair.pairing.airports', []);

            $sources[] = new RyanairRoutePairingSource(
                $this->http,
                $reader,
                $this->pairing,
                (string) $this->config->get('deals.ryanair.base_url'),
                (string) $this->config->get('deals.ryanair.booking_url'),
                (string) $this->config->get('deals.ryanair.market'),
                $this->timeout(),
                $pairingAirports,
                (int) $this->config->get('deals.ryanair.pairing.routes_per_airport'),
            );
        }

        if ((bool) $this->config->get('deals.wizzair.enabled')) {
            $versionResolver = new WizzAirApiVersionResolver(
                $this->http,
                $this->cache,
                (string) $this->config->get('deals.wizzair.site_url'),
                (string) $this->config->get('deals.wizzair.fallback_version'),
                $this->timeout(),
                (int) $this->config->get('deals.wizzair.version_cache_ttl'),
            );

            $sources[] = new WizzAirTimetableSource(
                $this->http,
                $versionResolver,
                new WizzAirStationDirectory(
                    $this->http,
                    $this->cache,
                    $versionResolver,
                    (string) $this->config->get('deals.wizzair.api_url'),
                    (string) $this->config->get('deals.wizzair.language'),
                    $this->timeout(),
                    (int) $this->config->get('deals.wizzair.stations_cache_ttl'),
                ),
                $this->pairing,
                (string) $this->config->get('deals.wizzair.api_url'),
                (string) $this->config->get('deals.wizzair.booking_url'),
                $this->routes(),
                $this->timeout(),
                (int) $this->config->get('deals.wizzair.max_window_days'),
            );
        }

        return $sources;
    }

    /**
     * @return list<DealSource>
     */
    private function feeds(): array
    {
        /** @var list<array{name: string, url: string, parser?: string, enabled?: bool, pages?: int, page_query?: string}> $feeds */
        $feeds = $this->config->get('deals.feeds', []);

        $sources = [];

        foreach ($feeds as $feed) {
            if (! (bool) ($feed['enabled'] ?? true)) {
                continue;
            }

            $sources[] = new RssFeedSource(
                $this->http,
                $this->priceParser,
                $this->typeClassifier,
                $this->articleFetcher(),
                $this->parserFor($feed),
                $feed['name'],
                $feed['url'],
                (string) $this->config->get('deals.http.user_agent'),
                $this->timeout(),
                (int) ($feed['pages'] ?? 1),
                (string) ($feed['page_query'] ?? 'paged'),
            );
        }

        return $sources;
    }

    /**
     * @return array<string, list<string>>
     */
    private function routes(): array
    {
        /** @var array<string, list<string>> $routes */
        $routes = $this->config->get('deals.wizzair.routes', []);

        return $routes;
    }

    /**
     * @param  array{name: string, url: string, parser?: string, enabled?: bool, pages?: int, page_query?: string}  $feed
     */
    private function parserFor(array $feed): ArticleParser
    {
        $parser = $feed['parser'] ?? null;

        return is_string($parser)
            ? $this->container->make($parser)
            : $this->articleParser;
    }

    private function articleFetcher(): ArticleFetcher
    {
        return new ArticleFetcher(
            $this->http,
            $this->cache,
            (string) $this->config->get('deals.http.user_agent'),
            $this->timeout(),
            (int) $this->config->get('deals.articles.cache_ttl'),
        );
    }

    private function timeout(): int
    {
        return (int) $this->config->get('deals.http.timeout');
    }
}
