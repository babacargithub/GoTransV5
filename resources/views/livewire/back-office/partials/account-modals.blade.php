{{-- Créer / modifier un compte --}}
<flux:modal wire:model.self="showAccountModal" wire:key="account-modal" class="w-full max-w-lg">
    <form wire:submit="saveAccount" class="space-y-6">
        <flux:heading size="lg">{{ $editingAccountId ? 'Modifier le compte' : 'Créer un compte' }}</flux:heading>

        <flux:field>
            <flux:label>Nom</flux:label>
            <flux:input wire:model="accountName" placeholder="Ex : Emprunt, Carburant, Loyer..." />
            <flux:error name="accountName" />
        </flux:field>

        <flux:field>
            <flux:label>Type</flux:label>
            <flux:select wire:model="accountType" :disabled="$editingAccountId !== null">
                @foreach ($this->accountTypeOptions as $typeValue => $typeLabel)
                    <flux:select.option :value="$typeValue">{{ $typeLabel }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="accountType" />
        </flux:field>

        <flux:switch wire:model="accountIsActive" label="Compte actif" />

        <div class="flex items-center justify-end gap-2">
            <flux:button variant="ghost" wire:click="closeAccountModal">Annuler</flux:button>
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveAccount">Enregistrer</flux:button>
        </div>
    </form>
</flux:modal>

{{-- Supprimer un compte --}}
<flux:modal wire:model.self="showDeleteAccountModal" wire:key="delete-account-modal" class="min-w-[22rem] max-w-md">
    <div class="space-y-6">
        <div>
            <flux:heading size="lg">Supprimer ce compte ?</flux:heading>
            <flux:text class="mt-2">
                Voulez-vous vraiment supprimer « {{ $this->deleteAccountLabel() }} » ? Le solde doit être à zéro. Cette action est irréversible.
            </flux:text>
        </div>

        <div class="flex items-center justify-end gap-2">
            <flux:button variant="ghost" wire:click="closeDeleteAccountModal">Retour</flux:button>
            <flux:button variant="danger" wire:click="confirmDeleteAccount" wire:loading.attr="disabled" wire:target="confirmDeleteAccount">Supprimer</flux:button>
        </div>
    </div>
</flux:modal>

{{-- Transférer entre comptes --}}
<flux:modal wire:model.self="showAccountTransferModal" wire:key="account-transfer-modal" class="w-full max-w-lg">
    <form wire:submit="saveAccountTransfer" class="space-y-6">
        <div>
            <flux:heading size="lg">Transférer entre comptes</flux:heading>
            <flux:text class="mt-1">Depuis : {{ $this->accountNameForId($transferFromAccountId) }}</flux:text>
        </div>

        <flux:field>
            <flux:label>Compte destination</flux:label>
            <flux:select wire:model="transferToAccountId">
                <flux:select.option value="">Sélectionner un compte</flux:select.option>
                @foreach ($this->accountRows as $accountRow)
                    @if ($accountRow['id'] !== $transferFromAccountId)
                        <flux:select.option value="{{ $accountRow['id'] }}">{{ $accountRow['name'] }}</flux:select.option>
                    @endif
                @endforeach
            </flux:select>
            <flux:error name="transferToAccountId" />
        </flux:field>

        <flux:field>
            <flux:label>Montant (FCFA)</flux:label>
            <flux:input type="number" wire:model="accountTransferAmount" />
            <flux:error name="accountTransferAmount" />
        </flux:field>

        <flux:field>
            <flux:label>Libellé</flux:label>
            <flux:input wire:model="accountTransferLabel" />
            <flux:error name="accountTransferLabel" />
        </flux:field>

        <div class="flex items-center justify-end gap-2">
            <flux:button variant="ghost" wire:click="closeAccountTransferModal">Annuler</flux:button>
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveAccountTransfer">Transférer</flux:button>
        </div>
    </form>
</flux:modal>

{{-- Transactions d'un compte --}}
<flux:modal wire:model.self="showAccountTransactionsModal" wire:key="account-transactions-modal" class="w-full max-w-2xl">
    @if ($showAccountTransactionsModal)
        <div class="space-y-6">
            <flux:heading size="lg">Transactions — {{ $this->accountNameForId($viewingAccountTransactionsId) }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-3">
                <flux:field>
                    <flux:label>Du</flux:label>
                    <flux:input type="date" wire:model="accountTransactionsDateFrom" wire:change="applyAccountTransactionsFilter" />
                </flux:field>
                <flux:field>
                    <flux:label>Au</flux:label>
                    <flux:input type="date" wire:model="accountTransactionsDateTo" wire:change="applyAccountTransactionsFilter" />
                </flux:field>
                <flux:field>
                    <flux:label>Type</flux:label>
                    <flux:select wire:model="accountTransactionsType" wire:change="applyAccountTransactionsFilter">
                        <flux:select.option value="">Tous</flux:select.option>
                        <flux:select.option value="CREDIT">Crédit</flux:select.option>
                        <flux:select.option value="DEBIT">Débit</flux:select.option>
                    </flux:select>
                </flux:field>
            </div>

            <div class="flex gap-4 text-sm">
                <flux:text>Total crédits : <strong>{{ number_format($this->accountTransactionsForModal['totalDeposits'], 0, ',', ' ') }} FCFA</strong></flux:text>
                <flux:text>Total débits : <strong>{{ number_format($this->accountTransactionsForModal['totalWithdrawals'], 0, ',', ' ') }} FCFA</strong></flux:text>
            </div>

            @if (count($this->accountTransactionsForModal['transactions']) === 0)
                <flux:callout icon="information-circle">
                    <flux:callout.text>Aucune transaction pour ce compte.</flux:callout.text>
                </flux:callout>
            @else
                <div class="max-h-96 overflow-y-auto overflow-x-auto">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Date</flux:table.column>
                            <flux:table.column>Libellé</flux:table.column>
                            <flux:table.column>Type</flux:table.column>
                            <flux:table.column align="end">Montant</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($this->accountTransactionsForModal['transactions'] as $transaction)
                                <flux:table.row wire:key="account-tx-{{ $transaction['id'] }}">
                                    <flux:table.cell>{{ $transaction['createdAt'] }}</flux:table.cell>
                                    <flux:table.cell>{{ $transaction['label'] ?? '—' }}</flux:table.cell>
                                    <flux:table.cell>
                                        @if ($transaction['transactionType'] === 'CREDIT')
                                            <flux:badge size="sm" color="green">Crédit</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="red">Débit</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell align="end">{{ number_format($transaction['effectiveAmount'], 0, ',', ' ') }} FCFA</flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            @endif

            <div class="flex items-center justify-end">
                <flux:button variant="ghost" wire:click="closeAccountTransactionsModal">Fermer</flux:button>
            </div>
        </div>
    @endif
</flux:modal>
