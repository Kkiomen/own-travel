<?php

declare(strict_types=1);

namespace App\Infrastructure\DealSource\Article;

use App\Domain\Deal\ValueObject\TripDetails;
use Carbon\CarbonImmutable;

/**
 * Fly4free state the whole offer in one sentence - the schema.org description,
 * repeated as the meta description and the article's lead:
 *
 *   "Sierpniowe wakacje w Egipcie w 4* hotelu Lemon & Soul Makadi Garden w
 *    Zatoce Makadi koło Hurghady. Tydzień wypoczynku z all inclusive..."
 *
 * The rest of the page is off limits: they paste advertising widgets for other
 * operators into the body ("Zarzis od 2639 PLN na 7 dni"), the contents change
 * between requests, and a duration lifted from one of those silently mis-rates
 * the deal. Missing detail is fine; wrong detail is not.
 */
final readonly class Fly4freeArticleParser implements ArticleParser
{
    public function __construct(
        private JsonLdReader $jsonLd,
        private ArticleTextReader $text,
        private TripAttributeReader $attributes,
    ) {}

    public function parse(string $html, string $headline, CarbonImmutable $publishedAt): TripDetails
    {
        $article = $this->jsonLd->article($html);
        $description = $article['description'] !== ''
            ? $article['description']
            : $this->text->metaDescription($html);

        $summary = trim(implode(' ', array_filter([$headline, $article['headline'], $description])));

        return new TripDetails(
            days: $this->attributes->days($summary),
            board: $this->attributes->board($summary),
            hotelStars: $this->attributes->hotelStars($summary),
            destination: $this->destination($summary),
            hotel: $this->attributes->hotel($summary),
            departureCities: $this->attributes->departureCities($summary),
            dates: $this->attributes->dates($summary, $publishedAt),
            highlights: $description === '' ? [] : [$description],
        );
    }

    /**
     * "Wakacje all inclusive w Egipcie", "Lato w Kalabrii".
     */
    private function destination(string $summary): ?string
    {
        if (preg_match('/\b(?:na|do|w)\s+([A-ZĄĆĘŁŃÓŚŹŻ][\p{L}\-]{2,25})/u', $summary, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
