<div class="mx-auto w-full max-w-5xl">
    <div>
        <flux:heading size="xl" level="1">Gestion des utilisateurs</flux:heading>
        <flux:text class="mt-1">Les comptes, leurs rôles et leurs permissions</flux:text>
    </div>

    @if (session('status'))
        <flux:callout class="mt-4" variant="success" icon="check-circle">
            <flux:callout.text>{{ session('status') }}</flux:callout.text>
        </flux:callout>
    @endif

    @if (session('error'))
        <flux:callout class="mt-4" variant="danger" icon="exclamation-triangle">
            <flux:callout.text>{{ session('error') }}</flux:callout.text>
        </flux:callout>
    @endif

    {{-- Tabs --}}
    <div class="mt-6 flex gap-1 border-b border-zinc-200 dark:border-zinc-700">
        <button
            type="button"
            wire:click="$set('activeTab', 'users')"
            @class([
                'px-4 py-2 text-sm font-medium border-b-2 -mb-px',
                'border-accent text-accent' => $activeTab === 'users',
                'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' => $activeTab !== 'users',
            ])
        >
            Utilisateurs
        </button>
        <button
            type="button"
            wire:click="$set('activeTab', 'permissions')"
            @class([
                'px-4 py-2 text-sm font-medium border-b-2 -mb-px',
                'border-accent text-accent' => $activeTab === 'permissions',
                'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' => $activeTab !== 'permissions',
            ])
        >
            Permissions &amp; rôles
        </button>
    </div>

    {{-- ============================ USERS TAB ============================ --}}
    @if ($activeTab === 'users')
        <div class="mt-6">
            <div class="flex items-center justify-between gap-4">
                <flux:text>
                    {{ $showInactiveUsers ? 'Tous les utilisateurs' : 'Utilisateurs actifs' }}
                </flux:text>

                @if ($showInactiveUsers)
                    <flux:button size="sm" variant="subtle" icon="funnel" wire:click="showActiveUsersOnly">
                        Actifs uniquement
                    </flux:button>
                @else
                    <flux:button size="sm" variant="subtle" wire:click="showAllUsers">
                        TOUS
                    </flux:button>
                @endif
            </div>

            <div class="mt-4 overflow-x-auto">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Nom</flux:table.column>
                        <flux:table.column>Identifiant</flux:table.column>
                        <flux:table.column>Rôles</flux:table.column>
                        <flux:table.column align="center">Statut</flux:table.column>
                        <flux:table.column />
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse ($this->userRows as $userRow)
                            <flux:table.row wire:key="user-{{ $userRow['id'] }}">
                                <flux:table.cell variant="strong">{{ $userRow['name'] }}</flux:table.cell>
                                <flux:table.cell>{{ $userRow['username'] ?? '—' }}</flux:table.cell>
                                <flux:table.cell>
                                    <div class="flex flex-wrap gap-1">
                                        @forelse ($userRow['roleLabels'] as $roleLabel)
                                            <flux:badge size="sm" color="purple">{{ $roleLabel }}</flux:badge>
                                        @empty
                                            <flux:text class="text-sm text-zinc-400">—</flux:text>
                                        @endforelse
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell align="center">
                                    <flux:badge size="sm" :color="$userRow['isActive'] ? 'green' : 'zinc'">
                                        {{ $userRow['isActive'] ? 'Actif' : 'Inactif' }}
                                    </flux:badge>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <div class="flex items-center justify-end gap-2">
                                        <flux:button
                                            size="sm"
                                            variant="filled"
                                            icon="key"
                                            wire:click="openUserPermissions({{ $userRow['id'] }})"
                                        >
                                            Permissions
                                        </flux:button>
                                        <flux:button
                                            size="sm"
                                            variant="filled"
                                            icon="user-group"
                                            wire:click="openUserRoles({{ $userRow['id'] }})"
                                        >
                                            Rôles
                                        </flux:button>
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="5">
                                    <flux:text class="text-zinc-400">Aucun utilisateur.</flux:text>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>
        </div>
    @endif

    {{-- ====================== PERMISSIONS & ROLES TAB ====================== --}}
    @if ($activeTab === 'permissions')
        <div class="mt-6 space-y-10">
            {{-- Permissions --}}
            <div>
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <flux:heading size="lg">Permissions</flux:heading>
                        <flux:text class="mt-1">Le libellé est l'intitulé public ; le nom interne ne change jamais.</flux:text>
                    </div>
                    <flux:button size="sm" variant="primary" icon="plus" wire:click="openCreatePermission">
                        Ajouter une permission
                    </flux:button>
                </div>

                <div class="mt-4 overflow-x-auto">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Libellé</flux:table.column>
                            <flux:table.column>Nom interne</flux:table.column>
                            <flux:table.column />
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($this->permissionCatalogue as $permissionRow)
                                <flux:table.row wire:key="permission-{{ $permissionRow['id'] }}">
                                    <flux:table.cell variant="strong">{{ $permissionRow['label'] }}</flux:table.cell>
                                    <flux:table.cell>
                                        <flux:badge size="sm" color="zinc" class="font-mono">{{ $permissionRow['name'] }}</flux:badge>
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <div class="flex items-center justify-end gap-2">
                                            <x-back-office.edit-button
                                                wire:click="openEditPermission({{ $permissionRow['id'] }})"
                                                label="Modifier le libellé"
                                            />
                                            <x-back-office.delete-button
                                                wire:click="askToDeletePermission({{ $permissionRow['id'] }})"
                                                label="Supprimer la permission"
                                            />
                                        </div>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            </div>

            {{-- Roles --}}
            <div>
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <flux:heading size="lg">Rôles</flux:heading>
                        <flux:text class="mt-1">Un rôle regroupe des permissions attribuées d'un bloc.</flux:text>
                    </div>
                    <flux:button size="sm" variant="primary" icon="plus" wire:click="openCreateRole">
                        Ajouter un rôle
                    </flux:button>
                </div>

                <div class="mt-4 overflow-x-auto">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Libellé</flux:table.column>
                            <flux:table.column>Nom interne</flux:table.column>
                            <flux:table.column align="center">Permissions</flux:table.column>
                            <flux:table.column />
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($this->roleCatalogue as $roleRow)
                                <flux:table.row wire:key="role-{{ $roleRow['id'] }}">
                                    <flux:table.cell variant="strong">{{ $roleRow['label'] }}</flux:table.cell>
                                    <flux:table.cell>
                                        <flux:badge size="sm" color="zinc" class="font-mono">{{ $roleRow['name'] }}</flux:badge>
                                    </flux:table.cell>
                                    <flux:table.cell align="center">{{ $roleRow['permissionCount'] }}</flux:table.cell>
                                    <flux:table.cell>
                                        <div class="flex items-center justify-end gap-2">
                                            <flux:button
                                                size="sm"
                                                variant="filled"
                                                icon="key"
                                                wire:click="openRolePermissions({{ $roleRow['id'] }})"
                                            >
                                                Permissions
                                            </flux:button>
                                            <x-back-office.edit-button
                                                wire:click="openEditRole({{ $roleRow['id'] }})"
                                                label="Modifier le libellé"
                                            />
                                            <x-back-office.delete-button
                                                wire:click="askToDeleteRole({{ $roleRow['id'] }})"
                                                label="Supprimer le rôle"
                                            />
                                        </div>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            </div>
        </div>
    @endif

    {{-- ============================ MODALS ============================ --}}

    {{-- User permissions --}}
    <flux:modal wire:model.self="showUserPermissionsModal" wire:key="user-permissions-modal" class="w-full max-w-lg">
        <form wire:submit="saveUserPermissions" class="space-y-6">
            <div>
                <flux:heading size="lg">Permissions de « {{ $this->managedUserName() }} »</flux:heading>
                <flux:text class="mt-1">Permissions attribuées directement à cet utilisateur (hors rôles).</flux:text>
            </div>

            <flux:checkbox.group wire:model="selectedPermissionNames" class="max-h-80 space-y-3 overflow-y-auto">
                @foreach ($this->permissionCatalogue as $permissionRow)
                    <flux:checkbox :value="$permissionRow['name']" :label="$permissionRow['label']" />
                @endforeach
            </flux:checkbox.group>

            <div class="flex items-center justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showUserPermissionsModal', false)">Annuler</flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveUserPermissions">
                    Enregistrer
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- User roles --}}
    <flux:modal wire:model.self="showUserRolesModal" wire:key="user-roles-modal" class="w-full max-w-lg">
        <form wire:submit="saveUserRoles" class="space-y-6">
            <div>
                <flux:heading size="lg">Rôles de « {{ $this->managedUserName() }} »</flux:heading>
            </div>

            @if (count($this->roleCatalogue) === 0)
                <flux:callout icon="information-circle">
                    <flux:callout.text>Aucun rôle n'existe encore. Créez-en un dans l'onglet « Permissions &amp; rôles ».</flux:callout.text>
                </flux:callout>
            @else
                <flux:checkbox.group wire:model="selectedRoleNames" class="max-h-80 space-y-3 overflow-y-auto">
                    @foreach ($this->roleCatalogue as $roleRow)
                        <flux:checkbox :value="$roleRow['name']" :label="$roleRow['label']" />
                    @endforeach
                </flux:checkbox.group>
            @endif

            <div class="flex items-center justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showUserRolesModal', false)">Annuler</flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveUserRoles">
                    Enregistrer
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Permission create / edit --}}
    <flux:modal wire:model.self="showPermissionModal" wire:key="permission-modal" class="w-full max-w-md">
        <form wire:submit="savePermission" class="space-y-6">
            <flux:heading size="lg">
                {{ $editingPermissionId ? 'Modifier la permission' : 'Ajouter une permission' }}
            </flux:heading>

            <flux:field>
                <flux:label>Nom interne</flux:label>
                <flux:input wire:model="permissionName" :disabled="(bool) $editingPermissionId" placeholder="cancel-paid-booking" />
                <flux:description>En minuscules, séparé par des tirets. Immuable une fois créé.</flux:description>
                <flux:error name="permissionName" />
            </flux:field>

            <flux:field>
                <flux:label>Libellé (français)</flux:label>
                <flux:input wire:model="permissionLabel" placeholder="Annuler une réservation payée" />
                <flux:error name="permissionLabel" />
            </flux:field>

            <div class="flex items-center justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showPermissionModal', false)">Annuler</flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="savePermission">
                    Enregistrer
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Permission delete --}}
    <flux:modal wire:model.self="showDeletePermissionModal" wire:key="delete-permission-modal" class="min-w-[22rem] max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Supprimer cette permission ?</flux:heading>
                <flux:text class="mt-2">
                    « {{ $this->deletePermissionLabel() }} » sera retirée de tous les utilisateurs et rôles. Cette action est irréversible.
                </flux:text>
            </div>
            <div class="flex items-center justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showDeletePermissionModal', false)">Retour</flux:button>
                <flux:button variant="danger" wire:click="confirmDeletePermission" wire:loading.attr="disabled" wire:target="confirmDeletePermission">
                    Supprimer
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Role create / edit --}}
    <flux:modal wire:model.self="showRoleModal" wire:key="role-modal" class="w-full max-w-md">
        <form wire:submit="saveRole" class="space-y-6">
            <flux:heading size="lg">
                {{ $editingRoleId ? 'Modifier le rôle' : 'Ajouter un rôle' }}
            </flux:heading>

            <flux:field>
                <flux:label>Nom interne</flux:label>
                <flux:input wire:model="roleName" :disabled="(bool) $editingRoleId" placeholder="chef-de-gare" />
                <flux:description>En minuscules, séparé par des tirets. Immuable une fois créé.</flux:description>
                <flux:error name="roleName" />
            </flux:field>

            <flux:field>
                <flux:label>Libellé (français)</flux:label>
                <flux:input wire:model="roleLabel" placeholder="Chef de gare" />
                <flux:error name="roleLabel" />
            </flux:field>

            <div class="flex items-center justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showRoleModal', false)">Annuler</flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveRole">
                    Enregistrer
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Role delete --}}
    <flux:modal wire:model.self="showDeleteRoleModal" wire:key="delete-role-modal" class="min-w-[22rem] max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Supprimer ce rôle ?</flux:heading>
                <flux:text class="mt-2">
                    « {{ $this->deleteRoleLabel() }} » sera retiré de tous les utilisateurs. Cette action est irréversible.
                </flux:text>
            </div>
            <div class="flex items-center justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showDeleteRoleModal', false)">Retour</flux:button>
                <flux:button variant="danger" wire:click="confirmDeleteRole" wire:loading.attr="disabled" wire:target="confirmDeleteRole">
                    Supprimer
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Role permissions --}}
    <flux:modal wire:model.self="showRolePermissionsModal" wire:key="role-permissions-modal" class="w-full max-w-lg">
        <form wire:submit="saveRolePermissions" class="space-y-6">
            <div>
                <flux:heading size="lg">Permissions du rôle « {{ $this->managedRoleName() }} »</flux:heading>
            </div>

            <flux:checkbox.group wire:model="selectedRolePermissionNames" class="max-h-80 space-y-3 overflow-y-auto">
                @foreach ($this->permissionCatalogue as $permissionRow)
                    <flux:checkbox :value="$permissionRow['name']" :label="$permissionRow['label']" />
                @endforeach
            </flux:checkbox.group>

            <div class="flex items-center justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showRolePermissionsModal', false)">Annuler</flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveRolePermissions">
                    Enregistrer
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
