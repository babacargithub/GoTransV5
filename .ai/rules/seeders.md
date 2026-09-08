---
paths:
  - 'app/Models/User.php,app/Livewire/BackOffice/UserAccessManagement.php,database/seeders/PermissionCatalogueSeeder.php'
---

# Seeders

## Roles & permissions run on spatie/laravel-permission
User privileges use spatie/laravel-permission (installed v8.3). Key points:
- The legacy `users.roles` column (PHP-serialized Symfony ROLE_* array, still read by the mobile /login in routes/api.php) was renamed to `users.legacy_roles` so it stops shadowing spatie's `roles()` relation on the User model. `User::resolveRoles()` + ROLE_HIERARCHY are unrelated legacy code kept only for that endpoint.
- App\Models\Permission / App\Models\Role extend the spatie models and add a nullable `label` column = the French public-facing wording shown in the UI. The internal `name` (kebab-case, e.g. `cancel-paid-booking`) is the stable constant guards check and is never edited from the UI. config/permission.php points `models.permission`/`models.role` at these subclasses. `->displayLabel()` falls back to name.
- App\Enums\PermissionName is the canonical catalogue (backing string = internal name) with `defaultLabel()` (seed value only) and `values()`. PermissionCatalogueSeeder (in DatabaseSeeder) upserts every case via findOrCreate and only seeds the label when still empty, so UI edits survive re-seeds. It also ensures a `super-admin` role holding `full-access`.
- Super-admin: AuthServiceProvider registers `Gate::before` returning true when `$user->hasFullAccess()` (holds the `full-access` permission). So every `can()/authorize()/@can` check is implicitly "OR full-access" — guards never name full-access.
- Back office UI: App\Livewire\BackOffice\UserAccessManagement, route back-office.users.index ("Gestion des utilisateurs" under Admin). Two tabs (users / permissions&roles) via a plain `$activeTab` string — Flux free has no tabs component.
- Tests seed PermissionCatalogueSeeder in setUp(). The full test suite needs `memory_limit` > 128M (run `php -d memory_limit=2G vendor/bin/phpunit`); this predates this feature.
