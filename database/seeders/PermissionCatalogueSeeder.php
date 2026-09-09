<?php

namespace Database\Seeders;

use App\Enums\PermissionName;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the permission catalogue.
 *
 * Every {@see PermissionName} case becomes a row. Existing rows are left alone so
 * a label a manager edited through the UI is never overwritten on re-seed. Also
 * ensures a "super-admin" role that carries the `full-access` permission.
 */
class PermissionCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        $guardName = config('auth.defaults.guard', 'web');

        $everyPermissionExists = Permission::whereIn('name', PermissionName::values())->count() === count(PermissionName::cases());

        if ($everyPermissionExists && Role::where('name', 'super-admin')->exists()) {
            return;
        }

        foreach (PermissionName::cases() as $permissionName) {
            $permission = Permission::findOrCreate($permissionName->value, $guardName);

            // Only seed the label when it is still empty so a manager's UI edit survives a re-seed.
            if ($permission->label === null || $permission->label === '') {
                $permission->update(['label' => $permissionName->defaultLabel()]);
            }
        }

        $superAdminRole = Role::findOrCreate('super-admin', $guardName);

        if ($superAdminRole->label === null) {
            $superAdminRole->update(['label' => 'Super administrateur']);
        }

        $superAdminRole->givePermissionTo(PermissionName::FullAccess->value);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
