<?php

namespace App\Providers;

use App\Http\Middleware\CachePublicHtmlResponse;
use App\Http\Middleware\ResolveAmicaleFromSubdomain;
use App\Models\Amicale;
use App\Models\Bus;
use App\Models\Depart;
use App\Models\PromotionalMessage;
use App\Models\Trajet;
use App\Models\Vehicule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Models whose changes make a cached public website page (see CachePublicHtmlResponse) stale.
     * Bookings are deliberately excluded — see that middleware's docblock.
     *
     * @var list<class-string<Model>>
     */
    private const PUBLIC_PAGE_CACHE_DEPENDENCIES = [
        Depart::class,
        Bus::class,
        Trajet::class,
        PromotionalMessage::class,
        Vehicule::class,
        Amicale::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The website routes take their host as a parameter (public domain or amicale subdomain): outside of a
        // website request, links to them point at the public domain.
        URL::defaults([ResolveAmicaleFromSubdomain::HOST_ROUTE_PARAMETER => config('app.public_website_domain')]);

        $this->invalidatePublicPageCacheOnContentChange();
    }

    private function invalidatePublicPageCacheOnContentChange(): void
    {
        $flush = static fn () => CachePublicHtmlResponse::flushAll();

        foreach (self::PUBLIC_PAGE_CACHE_DEPENDENCIES as $model) {
            $model::saved($flush);
            $model::deleted($flush);
        }
    }
}
