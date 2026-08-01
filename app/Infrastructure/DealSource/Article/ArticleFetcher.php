<?php

declare(strict_types=1);

namespace App\Infrastructure\DealSource\Article;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpClient;
use Throwable;

/**
 * Downloads an article page, once.
 *
 * A published offer does not change, and every scan sees the same feed items
 * again, so pages are cached - without it an hourly scan would re-download the
 * whole front page of two blogs all day.
 */
final readonly class ArticleFetcher
{
    public function __construct(
        private HttpClient $http,
        private Cache $cache,
        private string $userAgent,
        private int $timeoutSeconds,
        private int $cacheTtlSeconds,
    ) {}

    /**
     * Returns null when the article cannot be read - the offer is still worth
     * keeping, just without any detail behind it.
     */
    public function fetch(string $url): ?string
    {
        $key = 'deal-article:'.sha1($url);

        $cached = $this->cache->get($key);

        if (is_string($cached)) {
            return $cached === '' ? null : $cached;
        }

        $html = $this->download($url);

        $this->cache->put($key, $html ?? '', $this->cacheTtlSeconds);

        return $html;
    }

    private function download(string $url): ?string
    {
        try {
            $response = $this->http
                ->timeout($this->timeoutSeconds)
                ->withHeaders(['User-Agent' => $this->userAgent])
                ->get($url);
        } catch (Throwable) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $body = $response->body();

        return $body === '' ? null : $body;
    }
}
