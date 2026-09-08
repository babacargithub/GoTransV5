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

## CreateDepart: "Nouveau départ" full-page Livewire component
App\Livewire\BackOffice\CreateDepart + resources/views/livewire/back-office/create-depart.blade.php, routed Route::get('departs/create', CreateDepart::class)->name('departs.create') (placed BEFORE departs/{depart}/...). Linked from the layout "Nouveau départ" navmenu/menu items and a primary button on DepartList.

Mirrors legacy Vue DepartureNew.vue + DepartForm.vue minus the removed "Créer plusieurs départs" checkbox: it is ALWAYS multi-date now. Dropdown data comes from app(DepartController::class)->getDataForDepartCreation()->getData(true) (events/trajets/horaires/vehicules). On save it builds the legacy `departs` array (one entry per checked date, name = template with <Date> replaced by Carbon->locale('fr')->translatedFormat('l d F')), request()->merge(['departs' => ...]), then app(DepartController::class)->store(request()) and checks HTTP 201 / catches Throwable — same pattern as AddBusToDepart. Controller/store untouched. `type_of_bus_to_create` radio (simple/climatise/both) is sent for parity but legacy store ignores it. store() needs auth (User::requiredLoggedInUser) + ≥1 Event row.

## EditDepart: "Modifier départ" full-page Livewire component
App\Livewire\BackOffice\EditDepart + resources/views/livewire/back-office/edit-depart.blade.php, routed Route::get('departs/{depart}/edit', EditDepart::class)->name('departs.edit') (placed AFTER departs/create, BEFORE departs/{depart}/add-bus). Wired from the pencil-square button on each DepartList card (wire:navigate).

Mirrors legacy Vue DepartureEdit.vue + DepartForm.vue (isEditing mode) but FIXES its bug: the legacy edit form hid the date field (only time was editable). Here both date + time are editable and recombined into the single `date` column via Carbon::parse(date)->setTimeFromTimeString(time).

Editable fields: name, date, time, horaire_id, visibilite — exactly the fields DepartController@update whitelists. Trajet is shown read-only (legacy exposed a trajet picker but update() never persisted it). On save: request()->merge([...]) then app(DepartController::class)->update(request(), $depart), check HTTP 200, flash session('status') + redirectRoute('back-office.departs.index', navigate:true). Controller untouched (update() already had a routeIs-free JSON-only path).
