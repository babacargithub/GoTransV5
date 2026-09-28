<div class="mt-6">
    <div class="flex items-start justify-between gap-4">
        <flux:text>Les tills physiques et leurs soldes</flux:text>

        <flux:button variant="primary" icon="plus" wire:click="openCreateCaisse">
            Nouvelle caisse
        </flux:button>
    </div>

    @if (count($this->caisseRows) === 0)
        <flux:callout class="mt-4" icon="information-circle">
            <flux:callout.heading>Aucune caisse</flux:callout.heading>
            <flux:callout.text>Créez une caisse pour commencer à enregistrer des mouvements d'espèces.</flux:callout.text>
        </flux:callout>
    @else
        <div class="mt-4 overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Nom</flux:table.column>
                    <flux:table.column align="end">Solde</flux:table.column>
                    <flux:table.column align="center">Statut</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->caisseRows as $caisseRow)
                        <flux:table.row wire:key="caisse-{{ $caisseRow['id'] }}">
                            <flux:table.cell variant="strong">
                                {{ $caisseRow['name'] }}
                                @if ($caisseRow['isSystemCaisse'])
                                    <flux:badge size="sm" color="blue">Système</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($caisseRow['balance'], 0, ',', ' ') }} FCFA</flux:table.cell>
                            <flux:table.cell align="center">
                                @if ($caisseRow['isLockedForToday'])
                                    <flux:badge size="sm" color="amber">Clôturée aujourd'hui</flux:badge>
                                @else
                                    <flux:badge size="sm" color="green">Ouverte</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex items-center justify-end gap-1">
                                    <flux:tooltip content="Transactions">
                                        <flux:button variant="subtle" size="sm" icon="list-bullet" aria-label="Transactions" wire:click="openCaisseTransactions({{ $caisseRow['id'] }})" />
                                    </flux:tooltip>
                                    <flux:tooltip content="Entrée de caisse">
                                        <flux:button variant="subtle" size="sm" icon="arrow-down-circle" aria-label="Entrée de caisse" class="text-green-600" wire:click="openEntreeDeCaisse({{ $caisseRow['id'] }})" />
                                    </flux:tooltip>
                                    <flux:tooltip content="Sortie de caisse">
                                        <flux:button variant="subtle" size="sm" icon="arrow-up-circle" aria-label="Sortie de caisse" class="text-red-600" wire:click="openSortieDeCaisse({{ $caisseRow['id'] }})" />
                                    </flux:tooltip>
                                    <flux:tooltip content="Transférer vers une autre caisse">
                                        <flux:button variant="subtle" size="sm" icon="arrows-right-left" aria-label="Transférer" wire:click="openCaisseTransfer({{ $caisseRow['id'] }})" />
                                    </flux:tooltip>
                                    <flux:tooltip :content="$caisseRow['isLockedForToday'] ? 'Réouvrir la journée' : 'Clôturer la journée'">
                                        <flux:button variant="subtle" size="sm" :icon="$caisseRow['isLockedForToday'] ? 'lock-open' : 'lock-closed'" aria-label="Clôturer/Réouvrir" wire:click="toggleCaisseDayLock({{ $caisseRow['id'] }})" />
                                    </flux:tooltip>
                                    <x-back-office.edit-button wire:click="openEditCaisse({{ $caisseRow['id'] }})" label="Modifier la caisse" />
                                    <x-back-office.delete-button wire:click="askToDeleteCaisse({{ $caisseRow['id'] }})" label="Supprimer la caisse" />
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</div>
