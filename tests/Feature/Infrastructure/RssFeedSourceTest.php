<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Domain\Deal\BoardType;
use App\Domain\Deal\DealType;
use App\Domain\Deal\Exception\DealSourceUnavailable;
use App\Domain\Deal\ValueObject\Airport;
use App\Domain\Deal\ValueObject\Money;
use App\Domain\Deal\ValueObject\SearchCriteria;
use App\Domain\Deal\ValueObject\StayWindow;
use App\Infrastructure\DealSource\Article\ArticleFetcher;
use App\Infrastructure\DealSource\Article\ArticleTextReader;
use App\Infrastructure\DealSource\Article\Fly4freeArticleParser;
use App\Infrastructure\DealSource\Article\JsonLdReader;
use App\Infrastructure\DealSource\Article\TripAttributeReader;
use App\Infrastructure\DealSource\Parser\OfferPriceParser;
use App\Infrastructure\DealSource\Parser\OfferTypeClassifier;
use App\Infrastructure\DealSource\RssFeedSource;
use Carbon\CarbonImmutable;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Runs against a feed and article pages captured from fly4free.pl.
 */
final class RssFeedSourceTest extends TestCase
{
    public function test_it_turns_priced_headlines_into_deals(): void
    {
        Http::fake(['*' => Http::response($this->fixture('Rss/fly4free.xml'))]);

        $deals = $this->source()->findDeals($this->criteria());

        $this->assertNotEmpty($deals);

        foreach ($deals as $deal) {
            $this->assertSame('fly4free', $deal->source);
            $this->assertGreaterThan(0, $deal->price->minorUnits);
            $this->assertStringStartsWith('http', $deal->url);
            $this->assertNull($deal->routeLabel());
        }
    }

    public function test_it_reads_the_article_behind_a_trip(): void
    {
        Http::fake([
            'fly4free.pl/feed*' => Http::response($this->feedWith([
                '<title>Wakacje all inclusive w Egipcie od 2624 PLN</title><link>https://www.fly4free.pl/egipt/</link>',
            ])),
            'fly4free.pl/egipt*' => Http::response($this->fixture('Articles/fly4free-egipt.html')),
        ]);

        $deals = $this->source()->findDeals($this->criteria());

        $this->assertCount(1, $deals);
        $this->assertNotNull($deals[0]->trip);
        $this->assertSame(7, $deals[0]->trip->days);
        $this->assertSame(BoardType::AllInclusive, $deals[0]->trip->board);
        $this->assertSame(4, $deals[0]->trip->hotelStars);
    }

    public function test_it_does_not_open_articles_behind_flights(): void
    {
        Http::fake(['*' => Http::response($this->feedWith([
            '<title>Tanie loty do Barcelony za 79 PLN</title><link>https://www.fly4free.pl/barcelona/</link>',
        ]))]);

        $deals = $this->source()->findDeals($this->criteria());

        $this->assertSame(DealType::Flight, $deals[0]->type);
        $this->assertNull($deals[0]->trip);
        Http::assertSentCount(1);
    }

    public function test_an_article_is_downloaded_once_across_scans(): void
    {
        Http::fake([
            'fly4free.pl/feed*' => Http::response($this->feedWith([
                '<title>Egipt na 10 dni za 2999 PLN</title><link>https://www.fly4free.pl/egipt/</link>',
            ])),
            'fly4free.pl/egipt*' => Http::response($this->fixture('Articles/fly4free-egipt.html')),
        ]);

        $source = $this->source();
        $source->findDeals($this->criteria());
        $source->findDeals($this->criteria());

        // Two feed requests, but the article was only fetched for the first.
        Http::assertSentCount(3);
    }

    public function test_a_trip_survives_an_unreadable_article(): void
    {
        Http::fake([
            'fly4free.pl/feed*' => Http::response($this->feedWith([
                '<title>Tydzień na Rodos za 2518 PLN</title><link>https://www.fly4free.pl/rodos/</link>',
            ])),
            'fly4free.pl/rodos*' => Http::response('', 404),
        ]);

        $deals = $this->source()->findDeals($this->criteria());

        $this->assertCount(1, $deals);
        $this->assertNotNull($deals[0]->trip);
        $this->assertNull($deals[0]->trip->days);
        $this->assertSame(251800, $deals[0]->price->minorUnits);
    }

    public function test_it_skips_headlines_without_a_price(): void
    {
        Http::fake(['*' => Http::response($this->feedWith([
            '<title>Nowe połączenie z Krakowa do Rzymu</title><link>https://example.test/a</link>',
            '<title>Tanie loty do Barcelony za 79 PLN</title><link>https://example.test/b</link>',
        ]))]);

        $deals = $this->source()->findDeals($this->criteria());

        $this->assertCount(1, $deals);
        $this->assertSame('https://example.test/b', $deals[0]->url);
        $this->assertSame(7900, $deals[0]->price->minorUnits);
    }

    public function test_it_walks_the_archive_behind_the_feed(): void
    {
        Http::fake([
            'fly4free.pl/feed/?paged=2*' => Http::response($this->feedWith([
                '<title>Tanie loty do Rzymu za 99 PLN</title><link>https://www.fly4free.pl/rzym/</link>',
            ])),
            'fly4free.pl/feed/?paged=3*' => Http::response($this->feedWith([
                '<title>Tanie loty do Aten za 119 PLN</title><link>https://www.fly4free.pl/ateny/</link>',
            ])),
            'fly4free.pl/feed*' => Http::response($this->feedWith([
                '<title>Tanie loty do Barcelony za 79 PLN</title><link>https://www.fly4free.pl/barcelona/</link>',
            ])),
        ]);

        $deals = $this->source(pages: 3)->findDeals($this->criteria());

        $this->assertCount(3, $deals);
        $this->assertSame(
            ['https://www.fly4free.pl/barcelona/', 'https://www.fly4free.pl/rzym/', 'https://www.fly4free.pl/ateny/'],
            array_map(static fn ($deal): string => $deal->url, $deals),
        );
    }

    public function test_an_offer_that_shifted_between_pages_is_only_taken_once(): void
    {
        // A post moves down the archive as new ones are published, so the same
        // link can turn up on two pages of one walk.
        Http::fake(['*' => Http::response($this->feedWith([
            '<title>Tanie loty do Barcelony za 79 PLN</title><link>https://www.fly4free.pl/barcelona/</link>',
        ]))]);

        $deals = $this->source(pages: 4)->findDeals($this->criteria());

        $this->assertCount(1, $deals);
    }

    public function test_it_stops_where_the_archive_runs_out(): void
    {
        Http::fake([
            'fly4free.pl/feed/?paged=2*' => Http::response($this->feedWith([])),
            'fly4free.pl/feed*' => Http::response($this->feedWith([
                '<title>Tanie loty do Barcelony za 79 PLN</title><link>https://www.fly4free.pl/barcelona/</link>',
            ])),
        ]);

        $deals = $this->source(pages: 10)->findDeals($this->criteria());

        $this->assertCount(1, $deals);
        // The front page, the empty one, and nothing after it.
        Http::assertSentCount(2);
    }

    public function test_a_page_past_the_end_of_the_archive_is_not_an_outage(): void
    {
        Http::fake([
            'fly4free.pl/feed/?paged=2*' => Http::response('', 404),
            'fly4free.pl/feed*' => Http::response($this->feedWith([
                '<title>Tanie loty do Barcelony za 79 PLN</title><link>https://www.fly4free.pl/barcelona/</link>',
            ])),
        ]);

        $deals = $this->source(pages: 5)->findDeals($this->criteria());

        $this->assertCount(1, $deals);
    }

    public function test_it_does_not_open_the_article_behind_an_offer_over_budget(): void
    {
        Http::fake(['*' => Http::response($this->feedWith([
            '<title>Tydzień na Malediwach w 5* hotelu za 9999 PLN</title><link>https://www.fly4free.pl/malediwy/</link>',
        ]))]);

        $deals = $this->source()->findDeals($this->criteria(Money::fromDecimal(2500, 'PLN')));

        // The offer is still reported - the domain decides what to do with it -
        // but walking the archive must not cost a request per hopeless offer.
        $this->assertCount(1, $deals);
        $this->assertSame(DealType::Trip, $deals[0]->type);
        $this->assertNull($deals[0]->trip);
        Http::assertSentCount(1);
    }

    public function test_a_package_that_includes_flights_is_a_trip_not_a_flight(): void
    {
        Http::fake([
            'fly4free.pl/feed*' => Http::response($this->feedWith([
                '<title>Chorwacja 😍 Loty i 4* hotel za 579 PLN</title><link>https://www.fly4free.pl/chorwacja/</link>',
            ])),
            'fly4free.pl/chorwacja*' => Http::response($this->fixture('Articles/fly4free-egipt.html')),
        ]);

        $deals = $this->source()->findDeals($this->criteria());

        // Priced as a flight it would be over the one-way gate and thrown away,
        // even though 579 PLN buys a week away.
        $this->assertSame(DealType::Trip, $deals[0]->type);
        $this->assertSame(57900, $deals[0]->price->minorUnits);
    }

    public function test_it_reports_the_source_as_unavailable_on_a_failed_request(): void
    {
        Http::fake(['*' => Http::response('', 429)]);

        $this->expectException(DealSourceUnavailable::class);

        $this->source()->findDeals($this->criteria());
    }

    public function test_it_reports_the_source_as_unavailable_on_broken_xml(): void
    {
        Http::fake(['*' => Http::response('<rss><channel><item>')]);

        $this->expectException(DealSourceUnavailable::class);

        $this->source()->findDeals($this->criteria());
    }

    public function test_it_identifies_itself_to_the_blog(): void
    {
        Http::fake(['*' => Http::response($this->feedWith([]))]);

        $this->source()->findDeals($this->criteria());

        Http::assertSent(fn ($request): bool => $request->hasHeader('User-Agent', 'TravelBot/1.0'));
    }

    private function source(int $pages = 1): RssFeedSource
    {
        $http = $this->app->make(HttpClient::class);

        /** @var CacheRepository $cache */
        $cache = Cache::store('array');

        return new RssFeedSource(
            $http,
            new OfferPriceParser,
            new OfferTypeClassifier,
            new ArticleFetcher($http, $cache, 'TravelBot/1.0', 5, 60),
            new Fly4freeArticleParser(new JsonLdReader, new ArticleTextReader, new TripAttributeReader),
            'fly4free',
            'https://www.fly4free.pl/feed/',
            'TravelBot/1.0',
            5,
            $pages,
        );
    }

    /**
     * @param  list<string>  $items
     */
    private function feedWith(array $items): string
    {
        $body = implode('', array_map(static fn (string $item): string => "<item>{$item}</item>", $items));

        return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel>'.$body.'</channel></rss>';
    }

    private function criteria(?Money $maxTripPrice = null): SearchCriteria
    {
        $from = CarbonImmutable::parse('2026-08-01');

        return new SearchCriteria(
            [Airport::fromIataCode('KRK')],
            $from,
            $from->addDays(90),
            Money::fromDecimal(150, 'PLN'),
            Money::fromDecimal(600, 'PLN'),
            $maxTripPrice ?? Money::fromDecimal(3000, 'PLN'),
            new StayWindow(2, 10),
        );
    }

    private function fixture(string $path): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/'.$path));
    }
}
