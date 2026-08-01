<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the dashboard out of search results.
 *
 * `robots.txt` asks crawlers not to come; this tells anything that comes
 * anyway not to keep what it finds. It is a header rather than only a meta tag
 * because it then covers every response - JSON, redirects, the health check -
 * and cannot be forgotten by a page that does not render the head.
 */
final class PreventIndexing
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}
