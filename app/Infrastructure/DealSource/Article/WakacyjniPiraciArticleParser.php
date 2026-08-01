<?php

declare(strict_types=1);

namespace App\Infrastructure\DealSource\Article;

use App\Domain\Deal\ValueObject\TripDetails;
use Carbon\CarbonImmutable;

/**
 * Wakacyjni Piraci render as a single-page app, but the article itself ships
 * with the page as escaped rich-text JSON. Its text nodes are the offer's own
 * bullet points and nothing else - no adverts, no other operators' offers -
 * which makes them the best thing on either blog to read:
 *
 *   "7 nocy w 3* hotelu Agrabella"
 *   "wylot z Gdańska (za dopłatą również z Krakowa lub Warszawy)"
 *   "wyżywienie: HB - śniadania i obiadokolacje"
 *   "proponowany termin: 31 sierpnia - 7 września"
 */
final readonly class WakacyjniPiraciArticleParser implements ArticleParser
{
    /**
     * Boilerplate the site repeats on every post.
     */
    private const NOISE = '/^(SPRAWD|Cena za osob|Ceny s(?:ą|a) aktualne|Zarezerwuj|Przejd(?:ź|z))/iu';

    public function __construct(
        private JsonLdReader $jsonLd,
        private ArticleTextReader $text,
        private TripAttributeReader $attributes,
    ) {}

    public function parse(string $html, string $headline, CarbonImmutable $publishedAt): TripDetails
    {
        $article = $this->jsonLd->article($html);
        $values = $this->values($html);
        $bullets = $this->bullets($values);

        $summary = trim(implode(' ', array_filter([
            $headline,
            $article['headline'],
            $article['description'],
            $this->text->metaDescription($html),
        ])));

        // The bullets are the offer; the headline only fills in the gaps.
        $facts = implode(' | ', $bullets);
        // Dates are searched across every text node, not just the bullets:
        // bold runs split them into fragments too short to be a bullet
        // ("31 sierpnia" on its own).
        $everything = implode(' | ', $values).' '.$summary;

        return new TripDetails(
            days: $this->attributes->days($facts) ?? $this->attributes->days($summary),
            board: $this->attributes->board($facts.' '.$summary),
            hotelStars: $this->attributes->hotelStars($facts) ?? $this->attributes->hotelStars($summary),
            destination: $this->attributes->place($summary),
            hotel: $this->hotel($bullets),
            departureCities: $this->attributes->departureCities($everything),
            dates: $this->attributes->dates($everything, $publishedAt),
            highlights: $bullets,
        );
    }

    /**
     * Every text node the rich text carries.
     *
     * @return list<string>
     */
    private function values(string $html): array
    {
        if (preg_match_all('/\\\\"value\\\\":\\\\"(.*?)\\\\"/u', $html, $matches) === 0) {
            return [];
        }

        $values = [];

        foreach ($matches[1] as $value) {
            $text = trim(str_replace(['\\n', '\\/', '\\u00a0'], ["\n", '/', ' '], $value));

            if ($text !== '') {
                $values[$text] = true;
            }
        }

        return array_keys($values);
    }

    /**
     * The nodes that read as offer facts rather than prose fragments.
     *
     * @param  list<string>  $values
     * @return list<string>
     */
    private function bullets(array $values): array
    {
        $bullets = [];

        foreach ($values as $text) {
            // The offer's bullet list comes first and the write-up after it,
            // so the first paragraph-length node ends the facts.
            if (mb_strlen($text) > 100) {
                break;
            }

            // Shorter still is prose split around a bold run, not a fact.
            if (mb_strlen($text) < 12) {
                continue;
            }

            if (preg_match(self::NOISE, $text) === 1) {
                continue;
            }

            $bullets[$text] = true;
        }

        return array_slice(array_keys($bullets), 0, 12);
    }

    /**
     * Read per bullet: the hotel is named inside one of them, and joining the
     * facts first only lets the match run past its end.
     *
     * @param  list<string>  $bullets
     */
    private function hotel(array $bullets): ?string
    {
        foreach ($bullets as $bullet) {
            $hotel = $this->attributes->hotel($bullet);

            if ($hotel !== null) {
                return $hotel;
            }
        }

        return null;
    }
}
