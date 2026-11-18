<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only the public website and the standalone landing domains may appear in search engines. Every response
 * served anywhere else (the back office and its API, the ambassador space: pages, login, redirects, Livewire
 * calls, errors, robots.txt) tells search engines not to index, follow, cache or snippet it. The header
 * complements the noindex meta tag of the layouts and also covers responses that have no HTML head. Crawling
 * itself is refused by the robots.txt of those hosts (SeoFilesController@fallbackRobots).
 */
class KeepPrivateDomainsOutOfSearchEngines
{
    public const ROBOTS_DIRECTIVES = 'noindex, nofollow, noarchive, nosnippet';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! self::isSearchEngineFriendlyHost($request->getHost())) {
            $response->headers->set('X-Robots-Tag', self::ROBOTS_DIRECTIVES);
        }

        return $response;
    }

    public static function isSearchEngineFriendlyHost(string $host): bool
    {
        return in_array($host, [
            config('app.public_website_domain'),
            config('app.concours_domain'),
            config('app.gp_domain'),
        ], true);
    }
}
