<div class="mx-auto w-full max-w-3xl">
    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Paiements OM</flux:heading>
            <flux:text class="mt-1">Solde et transactions Orange Money du compte marchand</flux:text>
        </div>

        <flux:button variant="primary" icon="arrow-up-tray" wire:click="openWithdrawModal">
            Effectuer un retrait
        </flux:button>
    </div>

    @if (session('status'))
        <flux:callout class="mt-4" variant="success" icon="check-circle">
            <flux:callout.text>{{ session('status') }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:separator class="my-6" variant="subtle" />

    <flux:card class="space-y-1">
        <flux:text size="sm">Solde Orange Money</flux:text>
        @if ($this->orangeMoneyBalance !== null)
            <flux:heading size="xl">{{ number_format($this->orangeMoneyBalance, 0, ',', ' ') }} FCFA</flux:heading>
        @else
            <flux:badge color="zinc" icon="exclamation-triangle">Indisponible — l'API Orange Money est injoignable</flux:badge>
        @endif
    </flux:card>

    <flux:heading size="lg" class="mt-8">Transactions récentes</flux:heading>

    @php($orangeMoneyTransactions = $this->orangeMoneyTransactions)

    @if ($orangeMoneyTransactions === null)
        <flux:callout class="mt-4" variant="warning" icon="exclamation-triangle">
            <flux:callout.text>Les transactions Orange Money sont indisponibles pour le moment.</flux:callout.text>
        </flux:callout>
    @elseif (count($orangeMoneyTransactions) === 0)
        <flux:callout class="mt-4" icon="information-circle">
            <flux:callout.text>Aucune transaction récente.</flux:callout.text>
        </flux:callout>
    @else
        <div class="mt-4 overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Référence</flux:table.column>
                    <flux:table.column>Type</flux:table.column>
                    <flux:table.column align="end">Montant</flux:table.column>
                    <flux:table.column>Statut</flux:table.column>
                    <flux:table.column>Date</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($orangeMoneyTransactions as $orangeMoneyTransaction)
                        @php($amount = data_get($orangeMoneyTransaction, 'amount.value', data_get($orangeMoneyTransaction, 'amount')))
                        <flux:table.row wire:key="om-transaction-{{ $loop->index }}">
                            <flux:table.cell variant="strong">
                                {{ data_get($orangeMoneyTransaction, 'reference', data_get($orangeMoneyTransaction, 'id', '—')) }}
                            </flux:table.cell>
                            <flux:table.cell>{{ data_get($orangeMoneyTransaction, 'type', '—') }}</flux:table.cell>
                            <flux:table.cell align="end">
                                {{ is_numeric($amount) ? number_format((float) $amount, 0, ',', ' ').' FCFA' : '—' }}
                            </flux:table.cell>
                            <flux:table.cell>{{ data_get($orangeMoneyTransaction, 'status', '—') }}</flux:table.cell>
                            <flux:table.cell>{{ data_get($orangeMoneyTransaction, 'createdAt', data_get($orangeMoneyTransaction, 'date', '—')) }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif

    {{-- Effectuer un retrait --}}
    <flux:modal wire:model.self="showWithdrawModal" wire:key="om-withdraw-modal" class="w-full max-w-md">
        <form wire:submit="confirmWithdraw" class="space-y-6">
            <div>
                <flux:heading size="lg">Effectuer un retrait</flux:heading>
                <flux:text class="mt-1">Le montant sera envoyé sur le numéro Orange Money indiqué.</flux:text>
            </div>

            @if ($withdrawErrorMessage)
                <flux:callout variant="danger" icon="exclamation-triangle">
                    <flux:callout.text>{{ $withdrawErrorMessage }}</flux:callout.text>
                </flux:callout>
            @endif

            <flux:field>
                <flux:label>Montant (FCFA)</flux:label>
                <flux:input type="number" wire:model="withdrawAmount" />
                <flux:error name="withdrawAmount" />
            </flux:field>

            <flux:field>
                <flux:label>Numéro de téléphone</flux:label>
                <flux:input wire:model="withdrawPhoneNumber" inputmode="numeric" />
                <flux:error name="withdrawPhoneNumber" />
            </flux:field>

            <flux:field>
                <flux:label>Code secret</flux:label>
                <flux:input type="password" wire:model="withdrawSecretCode" />
                <flux:error name="withdrawSecretCode" />
            </flux:field>

            <div class="flex items-center justify-end gap-2">
                <flux:button variant="ghost" wire:click="closeWithdrawModal">Annuler</flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="confirmWithdraw">
                    Confirmer le retrait
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
