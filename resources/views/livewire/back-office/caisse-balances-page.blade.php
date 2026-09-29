@php
    $paymentMethodLabels = [
        'cash' => 'Espèces',
        'especes' => 'Espèces',
        'om' => 'Orange Money',
        'wave' => 'Wave',
        'card' => 'Carte bancaire',
    ];
@endphp

<div class="mx-auto w-full max-w-5xl">
    <flux:heading size="xl" level="1">Caisses</flux:heading>
    <flux:text class="mt-1">Tills, comptes et recettes de billets</flux:text>

    @if ($flashStatusMessage)
        <flux:callout class="mt-4" variant="success" icon="check-circle">
            <flux:callout.text>{{ $flashStatusMessage }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($flashErrorMessage)
        <flux:callout class="mt-4" variant="danger" icon="exclamation-triangle">
            <flux:callout.text>{{ $flashErrorMessage }}</flux:callout.text>
        </flux:callout>
    @endif

    {{-- Tabs --}}
    <div class="mt-6 flex gap-1 border-b border-zinc-200 dark:border-zinc-700">
        <button
            type="button"
            wire:click="$set('activeTab', 'apercu')"
            @class([
                'px-4 py-2 text-sm font-medium border-b-2 -mb-px',
                'border-accent text-accent' => $activeTab === 'apercu',
                'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' => $activeTab !== 'apercu',
            ])
        >
            Aperçu
        </button>
        <button
            type="button"
            wire:click="$set('activeTab', 'caisses')"
            @class([
                'px-4 py-2 text-sm font-medium border-b-2 -mb-px',
                'border-accent text-accent' => $activeTab === 'caisses',
                'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' => $activeTab !== 'caisses',
            ])
        >
            Caisses
        </button>
        <button
            type="button"
            wire:click="$set('activeTab', 'comptes')"
            @class([
                'px-4 py-2 text-sm font-medium border-b-2 -mb-px',
                'border-accent text-accent' => $activeTab === 'comptes',
                'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' => $activeTab !== 'comptes',
            ])
        >
            Comptes
        </button>
        <button
            type="button"
            wire:click="$set('activeTab', 'revenus')"
            @class([
                'px-4 py-2 text-sm font-medium border-b-2 -mb-px',
                'border-accent text-accent' => $activeTab === 'revenus',
                'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' => $activeTab !== 'revenus',
            ])
        >
            Revenus
        </button>
    </div>

    {{-- ============================ APERÇU TAB ============================ --}}
    @if ($activeTab === 'apercu')
        <div class="mt-6">
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:card class="space-y-1">
                    <flux:text size="sm">Solde Wave</flux:text>
                    @if ($this->waveBalance !== null)
                        <flux:heading size="xl">{{ number_format($this->waveBalance, 0, ',', ' ') }} FCFA</flux:heading>
                    @else
                        <flux:badge color="zinc" icon="exclamation-triangle">Indisponible</flux:badge>
                    @endif
                </flux:card>

                <flux:card class="space-y-1">
                    <flux:text size="sm">Solde Orange Money</flux:text>
                    @if ($this->orangeMoneyBalance !== null)
                        <flux:heading size="xl">{{ number_format($this->orangeMoneyBalance, 0, ',', ' ') }} FCFA</flux:heading>
                    @else
                        <flux:badge color="zinc" icon="exclamation-triangle">Indisponible</flux:badge>
                    @endif
                </flux:card>
            </div>

            <flux:heading size="lg" class="mt-8">Ventes de billets (départs à venir)</flux:heading>

            @if (count($this->paymentMethodBalances) === 0)
                <flux:callout class="mt-4" icon="information-circle">
                    <flux:callout.text>Aucune vente de billet pour les départs à venir.</flux:callout.text>
                </flux:callout>
            @else
                <div class="mt-4 overflow-x-auto">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Moyen de paiement</flux:table.column>
                            <flux:table.column align="end">Total</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($this->paymentMethodBalances as $paymentMethodBalance)
                                <flux:table.row wire:key="apercu-{{ $loop->index }}">
                                    <flux:table.cell variant="strong">
                                        {{ $paymentMethodLabels[$paymentMethodBalance['paymentMethod']] ?? ($paymentMethodBalance['paymentMethod'] ?? 'Non renseigné') }}
                                    </flux:table.cell>
                                    <flux:table.cell align="end">
                                        {{ number_format($paymentMethodBalance['total'], 0, ',', ' ') }} FCFA
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach

                            <flux:table.row wire:key="apercu-total">
                                <flux:table.cell variant="strong">Total</flux:table.cell>
                                <flux:table.cell align="end" variant="strong">
                                    {{ number_format($this->paymentMethodBalancesTotal(), 0, ',', ' ') }} FCFA
                                </flux:table.cell>
                            </flux:table.row>
                        </flux:table.rows>
                    </flux:table>
                </div>
            @endif
        </div>
    @endif

    {{-- ============================ CAISSES TAB ============================ --}}
    @if ($activeTab === 'caisses')
        @include('livewire.back-office.partials.caisse-list')
        @include('livewire.back-office.partials.caisse-modals')
    @endif

    {{-- ============================ COMPTES TAB ============================ --}}
    @if ($activeTab === 'comptes')
        @include('livewire.back-office.partials.account-list')
        @include('livewire.back-office.partials.account-modals')
    @endif

    {{-- ============================ REVENUS TAB ============================ --}}
    @if ($activeTab === 'revenus')
        @include('livewire.back-office.partials.profit-report')
    @endif
</div>
