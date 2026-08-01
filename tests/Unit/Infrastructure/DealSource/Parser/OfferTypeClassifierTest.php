<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\DealSource\Parser;

use App\Domain\Deal\DealType;
use App\Infrastructure\DealSource\Parser\OfferTypeClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OfferTypeClassifierTest extends TestCase
{
    #[DataProvider('headlines')]
    public function test_it_tells_flights_from_trips(string $headline, DealType $expected): void
    {
        $this->assertSame($expected, (new OfferTypeClassifier)->classify($headline));
    }

    /**
     * @return iterable<string, array{string, DealType}>
     */
    public static function headlines(): iterable
    {
        yield 'plural flights' => ['Tanie loty do Barcelony za 79 PLN', DealType::Flight];
        yield 'single flight' => ['Lot do Rzymu za 89 zł', DealType::Flight];
        yield 'connection' => ['Przelot do Aten za 120 PLN', DealType::Flight];
        yield 'package holiday' => ['Tydzień z all inclusive na Rodos za 2518 PLN', DealType::Trip];
        yield 'hotel only' => ['Hotel 4* w Chorwacji za 1200 zł', DealType::Trip];

        // Packages sell the flights as part of the deal. Read as flights they
        // were priced against the one-way gate and thrown away wholesale.
        yield 'flights and a hotel' => ['Chorwacja 😍 Loty i 4⭐hotel za 579 PLN', DealType::Trip];
        yield 'flights in the price' => ['Wycieczka na Korfu za 644 PLN. W cenie loty + hotel', DealType::Trip];
        yield 'flights and beds' => ['Loty do Nicei + 3 noclegi w Cannes za 1049 PLN', DealType::Trip];
        yield 'flights and board' => ['Loty i 3 dni ze śniadaniami w Noto za 939 PLN', DealType::Trip];
        yield 'flights and nights' => ['Loty z Warszawy i 7 nocy w hotelu za 3445 PLN', DealType::Trip];
        yield 'flights and days' => ['Loty z Warszawy i 4 dni w Alcudii za 999 PLN', DealType::Trip];

        // A seat and nothing else stays a flight, however it is dressed up.
        yield 'a batch of seats' => ['Zbiór lotów w jedną stronę od 65 PLN ✈️', DealType::Flight];
        yield 'a long-haul seat' => ['Loty Etihad Airways za 2471 PLN ✈️🧳', DealType::Flight];
        yield 'a new route' => ['Nowa trasa PLL LOT ✈️ Hanoi BEZPOŚREDNIO z Warszawy za 3472 PLN', DealType::Flight];
    }
}
