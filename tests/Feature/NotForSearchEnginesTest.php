<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard is a single-owner tool with no authentication, so the one thing
 * standing between it and a search result is that it asks not to be indexed.
 */
final class NotForSearchEnginesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_response_carries_the_header(): void
    {
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function test_the_health_check_carries_it_too(): void
    {
        // A header rather than a meta tag precisely so it covers what does not
        // render a head.
        $this->get('/up')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function test_the_page_says_it_in_its_head_as_well(): void
    {
        // For anything that fetches the page without honouring the header.
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow, noarchive">', false);
    }

    public function test_crawlers_are_asked_to_stay_away(): void
    {
        $this->assertStringContainsString(
            'Disallow: /',
            (string) file_get_contents(public_path('robots.txt')),
        );
    }
}
