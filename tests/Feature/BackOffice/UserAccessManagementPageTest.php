<?php

namespace Tests\Feature\BackOffice;

use App\Enums\PermissionName;
use App\Livewire\BackOffice\UserAccessManagement;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class UserAccessManagementPageTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionCatalogueSeeder::class);
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('back-office.users.index'))->assertRedirect(route('login'));
    }

    public function test_the_users_tab_shows_active_users_only_until_tous_is_pressed(): void
    {
        $activeUser = User::factory()->create(['name' => 'Actif Diallo', 'active' => true]);
        $inactiveUser = User::factory()->create(['name' => 'Inactif Sow', 'active' => false]);

        Livewire::actingAs(User::factory()->create())
            ->test(UserAccessManagement::class)
            ->assertSee($activeUser->name)
            ->assertDontSee($inactiveUser->name)
            ->call('showAllUsers')
            ->assertSet('showInactiveUsers', true)
            ->assertSee($inactiveUser->name)
            ->call('showActiveUsersOnly')
            ->assertDontSee($inactiveUser->name);
    }

    public function test_granting_direct_permissions_to_a_user_persists(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs(User::factory()->create())
            ->test(UserAccessManagement::class)
            ->call('openUserPermissions', $user->id)
            ->assertSet('showUserPermissionsModal', true)
            ->set('selectedPermissionNames', [PermissionName::CancelPaidBooking->value, PermissionName::RefundTicket->value])
            ->call('saveUserPermissions')
            ->assertSet('showUserPermissionsModal', false);

        $user->unsetRelation('permissions');
        $this->assertTrue($user->hasDirectPermission(PermissionName::CancelPaidBooking->value));
        $this->assertTrue($user->hasDirectPermission(PermissionName::RefundTicket->value));
    }

    public function test_assigning_a_role_to_a_user_persists(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'agent-guichet', 'label' => 'Agent guichet', 'guard_name' => 'web']);

        Livewire::actingAs(User::factory()->create())
            ->test(UserAccessManagement::class)
            ->call('openUserRoles', $user->id)
            ->set('selectedRoleNames', [$role->name])
            ->call('saveUserRoles');

        $this->assertTrue($user->fresh()->hasRole('agent-guichet'));
    }

    public function test_editing_a_permission_changes_only_its_label(): void
    {
        $permission = Permission::where('name', PermissionName::CancelPaidBooking->value)->firstOrFail();

        Livewire::actingAs(User::factory()->create())
            ->test(UserAccessManagement::class)
            ->call('openEditPermission', $permission->id)
            ->set('permissionLabel', 'Annuler un billet réglé')
            ->call('savePermission')
            ->assertHasNoErrors();

        $fresh = $permission->fresh();
        $this->assertSame('Annuler un billet réglé', $fresh->label);
        $this->assertSame(PermissionName::CancelPaidBooking->value, $fresh->name);
    }

    public function test_creating_a_permission_requires_a_kebab_case_name(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(UserAccessManagement::class)
            ->call('openCreatePermission')
            ->set('permissionName', 'Not Kebab')
            ->set('permissionLabel', 'Test')
            ->call('savePermission')
            ->assertHasErrors(['permissionName']);
    }

    public function test_creating_and_deleting_a_permission(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(UserAccessManagement::class)
            ->call('openCreatePermission')
            ->set('permissionName', 'view-reports')
            ->set('permissionLabel', 'Consulter les rapports')
            ->call('savePermission')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('permissions', ['name' => 'view-reports', 'label' => 'Consulter les rapports']);

        $created = Permission::where('name', 'view-reports')->firstOrFail();

        Livewire::actingAs(User::factory()->create())
            ->test(UserAccessManagement::class)
            ->call('askToDeletePermission', $created->id)
            ->assertSet('showDeletePermissionModal', true)
            ->call('confirmDeletePermission');

        $this->assertDatabaseMissing('permissions', ['id' => $created->id]);
    }

    public function test_giving_permissions_to_a_role_persists(): void
    {
        $role = Role::create(['name' => 'superviseur', 'label' => 'Superviseur', 'guard_name' => 'web']);

        Livewire::actingAs(User::factory()->create())
            ->test(UserAccessManagement::class)
            ->call('openRolePermissions', $role->id)
            ->set('selectedRolePermissionNames', [PermissionName::FreezeDepart->value, PermissionName::UnfreezeDepart->value])
            ->call('saveRolePermissions');

        $this->assertTrue($role->fresh()->hasPermissionTo(PermissionName::FreezeDepart->value));
        $this->assertTrue($role->fresh()->hasPermissionTo(PermissionName::UnfreezeDepart->value));
    }

    public function test_creating_and_deleting_a_role(): void
    {
        $component = Livewire::actingAs(User::factory()->create())
            ->test(UserAccessManagement::class)
            ->call('openCreateRole')
            ->set('roleName', 'chef-de-gare')
            ->set('roleLabel', 'Chef de gare')
            ->call('saveRole')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('roles', ['name' => 'chef-de-gare', 'label' => 'Chef de gare']);

        $created = Role::where('name', 'chef-de-gare')->firstOrFail();

        $component->call('askToDeleteRole', $created->id)
            ->call('confirmDeleteRole');

        $this->assertDatabaseMissing('roles', ['id' => $created->id]);
    }

    public function test_a_full_access_user_passes_every_ability_check(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->givePermissionTo(PermissionName::FullAccess->value);

        $this->assertTrue($superAdmin->hasFullAccess());
        $this->assertTrue($superAdmin->can('cancel-paid-booking'));
        $this->assertTrue($superAdmin->can('anything-not-even-defined'));

        $plainUser = User::factory()->create();
        $this->assertFalse($plainUser->can('cancel-paid-booking'));
    }
}
