<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Deal\DealSort;
use App\Domain\Deal\DealType;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

final class OpenApiDocumentTest extends TestCase
{
    public function test_it_serves_a_readable_openapi_document(): void
    {
        $document = $this->getJson('/api/openapi.json')->assertOk()->json();

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertArrayHasKey('/api/v1/deals', $document['paths']);
        $this->assertArrayHasKey('Deal', $document['components']['schemas']);
    }

    /**
     * A document that has fallen behind the routes is worse than none: it tells
     * the other app to call something that is not there.
     */
    public function test_every_endpoint_is_documented_and_every_documented_path_exists(): void
    {
        $document = $this->getJson('/api/openapi.json')->assertOk()->json();

        $served = collect(Router::getRoutes()->getRoutes())
            ->filter(static fn (Route $route): bool => str_starts_with($route->uri(), 'api/v1/'))
            ->map(static fn (Route $route): string => '/'.$route->uri())
            ->sort()
            ->values()
            ->all();

        $this->assertSame($served, collect(array_keys($document['paths']))->sort()->values()->all());
    }

    /**
     * The enums are read from the domain rather than typed out, so a new sort
     * or a new kind of deal cannot quietly go undocumented.
     */
    public function test_it_describes_the_values_the_app_actually_accepts(): void
    {
        $document = $this->getJson('/api/openapi.json')->assertOk()->json();

        $this->assertSame(
            array_map(static fn (DealSort $sort): string => $sort->value, DealSort::cases()),
            $document['components']['parameters']['sort']['schema']['enum'],
        );

        $this->assertSame(
            array_map(static fn (DealType $type): string => $type->value, DealType::cases()),
            $document['components']['parameters']['type']['schema']['enum'],
        );
    }

    public function test_the_docs_page_points_swagger_ui_at_that_document(): void
    {
        // The URL reaches the page as a JSON literal, slashes and all.
        $this->get('/api/docs')
            ->assertOk()
            ->assertSee((string) json_encode(route('api.openapi')), escape: false);
    }

    /**
     * There is no login here, so nothing the app serves may be indexed.
     */
    public function test_the_api_asks_not_to_be_indexed(): void
    {
        $this->getJson('/api/v1/deals')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->get('/api/docs')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }
}
