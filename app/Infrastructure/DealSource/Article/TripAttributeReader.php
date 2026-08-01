<?php

declare(strict_types=1);

namespace App\Infrastructure\DealSource\Article;

use App\Domain\Deal\BoardType;
use App\Domain\Deal\ValueObject\TravelWindow;
use Carbon\CarbonImmutable;

/**
 * The Polish phrasing these blogs use for length, board, standard, departure
 * airports and dates is the same regardless of who wrote it, so the per-blog
 * parsers share this. Where the text comes from is their business.
 */
final readonly class TripAttributeReader
{
    private const MONTHS = 'stycznia|lutego|marca|kwietnia|maja|czerwca|lipca|sierpnia|września|wrzesnia|października|pazdziernika|listopada|grudnia';

    private const MONTH_NUMBERS = [
        'stycznia' => 1, 'lutego' => 2, 'marca' => 3, 'kwietnia' => 4,
        'maja' => 5, 'czerwca' => 6, 'lipca' => 7, 'sierpnia' => 8,
        'września' => 9, 'wrzesnia' => 9, 'października' => 10, 'pazdziernika' => 10,
        'listopada' => 11, 'grudnia' => 12,
    ];

    /**
     * Airports these blogs fly from, as they decline them.
     */
    private const AIRPORT_CITIES = [
        'Gdańska' => 'Gdańsk', 'Krakowa' => 'Kraków', 'Warszawy' => 'Warszawa',
        'Katowic' => 'Katowice', 'Wrocławia' => 'Wrocław', 'Poznania' => 'Poznań',
        'Rzeszowa' => 'Rzeszów', 'Szczecina' => 'Szczecin', 'Bydgoszczy' => 'Bydgoszcz',
        'Łodzi' => 'Łódź', 'Lublina' => 'Lublin', 'Modlina' => 'Modlin',
        'Berlina' => 'Berlin', 'Pragi' => 'Praga',
    ];

    /**
     * Destinations that turn up often enough to be worth naming properly.
     */
    private const PLACES = [
        'Krecie' => 'Kreta', 'Kretę' => 'Kreta', 'Grecji' => 'Grecja', 'Grecję' => 'Grecja',
        'Egipcie' => 'Egipt', 'Egiptu' => 'Egipt', 'Sardynii' => 'Sardynia', 'Sardynię' => 'Sardynia',
        'Kalabrii' => 'Kalabria', 'Rodos' => 'Rodos', 'Teneryfie' => 'Teneryfa', 'Teneryfę' => 'Teneryfa',
        'Cyprze' => 'Cypr', 'Cypr' => 'Cypr', 'Malcie' => 'Malta', 'Maltę' => 'Malta',
        'Hiszpanii' => 'Hiszpania', 'Włoszech' => 'Włochy', 'Portugalii' => 'Portugalia',
        'Turcji' => 'Turcja', 'Tunezji' => 'Tunezja', 'Maroku' => 'Maroko',
        'Albanii' => 'Albania', 'Chorwacji' => 'Chorwacja', 'Bułgarii' => 'Bułgaria',
        'Barcelonie' => 'Barcelona', 'Barcelony' => 'Barcelona', 'Rzymie' => 'Rzym', 'Rzymu' => 'Rzym',
        'Lizbonie' => 'Lizbona', 'Lizbony' => 'Lizbona', 'Paryżu' => 'Paryż', 'Paryża' => 'Paryż',
        'Londynie' => 'Londyn', 'Londynu' => 'Londyn', 'Madrycie' => 'Madryt', 'Madrytu' => 'Madryt',
        'Toskanii' => 'Toskania', 'Sycylii' => 'Sycylia', 'Majorce' => 'Majorka', 'Majorkę' => 'Majorka',
    ];

    /**
     * Reads the length of the trip in days.
     *
     * "Tydzień" and "tygodniowy" mean seven days; nights are turned into days
     * because a seven-night stay is an eight-day trip.
     */
    public function days(string $text): ?int
    {
        if (preg_match('/(\d{1,2})\s*(?:-|\s)?\s*dni(?:owy|owa|owe)?\b/iu', $text, $matches) === 1) {
            return $this->withinReason((int) $matches[1]);
        }

        if (preg_match('/(\d{1,2})\s*(?:noc(?:e|y|ledz|legów|legi)?)\b/iu', $text, $matches) === 1) {
            return $this->withinReason((int) $matches[1] + 1);
        }

        if (preg_match('/(\d{1,2})\s*tygodni(?:e|owy|owa)?\b/iu', $text, $matches) === 1) {
            return $this->withinReason((int) $matches[1] * 7);
        }

        if (preg_match('/\b(tydzie(?:ń|n)|tygodniow(?:y|a|e))\b/iu', $text) === 1) {
            return 7;
        }

        return null;
    }

    public function board(string $text): BoardType
    {
        return match (true) {
            preg_match('/\ball[\s-]?inclusive\b|\bAI\b/iu', $text) === 1 => BoardType::AllInclusive,
            preg_match('/pe(?:ł|l)n(?:e|ym)\s+wy(?:ż|z)ywieni|\bfull\s?board\b|\bFB\b/iu', $text) === 1 => BoardType::FullBoard,
            preg_match('/obiadokolacj|dwa\s+posi(?:ł|l)ki|\bhalf\s?board\b|\bHB\b/iu', $text) === 1 => BoardType::HalfBoard,
            preg_match('/(?:ze\s+)?(?:ś|s)niadani|\bBB\b/iu', $text) === 1 => BoardType::Breakfast,
            preg_match('/bez\s+wy(?:ż|z)ywienia|tylko\s+nocleg/iu', $text) === 1 => BoardType::RoomOnly,
            default => BoardType::Unknown,
        };
    }

    public function hotelStars(string $text): ?int
    {
        if (preg_match('/\b([1-5])\s*\*/u', $text, $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/\b([1-5])[\s-]*gwiazdkow/iu', $text, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * "7 nocy w 3* hotelu Agrabella" -> "Agrabella".
     */
    public function hotel(string $text): ?string
    {
        $patterns = [
            '/hotelu?\s+(?:[1-5]\s*\*\s*)?([A-ZĄĆĘŁŃÓŚŹŻ][\p{L}0-9&\'\-\s]{2,40}?)(?=\s*(?:[.,;!?|]|\z|\s-\s|\s(?:z|na|w|przy|oraz|obejmuj)))/u',
            '/([A-ZĄĆĘŁŃÓŚŹŻ][\p{L}0-9&\'\-\s]{2,40}?)\s+(?:hotel|resort)\b/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches) === 1) {
                $hotel = trim($matches[1]);

                if (mb_strlen($hotel) >= 3) {
                    return $hotel;
                }
            }
        }

        return null;
    }

    /**
     * Airports an offer can be flown from: "wylot z Gdańska (za dopłatą
     * również z Krakowa lub Warszawy)".
     *
     * @return list<string>
     */
    public function departureCities(string $text): array
    {
        // Looked up rather than pattern-matched: Polish puts the first city in
        // the genitive ("wylot z Gdańska") and then lists the rest after "lub"
        // or a comma, where no "z ..." pattern reaches them.
        $cities = [];

        foreach (self::AIRPORT_CITIES as $declined => $name) {
            if (mb_stripos($text, $declined) !== false) {
                $cities[$name] = true;
            }
        }

        return array_keys($cities);
    }

    /**
     * Turns a place as the headline declines it into something a label can
     * show: "na Krecie" -> "Kreta". Anything unrecognised is left out rather
     * than printed in the wrong case.
     */
    public function place(string $text): ?string
    {
        if (preg_match_all('/\b(?:na|do|w|we)\s+([A-ZĄĆĘŁŃÓŚŹŻ][\p{L}\-]{2,25})/u', $text, $matches) === 0) {
            return null;
        }

        // The specific place tends to come after the country.
        foreach (array_reverse($matches[1]) as $candidate) {
            $place = self::PLACES[$candidate] ?? null;

            if ($place !== null) {
                return $place;
            }
        }

        return null;
    }

    /**
     * Dates the article names - "31 sierpnia", "20-27 lipca" - resolved to
     * real days so they can be drawn on a calendar.
     *
     * The year is never written, so it is taken from when the article was
     * published: a month already behind us means next year.
     *
     * @return list<TravelWindow>
     */
    public function dates(string $text, CarbonImmutable $publishedAt): array
    {
        $pattern = '/\b(\d{1,2})(?:\s*[-–]\s*(\d{1,2}))?\s+('.self::MONTHS.')\b/iu';

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $windows = [];

        foreach ($matches as $match) {
            $window = $this->toWindow($match, $publishedAt);

            if ($window instanceof TravelWindow) {
                $windows[$window->label] = $window;
            }
        }

        return array_values($windows);
    }

    /**
     * @param  array<int, string>  $match
     */
    private function toWindow(array $match, CarbonImmutable $publishedAt): ?TravelWindow
    {
        $month = self::MONTH_NUMBERS[mb_strtolower($match[3])] ?? null;
        $firstDay = (int) $match[1];
        $lastDay = ($match[2] ?? '') === '' ? null : (int) $match[2];

        if ($month === null || $firstDay < 1 || $firstDay > 31) {
            return null;
        }

        $year = $month < $publishedAt->month ? $publishedAt->year + 1 : $publishedAt->year;

        if (! checkdate($month, $firstDay, $year)) {
            return null;
        }

        $from = CarbonImmutable::create($year, $month, $firstDay)?->startOfDay();

        if ($from === null) {
            return null;
        }

        $to = $lastDay !== null && $lastDay >= $firstDay && checkdate($month, $lastDay, $year)
            ? CarbonImmutable::create($year, $month, $lastDay)?->startOfDay()
            : null;

        return new TravelWindow($from, $to, trim(preg_replace('/\s+/u', ' ', $match[0]) ?? $match[0]));
    }

    private function withinReason(int $days): ?int
    {
        return $days >= 2 && $days <= 30 ? $days : null;
    }
}
