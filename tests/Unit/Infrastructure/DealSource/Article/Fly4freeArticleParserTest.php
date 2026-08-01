<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\DealSource\Article;

use App\Domain\Deal\BoardType;
use App\Domain\Deal\ValueObject\TripDetails;
use App\Infrastructure\DealSource\Article\ArticleTextReader;
use App\Infrastructure\DealSource\Article\Fly4freeArticleParser;
use App\Infrastructure\DealSource\Article\JsonLdReader;
use App\Infrastructure\DealSource\Article\TripAttributeReader;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Runs against article pages captured from the live blogs.
 */
final class Fly4freeArticleParserTest extends TestCase
{
    private Fly4freeArticleParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new Fly4freeArticleParser(
            new JsonLdReader,
            new ArticleTextReader,
            new TripAttributeReader,
        );
    }

    public function test_it_reads_a_fly4free_package_holiday(): void
    {
        $details = $this->parse(
            $this->fixture('fly4free-egipt.html'),
            'Wakacje all inclusive w Egipcie od 2624 PLN 🍹🍉 Loty z 10 miast + 4* hotel przy plaży 🐠🐚',
        );

        $this->assertSame(7, $details->days);
        $this->assertSame(BoardType::AllInclusive, $details->board);
        $this->assertSame(4, $details->hotelStars);
        $this->assertTrue($details->areScorable());
    }

    /**
     * This offer is only ever described as "krótki wypad", so its length stays
     * unknown - the scorer falls back to the total price for offers like it.
     */
    public function test_it_reads_what_an_offer_states_and_no_more(): void
    {
        $details = $this->parse($this->fixture('fly4free-kalabria.html'), 'Lato w Kalabrii za 939 PLN');

        $this->assertNull($details->days);
        $this->assertFalse($details->areScorable());
        $this->assertSame(BoardType::Breakfast, $details->board);
        $this->assertSame(3, $details->hotelStars);
    }

    /**
     * Fly4free paste advertising widgets listing other operators' offers into
     * the article body. Reading a duration out of one of those would quietly
     * mis-rate the deal, so the body is never searched.
     */
    public function test_it_ignores_durations_belonging_to_adverts_in_the_body(): void
    {
        $html = <<<'HTML'
            <html>
                <head><meta name="description" content="Piza i wyspa Elba w jednej podróży"></head>
                <body>
                    <p>Zarzis od 2639 PLN na 7 dni (lotnisko wylotu: Katowice)</p>
                    <p>Hurghada od 1999 PLN na 13 dni. Reklama interaktywna, dane dostarczone przez Wakacje.pl</p>
                </body>
            </html>
            HTML;

        $details = $this->parse($html, 'Toskania w pigułce za 401 PLN');

        $this->assertNull($details->days);
    }

    public function test_it_reads_a_wakacyjni_piraci_package(): void
    {
        $details = $this->parse(
            $this->fixture('piraci-sardynia.html'),
            'Sardynia i jej turkusowe wody wzywają 💚 na tygodniowy odpoczynek 🌺',
        );

        // The article offers "7 noclegów" - seven nights, so eight days. An
        // explicit count in the text beats the vaguer "tygodniowy" headline.
        $this->assertSame(8, $details->days);

        // Wakacyjni Piraci render as a single-page app: the board badge lives
        // deep in the markup among unrelated offers, so it is left unread
        // rather than guessed at.
        $this->assertSame(BoardType::Unknown, $details->board);
    }

    public function test_it_gives_up_gracefully_on_an_empty_page(): void
    {
        $details = $this->parse('<html><body></body></html>', 'Wyjazd bez szczegółów');

        $this->assertNull($details->days);
        $this->assertSame(BoardType::Unknown, $details->board);
        $this->assertNull($details->hotelStars);
        $this->assertFalse($details->areScorable());
    }

    public function test_the_headline_is_read_even_when_the_page_says_otherwise(): void
    {
        $html = '<html><body><p>Hotel ma 250 pokoi, a obok reklama: Kreta na 12 dni.</p></body></html>';

        $details = $this->parse($html, 'Egipt na 10 dni za 2999 PLN');

        $this->assertSame(10, $details->days);
    }

    private function fixture(string $file): string
    {
        return (string) file_get_contents(__DIR__.'/../../../../Fixtures/Articles/'.$file);
    }

    /**
     * The captured articles date from August 2026; the dates in them carry no
     * year, so that is what they are read against.
     */
    private function parse(string $html, string $headline): TripDetails
    {
        return $this->parser->parse($html, $headline, CarbonImmutable::parse('2026-08-01'));
    }
}
