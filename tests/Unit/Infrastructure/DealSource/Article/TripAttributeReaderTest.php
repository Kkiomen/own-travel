<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\DealSource\Article;

use App\Domain\Deal\BoardType;
use App\Infrastructure\DealSource\Article\TripAttributeReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TripAttributeReaderTest extends TestCase
{
    private TripAttributeReader $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reader = new TripAttributeReader;
    }

    #[DataProvider('durations')]
    public function test_it_reads_how_long_a_trip_lasts(string $text, ?int $expected): void
    {
        $this->assertSame($expected, $this->reader->days($text));
    }

    /**
     * @return iterable<string, array{string, int|null}>
     */
    public static function durations(): iterable
    {
        yield 'days' => ['Egipt na 10 dni za 2999 PLN', 10];
        yield 'adjective' => ['8-dniowy wyjazd do Turcji', 8];
        yield 'a week spelled out' => ['Tydzień w Egipcie z all inclusive', 7];
        yield 'weekly adjective' => ['Tygodniowy odpoczynek na Sardynii', 7];
        yield 'two weeks' => ['2 tygodnie na Krecie', 14];
        yield 'nights become days' => ['7 nocy w 4* hotelu', 8];
        yield 'nothing to go on' => ['Wakacje w Egipcie za 2624 PLN', null];
        yield 'absurd length is ignored' => ['365 dni dookoła świata', null];
    }

    #[DataProvider('boards')]
    public function test_it_reads_the_board(string $text, BoardType $expected): void
    {
        $this->assertSame($expected, $this->reader->board($text));
    }

    /**
     * @return iterable<string, array{string, BoardType}>
     */
    public static function boards(): iterable
    {
        yield 'all inclusive' => ['Tydzień z all inclusive', BoardType::AllInclusive];
        yield 'hyphenated' => ['Hotel all-inclusive przy plaży', BoardType::AllInclusive];
        yield 'full board' => ['Pełne wyżywienie w cenie', BoardType::FullBoard];
        yield 'half board' => ['Pobyt z HB', BoardType::HalfBoard];
        yield 'breakfast' => ['Nocleg ze śniadaniem', BoardType::Breakfast];
        yield 'room only' => ['Tylko nocleg, bez wyżywienia', BoardType::RoomOnly];
        yield 'unsaid' => ['Wyjazd do Barcelony', BoardType::Unknown];
    }

    #[DataProvider('stars')]
    public function test_it_reads_the_hotel_standard(string $text, ?int $expected): void
    {
        $this->assertSame($expected, $this->reader->hotelStars($text));
    }

    /**
     * @return iterable<string, array{string, int|null}>
     */
    public static function stars(): iterable
    {
        yield 'asterisk' => ['4* hotel przy plaży', 4];
        yield 'spaced asterisk' => ['Hotel 5 * z basenem', 5];
        yield 'spelled out' => ['3-gwiazdkowy hotel', 3];
        yield 'not mentioned' => ['Apartament w centrum', null];
    }
}
