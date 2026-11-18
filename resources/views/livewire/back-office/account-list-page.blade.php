<x-back-office.balance-calculator
    class="mx-auto w-full max-w-7xl"
    :balances="collect($this->accountRows)->mapWithKeys(fn (array $accountRow): array => [$accountRow['id'] => $accountRow['balance']])->all()"
>
    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Comptes</flux:heading>
            <flux:text class="mt-1">Le plan de comptes, en miroir des caisses</flux:text>
        </div>

        <div class="flex items-center gap-2">
            @if (count($this->accountRows) > 0)
                <x-back-office.balance-calculator.toggle />
            @endif

            <flux:button variant="primary" icon="plus" wire:click="openCreateAccount">
                Nouveau compte
            </flux:button>
        </div>
    </div>

    <x-flash-toasts :status="$flashStatusMessage" :error="$flashErrorMessage" />

    @if (count($this->accountRows) === 0)
        <flux:callout class="mt-6" icon="information-circle">
            <flux:callout.heading>Aucun compte</flux:callout.heading>
            <flux:callout.text>Créez un compte pour catégoriser les mouvements de caisse.</flux:callout.text>
        </flux:callout>
    @else
        <x-back-office.balance-calculator.bar noun="compte" participle="sélectionné" />

        <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-4">
            @foreach ($this->accountRows as $accountRow)
                <x-back-office.finance-card wire:key="account-{{ $accountRow['id'] }}" :name="$accountRow['name']" :balance="$accountRow['balance']">
                    <x-slot:selector>
                        <x-back-office.balance-calculator.checkbox :id="$accountRow['id']" :name="$accountRow['name']" />
                    </x-slot:selector>
                    <x-slot:status>
                        @if ($accountRow['isActive'])
                            <flux:badge size="sm" color="green">Actif</flux:badge>
                        @else
                            <flux:badge size="sm" color="zinc">Inactif</flux:badge>
                        @endif
                    </x-slot:status>
                    <x-slot:subtitle>
                        <flux:badge size="sm" color="zinc">{{ $accountRow['accountTypeLabel'] }}</flux:badge>
                    </x-slot:subtitle>
                    <x-slot:actions>
                        <flux:tooltip content="Transactions">
                            <flux:button variant="subtle" size="sm" icon="list-bullet" aria-label="Transactions" wire:click="openAccountTransactions({{ $accountRow['id'] }})" />
                        </flux:tooltip>
                        <flux:tooltip content="Transférer">
                            <flux:button variant="subtle" size="sm" icon="arrows-right-left" aria-label="Transférer" wire:click="openAccountTransfer({{ $accountRow['id'] }})" />
                        </flux:tooltip>
                        <x-back-office.edit-button wire:click="openEditAccount({{ $accountRow['id'] }})" label="Modifier le compte" />
                        <x-back-office.delete-button
                            wire:click="askToDeleteAccount({{ $accountRow['id'] }})"
                            :disabled="$accountRow['balance'] !== 0"
                            :label="$accountRow['balance'] !== 0 ? 'Videz le solde avant de supprimer ce compte' : 'Supprimer le compte'"
                        />
                    </x-slot:actions>
                </x-back-office.finance-card>
            @endforeach
        </div>
    @endif

    @include('livewire.back-office.partials.account-modals')
</x-back-office.balance-calculator>
