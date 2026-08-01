<?php

declare(strict_types=1);

namespace App\Infrastructure\DealSource\Article;

use App\Domain\Deal\ValueObject\TripDetails;
use Carbon\CarbonImmutable;

/**
 * Reads trip details out of one blog's article page.
 *
 * Every blog writes its offers up differently, so each gets its own parser -
 * the feed adapter only knows it has one.
 */
interface ArticleParser
{
    /**
     * @param  CarbonImmutable  $publishedAt  the dates never carry a year, so
     *                                        this is what they are read against
     */
    public function parse(string $html, string $headline, CarbonImmutable $publishedAt): TripDetails;
}
