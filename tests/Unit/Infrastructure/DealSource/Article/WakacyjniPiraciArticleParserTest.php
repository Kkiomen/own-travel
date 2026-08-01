<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\DealSource\Article;

use App\Domain\Deal\BoardType;
use App\Domain\Deal\ValueObject\TripDetails;
use App\Infrastructure\DealSource\Article\ArticleTextReader;
use App\Infrastructure\DealSource\Article\JsonLdReader;
use App\Infrastructure\DealSource\Article\TripAttributeReader;
use App\Infrastructure\DealSource\Article\WakacyjniPiraciArticleParser;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Runs against an article captured from wakacyjnipiraci.pl.
 */
final class WakacyjniPiraciArticleParserTest extends TestCase
{
    private WakacyjniPiraciArticleParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new WakacyjniPiraciArticleParser(
            new JsonLdReader,
            new ArticleTextReader,
            new TripAttributeReader,
        );
    }

    public function test_it_reads_the_offer_out_of_the_rich_text(): void
    {
        $details = $this->parseKreta();

        $this->assertSame(8, $details->days, '7 nocy is an eight-day trip.');
        $this->assertSame(BoardType::HalfBoard, $details->board);
        $this->assertSame(3, $details->hotelStars);
        $this->assertSame('Agrabella', $details->hotel);
    }

    /**
     * The whole point of opening the article: the offer flies from more than
     * one airport, and the headline never says so.
     */
    public function test_it_reads_every_airport_the_offer_flies_from(): void
    {
        $cities = $this->parseKreta()->departureCities;

        $this->assertContains('Gdańsk', $cities);
        $this->assertContains('Kraków', $cities);
        $this->assertContains('Warszawa', $cities);
    }

    /**
     * The dates are resolved to real days, with the year taken from when the
     * article was published - the wording never carries one.
     */
    public function test_it_resolves_the_dates_the_article_names(): void
    {
        $dates = $this->parseKreta()->dates;

        $this->assertNotEmpty($dates);

        $labels = array_map(static fn ($window): string => $window->label, $dates);
        $this->assertContains('31 sierpnia', $labels);

        $august = array_values(array_filter(
            $dates,
            static fn ($window): bool => $window->label === '31 sierpnia',
        ))[0];

        $this->assertSame('2026-08-31', $august->from->toDateString());
        $this->assertNull($august->to);
    }

    public function test_a_month_already_behind_the_article_belongs_to_next_year(): void
    {
        $details = $this->parser->parse(
            $this->richText('proponowany termin: 12 lutego'),
            'Wyjazd',
            CarbonImmutable::parse('2026-08-01'),
        );

        $this->assertSame('2027-02-12', $details->dates[0]->from->toDateString());
    }

    public function test_a_range_covers_every_day_between_its_ends(): void
    {
        $details = $this->parser->parse(
            $this->richText('proponowany termin: 19 - 22 września'),
            'Wyjazd',
            CarbonImmutable::parse('2026-08-01'),
        );

        $window = $details->dates[0];

        $this->assertSame('2026-09-19', $window->from->toDateString());
        $this->assertSame('2026-09-22', $window->to?->toDateString());
        $this->assertCount(4, $window->days());
    }

    public function test_it_keeps_the_offer_bullets_for_the_details_view(): void
    {
        $highlights = $this->parseKreta()->highlights;

        $this->assertNotEmpty($highlights);
        $this->assertContains('transfery z i na lotnisko', $highlights);
        $this->assertContains('7 nocy w 3* hotelu Agrabella', $highlights);
    }

    /**
     * Every post repeats the same small print; it is not part of the offer.
     */
    public function test_it_leaves_out_the_boilerplate(): void
    {
        foreach ($this->parseKreta()->highlights as $highlight) {
            $this->assertStringNotContainsStringIgnoringCase('SPRAWDŹ OFERTĘ', $highlight);
            $this->assertStringNotContainsStringIgnoringCase('Cena za osobę aktualna', $highlight);
        }
    }

    public function test_there_is_enough_to_open(): void
    {
        $this->assertTrue($this->parseKreta()->areWorthShowing());
    }

    public function test_it_gives_up_gracefully_on_an_empty_page(): void
    {
        $details = $this->parse('<html><body></body></html>', 'Wyjazd bez szczegółów');

        $this->assertNull($details->days);
        $this->assertSame([], $details->departureCities);
        $this->assertSame([], $details->dates);
        $this->assertSame([], $details->highlights);
    }

    private function parseKreta(): TripDetails
    {
        return $this->parse(
            (string) file_get_contents(__DIR__.'/../../../../Fixtures/Articles/piraci-kreta.html'),
            'Nie wracaj do rutyny 💙 leć do Grecji! 🏖️ Tydzień na Krecie z wyżywieniem za 2367 zł 🔥',
        );
    }

    /**
     * The captured articles date from August 2026; the dates in them carry no
     * year, so that is what they are read against.
     */
    private function parse(string $html, string $headline): TripDetails
    {
        return $this->parser->parse($html, $headline, CarbonImmutable::parse('2026-08-01'));
    }

    /**
     * The article ships as escaped rich-text JSON, so a fact has to be handed
     * over the way the page carries it.
     */
    private function richText(string $fact): string
    {
        return '<html><body><script>{\\"value\\":\\"'.$fact.'\\"}</script></body></html>';
    }
}
