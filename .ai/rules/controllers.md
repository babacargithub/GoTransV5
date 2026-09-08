---
paths:
  - 'app/Http/Controllers/**'
---

# Controllers

## Back office rewrite: dual JSON/Flux responses from shared controllers
The back office is being rewritten as Livewire/Flux pages while the legacy Vue admin + mobile app keep using the JSON API. Reuse the SAME controller method for both. Branch on `$request->routeIs('back-office.*')`: return a Blade/Flux view (pass the existing API Resource via `->resolve($request)`) for the back office, otherwise return the current JSON/Resource response unchanged. Do NOT change services or business logic — front-end rewrite only. Back office web routes live in routes/web.php under `Route::middleware('auth')->prefix('back-office')->name('back-office.')`. Shared layout: `<x-layouts.back-office>` (Flux sidebar + header). First page done: DepartController@index -> resources/views/back-office/departs/index.blade.php.

## Back office mutations: POST form + routeIs branch + redirect back with flash
For simple, non-interactive back-office action endpoints, reuse the legacy controller method and branch on `$request->routeIs('back-office.*')`: run the existing business logic, then `return back()->with('status', ...)` on success or `back()->with('error', $e->getMessage())` on failure. Leave the existing JSON return path untouched below the branch. The Flux page wires each action as a plain `<form method="POST">` (or one form with `formaction` per submit button) + `@csrf`. Flash is rendered with `@if (session('status'))` / `session('error')` flux:callout at the top of the page. Examples: BookingController@saveTicketPayment, BookingController@triggerPaymentRequestForPaymentMethod. Back-office web routes still have NO auth middleware, so `request()->user()` is null in these actions (e.g. ticket soldBy falls back to "system") — restore auth on the back-office group before go-live.

When a page needs in-place updates (no full reload), it becomes a full-page Livewire component instead — see `.ai/rules/back-office.md`. The bus passengers page (`back-office.buses.bookings`) already moved: it routes to `App\Livewire\BackOffice\BusBookings`, not `BusController@bookings` (which is now JSON-API-only).

## Shared bookings export: bookingsExportDocumentResponse() on base Controller
DepartController@bookingsForExport and BusController@bookingsForExport each have a routeIs('back-office.*') branch that calls $this->bookingsExportDocumentResponse($bookings, $title, $headerLines, $fileName) (defined on App\Http\Controllers\Controller). Départ passes only the départ label; Bus passes départ label + bus name. It renders resources/views/back-office/exports/bookings.blade.php — a print-optimised HTML page (auto window.print()), columns: siège, nom complet (via normalize_passenger_display_name()), téléphone, point de départ, payé par. No server-side PDF library is installed (dependency changes need approval); "save as PDF" is done from the browser print dialog. The legacy JSON path (BookingForExportResource) is unchanged below the branch. Also added 'visibilite' => 'nullable|integer' to DepartController@addBusToDepart validation so the back-office AddBus form can set bus visibility; legacy Vue omits it and the column defaults to 1.
