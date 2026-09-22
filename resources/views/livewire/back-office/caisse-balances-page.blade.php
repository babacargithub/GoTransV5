@php
    $paymentMethodLabels = [
        'cash' => 'Espèces',
        'especes' => 'Espèces',
        'om' => 'Orange Money',
        'wave' => 'Wave',
        'card' => 'Carte bancaire',
    ];
@endphp

<div class="mx-auto w-full max-w-3xl">
    <flux:heading size="xl" level="1">Solde des caisses</flux:heading>
    <flux:text class="mt-1">Recettes des billets par moyen de paiement pour les départs à venir</flux:text>

    <flux:separator class="my-6" variant="subtle" />

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
                        <flux:table.row wire:key="caisse-{{ $loop->index }}">
                            <flux:table.cell variant="strong">
                                {{ $paymentMethodLabels[$paymentMethodBalance['paymentMethod']] ?? ($paymentMethodBalance['paymentMethod'] ?? 'Non renseigné') }}
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                {{ number_format($paymentMethodBalance['total'], 0, ',', ' ') }} FCFA
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach

                    <flux:table.row wire:key="caisse-total">
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
