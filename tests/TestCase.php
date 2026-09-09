<?php

namespace Tests;

use App\Enums\PermissionName;
use App\Models\User;
use Database\Seeders\PermissionCatalogueSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Seed the permission catalogue (idempotent — safe to call from any test).
     */
    protected function seedPermissionCatalogue(): void
    {
        $this->seed(PermissionCatalogueSeeder::class);
    }

    /**
     * A user that passes every permission check, for tests that exercise a
     * sensitive back-office / API action without being about authorization.
     */
    protected function createUserWithFullAccess(): User
    {
        return $this->createUserWithPermissions([PermissionName::FullAccess->value]);
    }

    /**
     * A user granted exactly the given permission names.
     *
     * @param  array<int, string>  $permissionNames
     */
    protected function createUserWithPermissions(array $permissionNames): User
    {
        $this->seedPermissionCatalogue();

        $user = User::factory()->create();
        $user->givePermissionTo($permissionNames);

        return $user;
    }
}
