<?php

namespace App\Http\Middleware;

use App\Services\AmicaleService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public website answers on its own domain and on one subdomain per amicale ("{slug}.{domain}").
 * The host is a route parameter so both share the same routes: this middleware drops it from the action
 * arguments, pins it for URL generation (links stay on the amicale's site) and, on a subdomain, loads the
 * amicale and shares it with the views. An unknown or deactivated amicale answers 404.
 */
class ResolveAmicaleFromSubdomain
{
    public const HOST_ROUTE_PARAMETER = 'websiteHost';

    public function __construct(private readonly AmicaleService $amicaleService) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->route()->forgetParameter(self::HOST_ROUTE_PARAMETER);
        URL::defaults([self::HOST_ROUTE_PARAMETER => $request->getHost()]);

        if ($request->getHost() !== config('app.public_website_domain')) {
            $amicale = $this->amicaleService->findActiveAmicaleByHost($request->getHost());

            abort_if($amicale === null, 404);

            $request->attributes->set('amicale', $amicale);
            View::share('currentAmicale', $amicale);
        }

        return $next($request);
    }
}
