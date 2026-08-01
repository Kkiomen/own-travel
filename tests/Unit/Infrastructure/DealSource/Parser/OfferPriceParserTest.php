<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\DealSource\Parser;

use App\Infrastructure\DealSource\Parser\OfferPriceParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OfferPriceParserTest extends TestCase
{
    private OfferPriceParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new OfferPriceParser;
    }

    #[DataProvider('headlinesWithPrices')]
    public function test_it_reads_the_price_out_of_a_headline(string $headline, int $expectedMinorUnits): void
    {
        $price = $this->parser->parse($headline);

        $this->assertNotNull($price);
        $this->assertSame($expectedMinorUnits, $price->minorUnits);
        $this->assertSame('PLN', $price->currency);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function headlinesWithPrices(): iterable
    {
        yield 'plain PLN' => ['Tanie loty do Barcelony za 79 PLN!', 7900];
        yield 'zloty sign' => ['Wenecja w listopadzie za 129 zł', 12900];
        yield 'thousands with a space' => ['Tydzień z all inclusive za 2 518 PLN', 251800];
        yield 'thousands without a space' => ['Tydzień z all inclusive za 2518 PLN', 251800];
        yield 'decimal comma' => ['Lot do Rzymu za 89,99 zł', 8999];
        yield 'starting from' => ['Loty do Grecji od 149 PLN', 14900];
        yield 'only keyword' => ['Kreta tylko 899 zł', 89900];

        // Wakacyjni Piraci phrase almost every headline this way, and every one
        // of them used to be dropped for want of a "za".
        yield 'below' => ['Rzym na sierpień poniżej 1000 zł 🤯', 100000];
        yield 'below, per person' => ['Minorka dla 4 osób 🏝️ poniżej 1600 zł/os 🤩', 160000];
        yield 'just under' => ['Bułgaria all inclusive w 4* hotelu 🏩 Niecałe 2000 zł/os', 200000];
        yield 'for the price of' => ['Wycieczka na Sycylię w superniskiej cenie 343 PLN', 34300];
        yield 'emphasised' => ['Odkryj Maltę za jedyne 561 PLN 🤩', 56100];
        yield 'emphasised, approximate' => ['Wyspy Kanaryjskie od około 1 200 zł', 120000];
    }

    #[DataProvider('headlinesWithoutPrices')]
    public function test_it_returns_nothing_when_there_is_no_polish_price(string $headline): void
    {
        $this->assertNull($this->parser->parse($headline));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function headlinesWithoutPrices(): iterable
    {
        yield 'no price at all' => ['Nowe połączenie z Krakowa do Rzymu'];
        yield 'foreign currency' => ['Loty do Rzymu za 19 EUR'];
        yield 'bare number' => ['Barcelona 79'];

        // Articles *about* prices are not offers: reading them as offers puts
        // things nobody can book on the dashboard.
        yield 'a price the article is about' => ['1300 zł za dobę. Sprawdziłem, jak wyglądają wakacje all inclusive w Polsce!'];
        yield 'a price in a news story' => ['1100 zł za 4 h w samolotowej „kuszetce”. Pierwsi pasażerowie już kupują'];
    }
}
