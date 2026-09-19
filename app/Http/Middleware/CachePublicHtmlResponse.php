<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Full-HTML response cache for the read-only public website pages (the caravane départ list is
 * the app's most-visited page). A cache hit skips the controller, the départ/bus queries and the
 * Blade render entirely — the request only pays framework boot + one cache read.
 *
 * Freshness: entries live for config('app.public_page_cache_ttl') seconds and are dropped whenever
 * a Départ / Bus / Trajet / PromotionalMessage / Vehicule changes (see AppServiceProvider), by
 * bumping a global version number that is part of every cache key. Bookings do NOT invalidate —
 * a bus showing as available for up to the TTL after it fills is acceptable (the booking backend
 * rejects the seat and offers the waiting list).
 *
 * The response also carries `Cache-Control: public` with `stale-while-revalidate`, so a CDN or
 * reverse proxy in front absorbs the bulk of the traffic and does the revalidation out of band.
 */
class CachePublicHtmlResponse
{
    private const VERSION_KEY = 'public-page:version';

    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        if (! config('app.public_page_cache_enabled', true) || $request->getMethod() !== 'GET') {
            return $next($request);
        }

        $ttl = max(1, (int) config('app.public_page_cache_ttl', 60));
        $cacheKey = $this->cacheKey($request);

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $this->decorate(
                new Response($cached['content'], 200, ['Content-Type' => $cached['content_type']]),
                'HIT',
                $ttl,
            );
        }

        $response = $next($request);

        if ($response instanceof Response && $response->getStatusCode() === 200) {
            Cache::put($cacheKey, [
                'content' => $response->getContent(),
                'content_type' => $response->headers->get('Content-Type', 'text/html; charset=UTF-8'),
            ], now()->addSeconds($ttl));

            $this->decorate($response, 'MISS', $ttl);
        }

        return $response;
    }

    /**
     * Drop every cached public page. Cheap: it only bumps the version counter that keys the cache,
     * so stale entries are ignored immediately and fall out on their own TTL.
     */
    public static function flushAll(): void
    {
        if (! config('app.public_page_cache_enabled', true)) {
            return;
        }

        Cache::forever(self::VERSION_KEY, self::version() + 1);
    }

    public static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 0);
    }

    private function cacheKey(Request $request): string
    {
        return implode(':', [
            'public-page',
            'v'.self::version(),
            // A front-end asset rebuild changes the @vite tags in the cached HTML.
            'a'.$this->assetBuildFingerprint(),
            sha1($request->getHost().'|'.$request->getRequestUri()),
        ]);
    }

    private function assetBuildFingerprint(): string
    {
        $manifest = public_path('build/manifest.json');

        return is_file($manifest) ? (string) filemtime($manifest) : 'dev';
    }

    private function decorate(SymfonyResponse $response, string $status, int $ttl): SymfonyResponse
    {
        $response->headers->set('X-Cache', $status);
        $response->headers->set(
            'Cache-Control',
            "public, max-age={$ttl}, s-maxage={$ttl}, stale-while-revalidate=600",
        );

        return $response;
    }
}
