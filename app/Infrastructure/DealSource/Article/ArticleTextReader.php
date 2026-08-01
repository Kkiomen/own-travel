<?php

declare(strict_types=1);

namespace App\Infrastructure\DealSource\Article;

/**
 * Pulls the summary a blog publishes for its own article.
 */
final readonly class ArticleTextReader
{
    public function metaDescription(string $html): string
    {
        $pattern = '#<meta[^>]+(?:name|property)="(?:og:)?description"[^>]+content="([^"]*)"#i';

        return preg_match($pattern, $html, $matches) === 1
            ? html_entity_decode($matches[1])
            : '';
    }
}
