---
paths:
  - 'app/Livewire/BackOffice/**'
---

# Back Office

## Interactive back-office pages are full-page Livewire components, not POST forms
When a back-office page needs in-place updates (no full reload), build it as a class-based full-page Livewire component in app/Livewire/BackOffice/ + view in resources/views/livewire/back-office/, registered with `Route::get('...', Component::class)->name(...)` inside the `back-office.` group. This supersedes the "no Livewire, plain POST form" guidance in .ai/rules/controllers.md for such pages (that pattern still applies to simple non-interactive action endpoints).

Reuse, don't reimplement, the legacy business logic: the component calls the existing controller method via `app(SomeController::class)->method($model, request())` and inspects the returned JsonResponse status / catches Throwable. During a Livewire update `request()->routeIs('back-office.*')` is false, so those methods take their JSON branch — treat that as the programmatic path. Do NOT touch services/managers/controllers.

Dangerous row actions (pay, cancel) go through a shared <flux:modal wire:model.self> confirmation: askToConfirm*() opens it and stashes pendingBookingId/pendingActionName; confirmPendingAction() closes it and dispatches via match(). First page: BusBookings (bus passengers). Legacy JSON API keeps hitting BusController@bookings unchanged.

## Départ list is now a full-page Livewire component (DepartList)
The back-office départ list moved from a Blade page (DepartController@index branch) to App\Livewire\BackOffice\DepartList + resources/views/livewire/back-office/depart-list.blade.php, routed by Route::get('departs', DepartList::class)->name('departs.index'). It gained three in-place features: "Ventes de billets" modal (reads DepartController@ticketSales), "Répartition des clients" modal (reads DepartController@bookingGroupingsCount), and per-départ/per-bus "Exporter" download links (see controllers rule). DepartController@index is now JSON-only again; the old back-office.departs.index Blade view is deleted. "Ajouter un bus" links to App\Livewire\BackOffice\AddBusToDepart (route departs.add-bus); on success it flashes session('status') and redirects to departs.index, which renders that flash.

## DepartList gained rendez-vous management, cancellation, and an Envoi page
DepartList's "3 dots" menu now wires four more actions:
- "Gestion des rendez-vous": <flux:modal> editing heure_departs. Scope picker (dépôt-wide 'depart' vs 'bus:{id}') reloads rows via app(DepartController::class)->busStopSchedules($depart, request()->merge(['bus_id' => ...])); save posts request()->merge(['busStopSchedules' => [...]]) to updateBusStopSchedules. Switch = "Actif" (stored inverted as heure_departs.disabled).
- "Annuler ce départ": shared <flux:modal> confirm -> confirmCancelDepart() calls DepartController@cancelDepart (soft cancel when bookings exist, else hard delete) then redirectRoute('back-office.departs.index', navigate:true) with a session flash. Depart model already has a global 'notCanceled' scope, so no list filtering needed.
- "Envoi des rendez-vous": links (wire:navigate) to App\Livewire\BackOffice\DepartScheduleNotifications (route back-office.departs.schedule-notifications). First pass is message-composer only; recipient selection + SMS sending (legacy BookingController@sendScheduleNotification) still TODO.
Menu items carry per-icon colours via `[&_[data-flux-menu-item-icon]]:!text-*` classes.
