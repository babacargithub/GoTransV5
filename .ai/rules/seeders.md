---
paths:
  - 'app/Models/User.php,app/Models/Permission.php,app/Models/Role.php,app/Enums/PermissionName.php,app/Providers/AuthServiceProvider.php,app/Livewire/BackOffice/UserAccessManagement.php,database/seeders/PermissionCatalogueSeeder.php,app/Http/Controllers/**,app/Livewire/BackOffice/**'
---

# Seeders

## Roles & permissions run on spatie/laravel-permission
User privileges use spatie/laravel-permission (installed v8.3). Key points:
- The legacy `users.roles` column (PHP-serialized Symfony ROLE_* array, still read by the mobile /login in routes/api.php) was renamed to `users.legacy_roles` so it stops shadowing spatie's `roles()` relation on the User model. `User::resolveRoles()` + ROLE_HIERARCHY are unrelated legacy code kept only for that endpoint.
- App\Models\Permission / App\Models\Role extend the spatie models and add a nullable `label` column = the French public-facing wording shown in the UI. The internal `name` (kebab-case, e.g. `cancel-paid-booking`) is the stable constant guards check and is never edited from the UI. config/permission.php points `models.permission`/`models.role` at these subclasses. `->displayLabel()` falls back to name.
- App\Enums\PermissionName is the canonical catalogue (backing string = internal name) with `defaultLabel()` (seed value only) and `values()`. PermissionCatalogueSeeder (in DatabaseSeeder) upserts every case via findOrCreate and only seeds the label when still empty, so UI edits survive re-seeds. It also ensures a `super-admin` role holding `full-access`.
- Super-admin: AuthServiceProvider registers `Gate::before` returning true when `$user->hasFullAccess()` (holds the `full-access` permission). So every `can()/authorize()/@can` check is implicitly "OR full-access" — guards never name full-access.
- Back office UI: App\Livewire\BackOffice\UserAccessManagement, route back-office.users.index ("Gestion des utilisateurs" under Admin). Two tabs (users / permissions&roles) via a plain `$activeTab` string — Flux free has no tabs component.
- Tests: `Tests\TestCase` has `seedPermissionCatalogue()`, `createUserWithFullAccess()`, `createUserWithPermissions([...])`. `phpunit.xml` now sets `memory_limit=1024M` (the suite OOMs at the 128M default against the large dev DB — predates this feature).

## Sensitive actions are guarded by `PermissionName`
Every sensitive mutation checks a permission with an implicit "OR full-access" (the Gate::before hook). Two call styles, both on the enum:
- Controllers: `PermissionName::CancelPaidBooking->authorizeForCurrentUser();` at the top of the method — throws `AuthorizationException` (403) before validation. The SAME shared controllers serve the mobile app + legacy Vue admin, so those now need the permissions too (hard enforcement, chosen deliberately — no grandfathering).
- Livewire: components that delegate to a guarded controller inside a `try/catch(\Throwable)` surface the 403 as a flash automatically. Where there is no catch, or a real gap (e.g. `ManagesBookingActions::cancelBooking` calls the UNguarded `BookingController@cancelBooking`, not `destroy`), guard explicitly: `if (! PermissionName::X->allowedForCurrentUser()) { flash; return; }`.
Branch guards: `BookingController@destroy` picks CancelPaidBooking/CancelUnpaidBooking by `$booking->hasTicket()`; `BusController@toggleClose` picks CloseBus/UncloseBus by the requested `closed` value.
`close-depart` / `freeze-depart` / `unfreeze-depart` permissions are seeded but have NO endpoint yet — guard them when that feature is built.
Guarded so far: DepartController store/update/destroy/cancelDepart/addBusToDepart/updateBusStopSchedules/addPointDepsSchedulesForBus; BusController update/destroy/toggleClose/transferBookings; BookingController destroy/transferBooking/refundTicket/sendScheduleNotification; CustomerController store/update/destroy; MessengerController createMessages; plus the matching Livewire actions.
