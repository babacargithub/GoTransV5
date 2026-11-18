<div class="mx-auto w-full max-w-3xl">
    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Types de dépense</flux:heading>
            <flux:text class="mt-1">Les catégories sous lesquelles chaque sortie de caisse est classée</flux:text>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="openCreateExpenseType">
            Nouveau type
        </flux:button>
    </div>

    <x-flash-toasts />

    <flux:separator class="my-6" variant="subtle" />

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Nom</flux:table.column>
                <flux:table.column align="center">Opérations</flux:table.column>
                <flux:table.column align="center">Planifié chaque mois</flux:table.column>
                <flux:table.column align="center">Actif</flux:table.column>
                <flux:table.column />
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->expenseTypeRows as $expenseTypeRow)
                    <flux:table.row wire:key="expense-type-{{ $expenseTypeRow['id'] }}">
                        <flux:table.cell variant="strong">
                            {{ $expenseTypeRow['name'] }}
                            @if ($expenseTypeRow['isSystem'])
                                <flux:badge size="sm" color="blue">Système</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell align="center">{{ $expenseTypeRow['transactionsCount'] }}</flux:table.cell>
                        <flux:table.cell align="center">
                            <flux:switch
                                :checked="$expenseTypeRow['isPlannedMonthly']"
                                wire:click="toggleExpenseTypePlannedMonthly({{ $expenseTypeRow['id'] }})"
                                aria-label="Planifier ce type chaque mois"
                            />
                        </flux:table.cell>
                        <flux:table.cell align="center">
                            <flux:switch
                                :checked="$expenseTypeRow['isActive']"
                                wire:click="toggleExpenseTypeActive({{ $expenseTypeRow['id'] }})"
                                aria-label="Activer ou désactiver ce type"
                            />
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex items-center justify-end gap-2">
                                <x-back-office.edit-button
                                    wire:click="openEditExpenseType({{ $expenseTypeRow['id'] }})"
                                    label="Renommer le type"
                                />
                                @if ($expenseTypeRow['canBeDeleted'])
                                    <x-back-office.delete-button
                                        wire:click="askToDeleteExpenseType({{ $expenseTypeRow['id'] }})"
                                        label="Supprimer le type"
                                    />
                                @endif
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:text size="sm" class="mt-4 text-zinc-500">
        Un type désactivé n'est plus proposé dans la sortie de caisse, mais reste visible dans les bilans. Seuls les types jamais utilisés peuvent être supprimés. Les types « planifiés chaque mois » apparaissent dans la grille de Gestion dépenses.
    </flux:text>

    {{-- Créer / renommer --}}
    <flux:modal wire:model.self="showExpenseTypeModal" wire:key="expense-type-modal" class="w-full max-w-md">
        <form wire:submit="saveExpenseType" class="space-y-6">
            <flux:heading size="lg">{{ $editingExpenseTypeId ? 'Renommer le type de dépense' : 'Nouveau type de dépense' }}</flux:heading>

            <flux:field>
                <flux:label>Nom</flux:label>
                <flux:input wire:model="expenseTypeName" placeholder="Ex : Airtime" autofocus />
                <flux:error name="expenseTypeName" />
            </flux:field>

            <div class="flex items-center justify-end gap-2">
                <flux:button variant="ghost" wire:click="closeExpenseTypeModal">Annuler</flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveExpenseType">Enregistrer</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Supprimer --}}
    <flux:modal wire:model.self="showDeleteExpenseTypeModal" wire:key="delete-expense-type-modal" class="min-w-[22rem] max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Supprimer ce type ?</flux:heading>
                <flux:text class="mt-2">Voulez-vous vraiment supprimer « {{ $this->deleteExpenseTypeLabel() }} » ?</flux:text>
            </div>

            <div class="flex items-center justify-end gap-2">
                <flux:button variant="ghost" wire:click="closeDeleteExpenseTypeModal">Retour</flux:button>
                <flux:button variant="danger" wire:click="confirmDeleteExpenseType" wire:loading.attr="disabled" wire:target="confirmDeleteExpenseType">Supprimer</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
