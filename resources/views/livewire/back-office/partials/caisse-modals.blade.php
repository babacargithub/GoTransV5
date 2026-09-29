{{-- Créer / modifier une caisse --}}
<flux:modal wire:model.self="showCaisseModal" wire:key="caisse-modal" class="w-full max-w-lg">
    <form wire:submit="saveCaisse" class="space-y-6">
        <flux:heading size="lg">{{ $editingCaisseId ? 'Modifier la caisse' : 'Créer une caisse' }}</flux:heading>

        <flux:field>
            <flux:label>Nom</flux:label>
            <flux:input wire:model="caisseName" />
            <flux:error name="caisseName" />
        </flux:field>

        <div class="flex items-center justify-end gap-2">
            <flux:button variant="ghost" wire:click="closeCaisseModal">Annuler</flux:button>
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveCaisse">Enregistrer</flux:button>
        </div>
    </form>
</flux:modal>

{{-- Supprimer une caisse --}}
<flux:modal wire:model.self="showDeleteCaisseModal" wire:key="delete-caisse-modal" class="min-w-[22rem] max-w-md">
    <div class="space-y-6">
        <div>
            <flux:heading size="lg">Supprimer cette caisse ?</flux:heading>
            <flux:text class="mt-2">
                Voulez-vous vraiment supprimer « {{ $this->deleteCaisseLabel() }} » ? Le solde doit être à zéro et ce ne doit pas être une caisse système. Cette action est irréversible.
            </flux:text>
        </div>

        <div class="flex items-center justify-end gap-2">
            <flux:button variant="ghost" wire:click="closeDeleteCaisseModal">Retour</flux:button>
            <flux:button variant="danger" wire:click="confirmDeleteCaisse" wire:loading.attr="disabled" wire:target="confirmDeleteCaisse">Supprimer</flux:button>
        </div>
    </div>
</flux:modal>

{{-- Entrée de caisse --}}
<flux:modal wire:model.self="showEntreeModal" wire:key="entree-modal" class="w-full max-w-lg">
    <form wire:submit="saveEntreeDeCaisse" class="space-y-6">
        <div>
            <flux:heading size="lg">Entrée de caisse</flux:heading>
            <flux:text class="mt-1">Caisse : {{ $this->caisseNameForId($entreeCaisseId) }}</flux:text>
        </div>

        <flux:field>
            <flux:label>Compte à créditer</flux:label>
            <flux:select wire:model="entreeAccountId">
                <flux:select.option value="">Sélectionner un compte</flux:select.option>
                @foreach ($this->accountRows as $accountRow)
                    <flux:select.option value="{{ $accountRow['id'] }}">{{ $accountRow['name'] }} ({{ $accountRow['accountTypeLabel'] }})</flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="entreeAccountId" />
        </flux:field>

        <flux:field>
            <flux:label>Montant (FCFA)</flux:label>
            <flux:input
                type="text"
                inputmode="numeric"
                autocomplete="off"
                placeholder="0"
                wire:key="entree-amount-{{ $entreeCaisseId }}-{{ $showEntreeModal ? 1 : 0 }}"
                x-on:input="
                    const digits = $event.target.value.replace(/\D/g, '');
                    $event.target.value = digits.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
                    $wire.set('entreeAmount', digits === '' ? null : Number(digits));
                "
            />
            <flux:error name="entreeAmount" />
        </flux:field>

        <flux:field>
            <flux:label>Libellé</flux:label>
            <flux:input wire:model="entreeLabel" placeholder="Ex : Emprunt, apport de fonds..." />
            <flux:error name="entreeLabel" />
        </flux:field>

        <flux:field>
            <flux:label>Opérations courantes</flux:label>
            <div class="grid gap-2 sm:grid-cols-2">
                @foreach (\App\Enums\CommonCaisseOperation::forEntreeDeCaisse() as $operation)
                    <flux:checkbox
                        :checked="$entreeShortcut === $operation->value"
                        wire:click="toggleEntreeShortcut('{{ $operation->value }}')"
                        label="{{ $operation->label() }}"
                    />
                @endforeach
            </div>
        </flux:field>

        <flux:field>
            <flux:label>Nature de l'opération</flux:label>
            <flux:select wire:model="entreeCategory">
                @foreach (\App\Enums\AccountTransactionCategory::cases() as $category)
                    <flux:select.option value="{{ $category->value }}">{{ $category->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="entreeCategory" />
        </flux:field>

        <div class="flex items-center justify-end gap-2">
            <flux:button variant="ghost" wire:click="closeEntreeModal">Annuler</flux:button>
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveEntreeDeCaisse">Enregistrer</flux:button>
        </div>
    </form>
</flux:modal>

{{-- Sortie de caisse --}}
<flux:modal wire:model.self="showSortieModal" wire:key="sortie-modal" class="w-full max-w-lg">
    <form wire:submit="saveSortieDeCaisse" class="space-y-6">
        <div>
            <flux:heading size="lg">Sortie de caisse</flux:heading>
            <flux:text class="mt-1">Caisse : {{ $this->caisseNameForId($sortieCaisseId) }}</flux:text>
        </div>

        <flux:field>
            <flux:label>Montant (FCFA)</flux:label>
            <flux:input
                type="text"
                inputmode="numeric"
                autocomplete="off"
                placeholder="0"
                wire:key="sortie-amount-{{ $sortieCaisseId }}-{{ $showSortieModal ? 1 : 0 }}"
                x-on:input="
                    const digits = $event.target.value.replace(/\D/g, '');
                    $event.target.value = digits.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
                    $wire.set('sortieAmount', digits === '' ? null : Number(digits));
                "
            />
            <flux:error name="sortieAmount" />
        </flux:field>

        <flux:field>
            <flux:label>Libellé</flux:label>
            <flux:input wire:model="sortieLabel" placeholder="Ex : Paiement fournisseur, salaires..." />
            <flux:error name="sortieLabel" />
        </flux:field>

        <flux:field>
            <flux:label>Opérations courantes</flux:label>
            <div class="grid gap-2 sm:grid-cols-2">
                @foreach (\App\Enums\CommonCaisseOperation::forSortieDeCaisse() as $operation)
                    <flux:checkbox
                        :checked="$sortieShortcut === $operation->value"
                        wire:click="toggleSortieShortcut('{{ $operation->value }}')"
                        label="{{ $operation->label() }}"
                    />
                @endforeach
            </div>
        </flux:field>

        <flux:field>
            <flux:label>Nature de l'opération</flux:label>
            <flux:select wire:model="sortieCategory">
                @foreach (\App\Enums\AccountTransactionCategory::cases() as $category)
                    <flux:select.option value="{{ $category->value }}">{{ $category->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="sortieCategory" />
        </flux:field>

        <flux:field>
            <flux:label>Comptes à débiter (dans l'ordre)</flux:label>
            <flux:text size="sm" class="text-zinc-500">Chaque compte est débité jusqu'à son solde disponible avant de passer au suivant.</flux:text>
            <flux:checkbox.group wire:model="sortieAccountIds">
                @foreach ($this->accountRows as $accountRow)
                    <flux:checkbox value="{{ $accountRow['id'] }}" label="{{ $accountRow['name'] }} ({{ number_format($accountRow['balance'], 0, ',', ' ') }} FCFA)" />
                @endforeach
            </flux:checkbox.group>
            <flux:error name="sortieAccountIds" />
        </flux:field>

        <div class="flex items-center justify-end gap-2">
            <flux:button variant="ghost" wire:click="closeSortieModal">Annuler</flux:button>
            <flux:button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="saveSortieDeCaisse">Enregistrer</flux:button>
        </div>
    </form>
</flux:modal>

{{-- Transférer vers une autre caisse --}}
<flux:modal wire:model.self="showCaisseTransferModal" wire:key="caisse-transfer-modal" class="w-full max-w-lg">
    <form wire:submit="saveCaisseTransfer" class="space-y-6">
        <div>
            <flux:heading size="lg">Transférer vers une autre caisse</flux:heading>
            <flux:text class="mt-1">Depuis : {{ $this->caisseNameForId($transferFromCaisseId) }}</flux:text>
        </div>

        <flux:field>
            <flux:label>Caisse destination</flux:label>
            <flux:select wire:model="transferToCaisseId">
                <flux:select.option value="">Sélectionner une caisse</flux:select.option>
                @foreach ($this->caisseRows as $caisseRow)
                    @if ($caisseRow['id'] !== $transferFromCaisseId)
                        <flux:select.option value="{{ $caisseRow['id'] }}">{{ $caisseRow['name'] }}</flux:select.option>
                    @endif
                @endforeach
            </flux:select>
            <flux:error name="transferToCaisseId" />
        </flux:field>

        <flux:field>
            <flux:label>Montant (FCFA)</flux:label>
            <flux:input type="number" wire:model="transferAmount" />
            <flux:error name="transferAmount" />
        </flux:field>

        <flux:field>
            <flux:label>Description (optionnel)</flux:label>
            <flux:input wire:model="transferDescription" />
            <flux:error name="transferDescription" />
        </flux:field>

        <div class="flex items-center justify-end gap-2">
            <flux:button variant="ghost" wire:click="closeCaisseTransferModal">Annuler</flux:button>
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveCaisseTransfer">Transférer</flux:button>
        </div>
    </form>
</flux:modal>

{{-- Transactions d'une caisse --}}
<flux:modal wire:model.self="showCaisseTransactionsModal" wire:key="caisse-transactions-modal" class="w-full max-w-2xl">
    @if ($showCaisseTransactionsModal)
        <div class="space-y-6">
            <flux:heading size="lg">Transactions — {{ $this->caisseNameForId($viewingCaisseTransactionsId) }}</flux:heading>

            @if (count($this->caisseTransactionsForModal) === 0)
                <flux:callout icon="information-circle">
                    <flux:callout.text>Aucune transaction pour cette caisse.</flux:callout.text>
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
                            @foreach ($this->caisseTransactionsForModal as $transaction)
                                <flux:table.row wire:key="caisse-tx-{{ $transaction['id'] }}">
                                    <flux:table.cell>{{ $transaction['createdAt'] }}</flux:table.cell>
                                    <flux:table.cell>{{ $transaction['label'] ?? '—' }}</flux:table.cell>
                                    <flux:table.cell>
                                        @if ($transaction['transactionType'] === 'DEPOSIT')
                                            <flux:badge size="sm" color="green">Entrée</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="red">Sortie</flux:badge>
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
                <flux:button variant="ghost" wire:click="closeCaisseTransactionsModal">Fermer</flux:button>
            </div>
        </div>
    @endif
</flux:modal>
