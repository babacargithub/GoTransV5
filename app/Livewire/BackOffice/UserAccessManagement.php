<?php

namespace App\Livewire\BackOffice;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Back office "Gestion des utilisateurs".
 *
 * Full-page Livewire component with two tabs:
 * - "Utilisateurs": every user (active only by default, "TOUS" reveals inactive
 *   ones) with per-row "Permissions" and "Rôles" dialogs to grant / revoke.
 * - "Permissions & rôles": the permission and role catalogues, each permission
 *   editable (French label only — the internal name is immutable) and deletable,
 *   each role able to receive permissions.
 */
#[Layout('components.layouts.back-office')]
class UserAccessManagement extends Component
{
    /** @var 'users'|'permissions' */
    public string $activeTab = 'users';

    public bool $showInactiveUsers = false;

    // --- User permissions dialog -------------------------------------------------

    public bool $showUserPermissionsModal = false;

    public ?int $managedUserId = null;

    /** @var array<int, string> */
    public array $selectedPermissionNames = [];

    // --- User roles dialog ------------------------------------------------------

    public bool $showUserRolesModal = false;

    /** @var array<int, string> */
    public array $selectedRoleNames = [];

    // --- Permission create / edit / delete -------------------------------------

    public bool $showPermissionModal = false;

    public ?int $editingPermissionId = null;

    public string $permissionName = '';

    public string $permissionLabel = '';

    public bool $showDeletePermissionModal = false;

    public ?int $deletingPermissionId = null;

    // --- Role create / edit / delete + permissions ----------------------------

    public bool $showRoleModal = false;

    public ?int $editingRoleId = null;

    public string $roleName = '';

    public string $roleLabel = '';

    public bool $showDeleteRoleModal = false;

    public ?int $deletingRoleId = null;

    public bool $showRolePermissionsModal = false;

    public ?int $managedRoleId = null;

    /** @var array<int, string> */
    public array $selectedRolePermissionNames = [];

    public function showAllUsers(): void
    {
        $this->showInactiveUsers = true;
        unset($this->userRows);
    }

    public function showActiveUsersOnly(): void
    {
        $this->showInactiveUsers = false;
        unset($this->userRows);
    }

    /**
     * Users for the table.
     *
     * @return array<int, array{id: int, name: string, username: string|null, email: string|null, isActive: bool, roleLabels: array<int, string>}>
     */
    #[Computed]
    public function userRows(): array
    {
        return User::query()
            ->when(! $this->showInactiveUsers, fn ($query) => $query->where('active', true))
            ->with('roles:id,name,label')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => (string) $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'isActive' => (bool) $user->active,
                'roleLabels' => $user->roles
                    ->map(fn (Role $role): string => $role->displayLabel())
                    ->all(),
            ])
            ->all();
    }

    /**
     * The whole permission catalogue, ordered by label.
     *
     * @return array<int, array{id: int, name: string, label: string}>
     */
    #[Computed]
    public function permissionCatalogue(): array
    {
        return Permission::query()
            ->orderBy('label')
            ->get()
            ->map(fn (Permission $permission): array => [
                'id' => $permission->id,
                'name' => $permission->name,
                'label' => $permission->displayLabel(),
            ])
            ->all();
    }

    /**
     * The whole role catalogue with its permission count.
     *
     * @return array<int, array{id: int, name: string, label: string, permissionCount: int}>
     */
    #[Computed]
    public function roleCatalogue(): array
    {
        return Role::query()
            ->withCount('permissions')
            ->orderBy('label')
            ->get()
            ->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $role->displayLabel(),
                'permissionCount' => (int) $role->permissions_count,
            ])
            ->all();
    }

    // --- User: permissions ----------------------------------------------------

    public function openUserPermissions(int $userId): void
    {
        $user = User::findOrFail($userId);

        $this->managedUserId = $user->id;
        $this->selectedPermissionNames = $user->getDirectPermissions()
            ->pluck('name')
            ->all();
        $this->showUserPermissionsModal = true;
    }

    public function managedUserName(): ?string
    {
        return $this->managedUserId === null
            ? null
            : User::find($this->managedUserId)?->name;
    }

    public function saveUserPermissions(): void
    {
        if ($this->managedUserId === null) {
            return;
        }

        $user = User::findOrFail($this->managedUserId);
        $user->syncPermissions($this->selectedPermissionNames);

        unset($this->userRows);
        $this->showUserPermissionsModal = false;
        session()->flash('status', 'Les permissions de « '.$user->name.' » ont été mises à jour.');
    }

    // --- User: roles --------------------------------------------------------

    public function openUserRoles(int $userId): void
    {
        $user = User::findOrFail($userId);

        $this->managedUserId = $user->id;
        $this->selectedRoleNames = $user->roles->pluck('name')->all();
        $this->showUserRolesModal = true;
    }

    public function saveUserRoles(): void
    {
        if ($this->managedUserId === null) {
            return;
        }

        $user = User::findOrFail($this->managedUserId);
        $user->syncRoles($this->selectedRoleNames);

        unset($this->userRows);
        $this->showUserRolesModal = false;
        session()->flash('status', 'Les rôles de « '.$user->name.' » ont été mis à jour.');
    }

    // --- Permission: create / edit -----------------------------------------

    public function openCreatePermission(): void
    {
        $this->resetPermissionForm();
        $this->editingPermissionId = null;
        $this->showPermissionModal = true;
    }

    public function openEditPermission(int $permissionId): void
    {
        $permission = Permission::findOrFail($permissionId);

        $this->editingPermissionId = $permission->id;
        $this->permissionName = $permission->name;
        $this->permissionLabel = (string) $permission->label;
        $this->resetValidation();
        $this->showPermissionModal = true;
    }

    private function resetPermissionForm(): void
    {
        $this->permissionName = '';
        $this->permissionLabel = '';
        $this->resetValidation();
    }

    public function savePermission(): void
    {
        if ($this->editingPermissionId === null) {
            $validated = $this->validate([
                'permissionName' => ['required', 'string', 'max:125', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('permissions', 'name')],
                'permissionLabel' => ['required', 'string', 'max:255'],
            ], attributes: ['permissionName' => 'nom interne', 'permissionLabel' => 'libellé']);

            Permission::create([
                'name' => $validated['permissionName'],
                'label' => $validated['permissionLabel'],
                'guard_name' => config('auth.defaults.guard', 'web'),
            ]);

            $statusMessage = 'La permission « '.$validated['permissionLabel'].' » a été créée.';
        } else {
            // The internal name is immutable; only the French label can change.
            $validated = $this->validate([
                'permissionLabel' => ['required', 'string', 'max:255'],
            ], attributes: ['permissionLabel' => 'libellé']);

            Permission::findOrFail($this->editingPermissionId)->update(['label' => $validated['permissionLabel']]);
            $statusMessage = 'Le libellé de la permission a été mis à jour.';
        }

        unset($this->permissionCatalogue, $this->roleCatalogue);
        $this->showPermissionModal = false;
        session()->flash('status', $statusMessage);
    }

    // --- Permission: delete ------------------------------------------------

    public function askToDeletePermission(int $permissionId): void
    {
        $this->deletingPermissionId = $permissionId;
        $this->showDeletePermissionModal = true;
    }

    public function deletePermissionLabel(): ?string
    {
        return $this->deletingPermissionId === null
            ? null
            : Permission::find($this->deletingPermissionId)?->displayLabel();
    }

    public function confirmDeletePermission(): void
    {
        $permissionId = $this->deletingPermissionId;
        $this->showDeletePermissionModal = false;
        $this->deletingPermissionId = null;

        if ($permissionId === null) {
            return;
        }

        $permission = Permission::findOrFail($permissionId);
        $permissionLabel = $permission->displayLabel();
        $permission->delete();

        unset($this->permissionCatalogue, $this->roleCatalogue, $this->userRows);
        session()->flash('status', 'La permission « '.$permissionLabel.' » a été supprimée.');
    }

    // --- Role: create / edit ---------------------------------------------

    public function openCreateRole(): void
    {
        $this->roleName = '';
        $this->roleLabel = '';
        $this->editingRoleId = null;
        $this->resetValidation();
        $this->showRoleModal = true;
    }

    public function openEditRole(int $roleId): void
    {
        $role = Role::findOrFail($roleId);

        $this->editingRoleId = $role->id;
        $this->roleName = $role->name;
        $this->roleLabel = (string) $role->label;
        $this->resetValidation();
        $this->showRoleModal = true;
    }

    public function saveRole(): void
    {
        if ($this->editingRoleId === null) {
            $validated = $this->validate([
                'roleName' => ['required', 'string', 'max:125', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('roles', 'name')],
                'roleLabel' => ['required', 'string', 'max:255'],
            ], attributes: ['roleName' => 'nom interne', 'roleLabel' => 'libellé']);

            Role::create([
                'name' => $validated['roleName'],
                'label' => $validated['roleLabel'],
                'guard_name' => config('auth.defaults.guard', 'web'),
            ]);

            $statusMessage = 'Le rôle « '.$validated['roleLabel'].' » a été créé.';
        } else {
            $validated = $this->validate([
                'roleLabel' => ['required', 'string', 'max:255'],
            ], attributes: ['roleLabel' => 'libellé']);

            Role::findOrFail($this->editingRoleId)->update(['label' => $validated['roleLabel']]);
            $statusMessage = 'Le libellé du rôle a été mis à jour.';
        }

        unset($this->roleCatalogue);
        $this->showRoleModal = false;
        session()->flash('status', $statusMessage);
    }

    // --- Role: delete --------------------------------------------------

    public function askToDeleteRole(int $roleId): void
    {
        $this->deletingRoleId = $roleId;
        $this->showDeleteRoleModal = true;
    }

    public function deleteRoleLabel(): ?string
    {
        return $this->deletingRoleId === null
            ? null
            : Role::find($this->deletingRoleId)?->displayLabel();
    }

    public function confirmDeleteRole(): void
    {
        $roleId = $this->deletingRoleId;
        $this->showDeleteRoleModal = false;
        $this->deletingRoleId = null;

        if ($roleId === null) {
            return;
        }

        $role = Role::findOrFail($roleId);
        $roleLabel = $role->displayLabel();
        $role->delete();

        unset($this->roleCatalogue, $this->userRows);
        session()->flash('status', 'Le rôle « '.$roleLabel.' » a été supprimé.');
    }

    // --- Role: permissions -------------------------------------------

    public function openRolePermissions(int $roleId): void
    {
        $role = Role::findOrFail($roleId);

        $this->managedRoleId = $role->id;
        $this->selectedRolePermissionNames = $role->permissions->pluck('name')->all();
        $this->showRolePermissionsModal = true;
    }

    public function managedRoleName(): ?string
    {
        return $this->managedRoleId === null
            ? null
            : Role::find($this->managedRoleId)?->displayLabel();
    }

    public function saveRolePermissions(): void
    {
        if ($this->managedRoleId === null) {
            return;
        }

        $role = Role::findOrFail($this->managedRoleId);
        $role->syncPermissions($this->selectedRolePermissionNames);

        unset($this->roleCatalogue, $this->userRows);
        $this->showRolePermissionsModal = false;
        session()->flash('status', 'Les permissions du rôle « '.$role->displayLabel().' » ont été mises à jour.');
    }

    public function render(): View
    {
        return view('livewire.back-office.user-access-management')
            ->title('Gestion des utilisateurs — Back Office');
    }
}
