<?php

declare(strict_types=1);

namespace App\Infrastructure\DealSource\Parser;

use App\Domain\Deal\ValueObject\Money;

/**
 * Polish deal blogs put the price in the headline: "Tanie loty do Barcelony za
 * 79 PLN", "Tydzień w 4* za 2518 PLN". This pulls it back out.
 *
 * The amount always has to be introduced by a word promising a price. A bare
 * number is deliberately not enough - headlines like "1300 zł za dobę.
 * Sprawdziłem, jak wyglądają wakacje all inclusive… w Polsce!" are articles
 * about prices, not offers, and reading them as offers fills the dashboard
 * with things nobody can book.
 */
final readonly class OfferPriceParser
{
    /**
     * "za", "od" and "tylko" cover most of it; Wakacyjni Piraci favour "poniżej
     * 1000 zł" and "niecałe 2000 zł/os", which used to be dropped outright.
     */
    private const LEAD_IN = 'za|od|tylko|poniżej|ponizej'
        .'|niecałe|niecale|niecały|niecaly|niecała|niecala'
        .'|w cenie|cenie';

    /**
     * Superlatives the blogs slip between the promise and the number - "za
     * jedyne 561 PLN", "od około 1200 zł".
     */
    private const EMPHASIS = 'jedyne|jedynie|tylko|już|juz|około|okolo|ok\.';

    private const AMOUNT = '(\d{1,3}(?:[\s\x{00A0}]?\d{3})*(?:[.,]\d{1,2})?)';

    public function parse(string $text): ?Money
    {
        $pattern = '/(?:'.self::LEAD_IN.')\s+'
            .'(?:(?:'.self::EMPHASIS.')\s+){0,2}'
            .self::AMOUNT
            .'\s*(PLN|zł|zl)\b/iu';

        if (preg_match($pattern, $text, $matches) !== 1) {
            return null;
        }

        $amount = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $matches[1]);

        if (! is_numeric($amount)) {
            return null;
        }

        return Money::fromDecimal($amount, 'PLN');
    }
}
