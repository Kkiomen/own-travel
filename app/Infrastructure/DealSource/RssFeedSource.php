<?php

declare(strict_types=1);

namespace App\Infrastructure\DealSource;

use App\Domain\Deal\Deal;
use App\Domain\Deal\DealType;
use App\Domain\Deal\Exception\DealSourceUnavailable;
use App\Domain\Deal\Port\DealSource;
use App\Domain\Deal\ValueObject\Money;
use App\Domain\Deal\ValueObject\SearchCriteria;
use App\Domain\Deal\ValueObject\TripDetails;
use App\Infrastructure\DealSource\Article\ArticleFetcher;
use App\Infrastructure\DealSource\Article\ArticleParser;
use App\Infrastructure\DealSource\Parser\OfferPriceParser;
use App\Infrastructure\DealSource\Parser\OfferTypeClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpClient;
use SimpleXMLElement;
use Throwable;

/**
 * Reads a deal blog's RSS feed and then the articles behind it.
 *
 * The feed alone gives a headline and a price, which is not enough to tell a
 * bargain from a short trip that merely looks cheap - the length, board and
 * hotel standard live in the article, so each trip is opened and read.
 *
 * One instance per feed: the blogs differ in content, not in protocol.
 */
final readonly class RssFeedSource implements DealSource
{
    public function __construct(
        private HttpClient $http,
        private OfferPriceParser $priceParser,
        private OfferTypeClassifier $typeClassifier,
        private ArticleFetcher $articleFetcher,
        private ArticleParser $articleParser,
        private string $name,
        private string $feedUrl,
        private string $userAgent,
        private int $timeoutSeconds,
        /**
         * How many pages of the feed to walk. A feed's front page is a day or
         * two of posts, which is a handful of trips - the archive behind it is
         * where the rest of them are. Feeds that do not paginate stay at 1.
         */
        private int $pages = 1,
        /** The query parameter that asks for an older page. */
        private string $pageQuery = 'paged',
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function findDeals(SearchCriteria $criteria): array
    {
        $deals = [];
        $seen = [];

        for ($page = 1; $page <= max(1, $this->pages); $page++) {
            $body = $this->fetchPage($page);

            // Past the end of the archive the blog stops answering. That is not
            // an outage - it is simply where its history runs out.
            if ($body === null) {
                break;
            }

            $items = $this->itemsIn($body);

            if ($items === []) {
                break;
            }

            foreach ($items as $item) {
                $url = trim((string) $item->link);

                // A post shifts between pages as new ones are published, so the
                // same offer can turn up twice mid-walk.
                if ($url === '' || isset($seen[$url])) {
                    continue;
                }

                $seen[$url] = true;

                $deal = $this->toDeal($item, $criteria);

                if ($deal instanceof Deal) {
                    $deals[] = $deal;
                }
            }
        }

        return $deals;
    }

    /**
     * Returns null once the feed stops answering. The first page failing means
     * the blog is down and there is nothing to report; a later one only means
     * we have read everything it has.
     */
    private function fetchPage(int $page): ?string
    {
        $response = $this->http
            ->timeout($this->timeoutSeconds)
            ->withHeaders(['User-Agent' => $this->userAgent])
            ->get($this->urlForPage($page));

        if ($response->failed()) {
            if ($page === 1) {
                throw DealSourceUnavailable::forSource(
                    $this->name,
                    sprintf('HTTP %d for the feed', $response->status()),
                );
            }

            return null;
        }

        return $response->body();
    }

    private function urlForPage(int $page): string
    {
        if ($page === 1) {
            return $this->feedUrl;
        }

        $separator = str_contains($this->feedUrl, '?') ? '&' : '?';

        return $this->feedUrl.$separator.$this->pageQuery.'='.$page;
    }

    /**
     * @return list<SimpleXMLElement>
     */
    private function itemsIn(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $feed = new SimpleXMLElement($xml);
        } catch (Throwable $exception) {
            throw DealSourceUnavailable::forSource($this->name, 'the feed is not valid XML', $exception);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $items = [];

        foreach ($feed->channel->item ?? [] as $item) {
            $items[] = $item;
        }

        return $items;
    }

    private function toDeal(SimpleXMLElement $item, SearchCriteria $criteria): ?Deal
    {
        $title = trim((string) $item->title);
        $url = trim((string) $item->link);

        if ($title === '' || $url === '') {
            return null;
        }

        // Without a price there is nothing to judge, so the item is skipped.
        $price = $this->priceParser->parse($title);

        if ($price === null) {
            return null;
        }

        $type = $this->typeClassifier->classify($title);
        $publishedAt = $this->toPublishedAt((string) $item->pubDate);

        return Deal::fromFeed(
            source: $this->name,
            type: $type,
            title: $title,
            price: $price,
            url: $url,
            publishedAt: $publishedAt,
            trip: $type === DealType::Trip && $this->isAffordable($price, $criteria)
                ? $this->readTripDetails($url, $title, $publishedAt)
                : null,
        );
    }

    /**
     * Walking the archive multiplies the articles behind it, and an offer over
     * budget is dropped whatever the article says - so it is not worth a
     * request to somebody's blog.
     */
    private function isAffordable(Money $price, SearchCriteria $criteria): bool
    {
        return $price->isAtMost($criteria->maxTripPrice);
    }

    /**
     * Flights are not opened: their article holds nothing the headline did not
     * already say, and every fetch is a request to somebody's blog.
     */
    private function readTripDetails(string $url, string $title, ?CarbonImmutable $publishedAt): TripDetails
    {
        $html = $this->articleFetcher->fetch($url);

        if ($html === null) {
            return TripDetails::unknown();
        }

        // Undated feed items are rare; reading their dates against today is
        // closer than refusing to read them at all.
        return $this->articleParser->parse($html, $title, $publishedAt ?? CarbonImmutable::now());
    }

    private function toPublishedAt(string $pubDate): ?CarbonImmutable
    {
        if (trim($pubDate) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($pubDate);
        } catch (Throwable) {
            return null;
        }
    }
}
