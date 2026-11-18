<x-back-office.balance-calculator
    class="mt-6"
    :balances="collect($this->caisseRows)->mapWithKeys(fn (array $caisseRow): array => [$caisseRow['id'] => $caisseRow['balance']])->all()"
>
    <div class="flex items-start justify-between gap-4">
        <flux:text>Les tills physiques et leurs soldes</flux:text>

        <div class="flex items-center gap-2">
            @if (count($this->caisseRows) > 0)
                <x-back-office.balance-calculator.toggle />
            @endif

            <flux:button variant="primary" icon="plus" wire:click="openCreateCaisse">
                Nouvelle caisse
            </flux:button>
        </div>
    </div>

    @if (count($this->caisseRows) === 0)
        <flux:callout class="mt-4" icon="information-circle">
            <flux:callout.heading>Aucune caisse</flux:callout.heading>
            <flux:callout.text>Créez une caisse pour commencer à enregistrer des mouvements d'espèces.</flux:callout.text>
        </flux:callout>
    @else
        <x-back-office.balance-calculator.bar noun="caisse" participle="sélectionnée" />

        <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-4">
            @foreach ($this->caisseRows as $caisseRow)
                @php
                    $caisseLogoUrl = match ($caisseRow['code']) {
                        \App\Enums\CaisseCode::Wave->value => asset('images/payments/wave.png'),
                        \App\Enums\CaisseCode::OrangeMoney->value => asset('images/payments/orange-money.png'),
                        default => null,
                    };
                @endphp
                <x-back-office.finance-card
                    wire:key="caisse-{{ $caisseRow['id'] }}"
                    :name="$caisseRow['name']"
                    :balance="$caisseRow['balance']"
                    :logo-url="$caisseLogoUrl"
                    :icon="$caisseLogoUrl || str_contains(mb_strtolower($caisseRow['name']), 'cbao') ? null : 'banknotes'"
                >
                    <x-slot:selector>
                        <x-back-office.balance-calculator.checkbox :id="$caisseRow['id']" :name="$caisseRow['name']" />
                    </x-slot:selector>
                    <x-slot:status>
                        @if ($caisseRow['isLockedForToday'])
                            <flux:badge size="sm" color="amber">Clôturée</flux:badge>
                        @else
                            <flux:badge size="sm" color="green">Ouverte</flux:badge>
                        @endif
                    </x-slot:status>
                    @if ($caisseRow['isSystemCaisse'])
                        <x-slot:subtitle>
                            <flux:badge size="sm" color="blue">Système</flux:badge>
                        </x-slot:subtitle>
                    @endif
                    <x-slot:actions>
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
                    </x-slot:actions>
                </x-back-office.finance-card>
            @endforeach
        </div>
    @endif
</x-back-office.balance-calculator>
