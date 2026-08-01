<?php

declare(strict_types=1);

namespace App\Infrastructure\DealSource;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpClient;
use Throwable;

/**
 * Wizz Air versions its API in the URL path and bumps it every few weeks. The
 * current version is embedded in the public site, so we scrape it once a day
 * and fall back to the last known good one.
 */
final readonly class WizzAirApiVersionResolver
{
    private const CACHE_KEY = 'wizzair.api-version';

    public function __construct(
        private HttpClient $http,
        private Cache $cache,
        private string $siteUrl,
        private string $fallbackVersion,
        private int $timeoutSeconds,
        private int $cacheTtlSeconds,
    ) {}

    public function resolve(): string
    {
        $cached = $this->cache->get(self::CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $version = $this->discover() ?? $this->fallbackVersion;

        $this->cache->put(self::CACHE_KEY, $version, $this->cacheTtlSeconds);

        return $version;
    }

    private function discover(): ?string
    {
        try {
            $response = $this->http->timeout($this->timeoutSeconds)->get($this->siteUrl);
        } catch (Throwable) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        if (preg_match('#be\.wizzair\.com/(\d+\.\d+\.\d+)#', $response->body(), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
