---
paths:
  - 'app/Http/Controllers/**'
---

# Controllers

## Back office rewrite: dual JSON/Flux responses from shared controllers
The back office is being rewritten as Livewire/Flux pages while the legacy Vue admin + mobile app keep using the JSON API. Reuse the SAME controller method for both. Branch on `$request->routeIs('back-office.*')`: return a Blade/Flux view (pass the existing API Resource via `->resolve($request)`) for the back office, otherwise return the current JSON/Resource response unchanged. Do NOT change services or business logic — front-end rewrite only. Back office web routes live in routes/web.php under `Route::middleware('auth')->prefix('back-office')->name('back-office.')`. Shared layout: `<x-layouts.back-office>` (Flux sidebar + header). First page done: DepartController@index -> resources/views/back-office/departs/index.blade.php.
