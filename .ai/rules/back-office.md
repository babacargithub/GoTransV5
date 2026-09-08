---
paths:
  - 'app/Livewire/BackOffice/**'
---

# Back Office

## Interactive back-office pages are full-page Livewire components, not POST forms
When a back-office page needs in-place updates (no full reload), build it as a class-based full-page Livewire component in app/Livewire/BackOffice/ + view in resources/views/livewire/back-office/, registered with `Route::get('...', Component::class)->name(...)` inside the `back-office.` group. This supersedes the "no Livewire, plain POST form" guidance in .ai/rules/controllers.md for such pages (that pattern still applies to simple non-interactive action endpoints).

Reuse, don't reimplement, the legacy business logic: the component calls the existing controller method via `app(SomeController::class)->method($model, request())` and inspects the returned JsonResponse status / catches Throwable. During a Livewire update `request()->routeIs('back-office.*')` is false, so those methods take their JSON branch — treat that as the programmatic path. Do NOT touch services/managers/controllers.

Dangerous row actions (pay, cancel) go through a shared <flux:modal wire:model.self> confirmation: askToConfirm*() opens it and stashes pendingBookingId/pendingActionName; confirmPendingAction() closes it and dispatches via match(). First page: BusBookings (bus passengers). Legacy JSON API keeps hitting BusController@bookings unchanged.
