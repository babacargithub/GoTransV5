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

## DepartList bus "3 dots" menu — every bus action lives here
Each bus row of DepartList has an ellipsis dropdown (partial resources/views/livewire/back-office/partials/bus-actions-menu.blade.php) rebuilding legacy BusActions.vue. All actions reuse an untouched controller method via app(BusController::class)->x($bus, request()) / app(DepartController::class):
- Chiffres → openBusTicketSales($busId): shares the ONE ventes-de-billets modal with the départ card. ticketSalesBusId set → BusController@busTicketSales, else DepartController@ticketSales.
- Itinéraire / rendez-vous → openScheduleManagement($departId, $busId): existing RV dialog pre-scoped to bus:{id}. Legacy "Itinéraire" + "Gérer les RV" are merged into this one item. Empty bus scope shows "Ajouter tous les arrêts" (addAllBusStopSchedules → DepartController@addPointDepsSchedulesForBus).
- Répartition des clients → openBookingsRepartition($departId, $busId): passes bus_id to bookingGroupingsCount; dialog has a running "Cumul" column.
- Sièges du bus → openBusSeats: BusController@seatsForAdmin grid + performBulkAction (needs request()->query->set('action',...), NOT merge) + freeSeatsOfBus.
- Clôturer/Réouvrir → toggleBusClosed → BusController@toggleClose (request()->merge(['closed'=>!closed])).
- Modifier infos bus → route back-office.buses.edit (App\Livewire\BackOffice\EditBus, mirrors EditBus.vue/BusForm.vue; fields = BusController@update whitelist; véhicule read-only).
- Transférer les réservations → openBusBookingsTransfer → BusController@transferBookings (BusManager); transferType 3 forces count -1.
- Supprimer le bus → askToDeleteBus → BusController@destroy (422 when bookings exist).
- Exports: 4 filtered links (paye=1|0 & format=pdf|text) + "Toutes (PDF)" via Controller::filteredBookingsExportResponse; also added to the départ menu.
Menu item icon colours: [&_[data-flux-menu-item-icon]]:!text-<color>-500. Seat icon fetched via php artisan flux:icon armchair.

## Départs sub-menu list pages (PointDep / Itineraire / Horaire / Trajet)
The "Départs" header sub-items + "Admin > Trajets" are full-page Livewire list components in app/Livewire/BackOffice/, views resources/views/livewire/back-office/*-list.blade.php, routes back-office.{point-deps,itineraires,horaires,trajets}.index.

Row actions use shared icon-only buttons: <x-back-office.edit-button> (indigo) and <x-back-office.delete-button> (red danger). House rule — edit/delete are always icon-only, indigo/red.

Mutations reuse untouched controller methods where they exist: PointDepController (update/disable/destroy/store), ItineraryController (update/destroy), TrajetController+TrajetService (update/destroy). HoraireController and DestinationController are empty stubs, so those mutations are plain model writes in the component. Components validate first (mirroring the controller rules) then call app(Controller::class)->method(request()->merge([...]), $model) inside try/catch(\Throwable).

Itinerary gained a `disabled` boolean column (migration) + cast; disable toggle is inline. Horaire gained `periode` in $fillable + a trajet() belongsTo.

TrajetList is interactive: pointDeps/destinations count badges (flux:badge with wire:click) open a flux:modal listing that trajet's items with an add form + per-row edit/delete.

## Admin + Finances back-office pages (Employé / Véhicule / Params / Caisses / OM / Wave)
Header "Admin" + "Finances" menus now point to full-page Livewire components:
- EmployeList (back-office.employes.index), VehiculeList (vehicules.index): list + top "Ajouter/Créer" + modal create/edit + delete + activate toggle. EmployeController is JSON-index-only and VehiculeController is an empty stub, so mutations are plain model writes. Fleshed out Employe/EmployeCategory/Vehicule models (fillable, casts, relations). vehicules table has NOT-NULL no-default columns chauffeur/nombre_place/vehicule_type/description — chauffeur & seats required, description coalesced to ''. Marking a véhicule default unsets the others in a DB::transaction.
- AppParamsPage (parametres.index): edits the known scalar keys of the single app_params.data JSON; save does array_replace_recursive to preserve unrelated keys (mirrors MobileAppController@updateParams).
- CaisseBalancesPage (caisses.index): reproduces TicketController@index's payment-method aggregation query in-component; Wave/OM balances via app(WavePaiementController/OrangeMoneyController)::class each in its own try/catch → null → "Indisponible".
- OrangeMoneyPage (paiements-om.index): OM balance + transactions (try/catch → null), withdraw form → OrangeMoneyController@withdraw (422 handling). Tests use Http::fake to keep provider calls in-process.
- WavePaymentsPage (paiements-wave.index): placeholder only.
