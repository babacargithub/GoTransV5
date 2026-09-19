---
paths:
  - 'routes/web.php,app/Http/Middleware/CachePublicHtmlResponse.php,app/Providers/AppServiceProvider.php,config/app.php'
---

# Providers

## Public website read-only pages: no session + full-HTML cache
website.home / website.yobante / website.aide / website.caravanes.show run with session/cookie/CSRF middleware stripped (Route::withoutMiddleware in routes/web.php — they render no forms, no @csrf, no $errors) and website.home + website.caravanes.show additionally go through App\Http\Middleware\CachePublicHtmlResponse (full rendered HTML cached under config('app.public_page_cache_enabled')/_ttl, default 60s).

Cache key includes a version counter bumped by AppServiceProvider on Depart/Bus/Trajet/PromotionalMessage/Vehicule save|delete — Booking is deliberately excluded (seat "complet" status may lag up to the TTL; the booking backend re-validates and shows a proper error + waiting list, so this is an accepted tradeoff, not a bug). Also keyed by the Vite manifest mtime so an asset rebuild busts old cached HTML.

phpunit.xml sets PUBLIC_PAGE_CACHE_ENABLED=false so tests get a normal request by default; tests that exercise the cache set config(['app.public_page_cache_enabled' => true]) explicitly (see PublicWebsiteTrajetPageTest). Adding a new website read-only page: put it in the same withoutMiddleware group; adding a new model that should invalidate the cache: add it to AppServiceProvider::PUBLIC_PAGE_CACHE_DEPENDENCIES, never Booking.

website.caravanes.show also eager-loads with Bus::withExists('seats as has_available_seat', ...) instead of counting seats — Bus::isFull() reads that attribute when present (see .ai/rules/caravanes.md).
