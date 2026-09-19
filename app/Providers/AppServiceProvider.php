<?php

namespace App\Providers;

use App\Http\Middleware\CachePublicHtmlResponse;
use App\Models\Bus;
use App\Models\Depart;
use App\Models\PromotionalMessage;
use App\Models\Trajet;
use App\Models\Vehicule;
use Illuminate\Database\Eloquent\Model;
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
