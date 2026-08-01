<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

abstract class TestCase extends BaseTestCase
{
    /**
     * Reads the Inertia page object out of a rendered response.
     *
     * Inertia v3 embeds it in a JSON script tag, which its own assertion
     * helper does not read - so we take it from there ourselves.
     *
     * @return array{component: string, props: array<string, mixed>, url: string, version: string|null}
     */
    protected function inertiaPage(TestResponse $response): array
    {
        $matched = preg_match(
            '#<script data-page="app" type="application/json">(.*?)</script>#s',
            $response->getContent() ?: '',
            $matches,
        );

        Assert::assertSame(1, $matched, 'The response does not carry an Inertia page.');

        /** @var array{component: string, props: array<string, mixed>, url: string, version: string|null} $page */
        $page = json_decode(html_entity_decode($matches[1]), true, 512, JSON_THROW_ON_ERROR);

        return $page;
    }

    /**
     * @return array<string, mixed>
     */
    protected function inertiaProps(TestResponse $response): array
    {
        return $this->inertiaPage($response)['props'];
    }
}
