<?php

declare(strict_types=1);

namespace App\Infrastructure\DealSource\Article;

use Throwable;

/**
 * Both blogs publish schema.org metadata, which is far steadier ground than
 * their markup. This pulls the article node out of it.
 */
final readonly class JsonLdReader
{
    private const ARTICLE_TYPES = ['Article', 'NewsArticle', 'BlogPosting'];

    /**
     * Returns the article's headline and description, whichever way the blog
     * nests them.
     *
     * @return array{headline: string, description: string}
     */
    public function article(string $html): array
    {
        foreach ($this->blocks($html) as $block) {
            $article = $this->findArticle($block);

            if ($article !== null) {
                return [
                    'headline' => is_string($article['headline'] ?? null) ? $article['headline'] : '',
                    'description' => is_string($article['description'] ?? null) ? $article['description'] : '',
                ];
            }
        }

        return ['headline' => '', 'description' => ''];
    }

    /**
     * @return list<array<mixed>>
     */
    private function blocks(string $html): array
    {
        if (preg_match_all('#<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>#si', $html, $matches) !== 1
            && $matches[1] === []) {
            return [];
        }

        $blocks = [];

        foreach ($matches[1] as $json) {
            try {
                $decoded = json_decode(trim($json), true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }

            if (is_array($decoded)) {
                $blocks[] = $decoded;
            }
        }

        return $blocks;
    }

    /**
     * @param  array<mixed>  $block
     * @return array<string, mixed>|null
     */
    private function findArticle(array $block): ?array
    {
        $candidates = isset($block['@graph']) && is_array($block['@graph'])
            ? $block['@graph']
            : [$block];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $type = $candidate['@type'] ?? null;
            $types = is_array($type) ? $type : [$type];

            if (array_intersect(self::ARTICLE_TYPES, array_filter($types, 'is_string')) !== []) {
                /** @var array<string, mixed> $candidate */
                return $candidate;
            }
        }

        return null;
    }
}
