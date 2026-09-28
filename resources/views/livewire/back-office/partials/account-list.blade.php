<div class="mt-6">
    <div class="flex items-start justify-between gap-4">
        <flux:text>Le plan de comptes, en miroir des caisses</flux:text>

        <flux:button variant="primary" icon="plus" wire:click="openCreateAccount">
            Nouveau compte
        </flux:button>
    </div>

    @if (count($this->accountRows) === 0)
        <flux:callout class="mt-4" icon="information-circle">
            <flux:callout.heading>Aucun compte</flux:callout.heading>
            <flux:callout.text>Créez un compte pour catégoriser les mouvements de caisse.</flux:callout.text>
        </flux:callout>
    @else
        <div class="mt-4 overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Nom</flux:table.column>
                    <flux:table.column>Type</flux:table.column>
                    <flux:table.column align="end">Solde</flux:table.column>
                    <flux:table.column align="center">Statut</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->accountRows as $accountRow)
                        <flux:table.row wire:key="account-{{ $accountRow['id'] }}">
                            <flux:table.cell variant="strong">{{ $accountRow['name'] }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" color="zinc">{{ $accountRow['accountTypeLabel'] }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($accountRow['balance'], 0, ',', ' ') }} FCFA</flux:table.cell>
                            <flux:table.cell align="center">
                                @if ($accountRow['isActive'])
                                    <flux:badge size="sm" color="green">Actif</flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc">Inactif</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex items-center justify-end gap-1">
                                    <flux:tooltip content="Transactions">
                                        <flux:button variant="subtle" size="sm" icon="list-bullet" aria-label="Transactions" wire:click="openAccountTransactions({{ $accountRow['id'] }})" />
                                    </flux:tooltip>
                                    <flux:tooltip content="Transférer">
                                        <flux:button variant="subtle" size="sm" icon="arrows-right-left" aria-label="Transférer" wire:click="openAccountTransfer({{ $accountRow['id'] }})" />
                                    </flux:tooltip>
                                    <x-back-office.edit-button wire:click="openEditAccount({{ $accountRow['id'] }})" label="Modifier le compte" />
                                    <x-back-office.delete-button wire:click="askToDeleteAccount({{ $accountRow['id'] }})" label="Supprimer le compte" />
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</div>
