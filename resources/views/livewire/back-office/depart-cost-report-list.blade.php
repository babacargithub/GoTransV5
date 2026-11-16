@php
    $formatFcfa = fn (int $amount): string => number_format($amount, 0, ',', ' ').' FCFA';
    $summary = $this->summary;
    $summaryCards = [
        ['label' => 'Départs', 'value' => number_format($summary['departsCount'], 0, ',', ' ')],
        ['label' => 'Bus', 'value' => number_format($summary['busesCount'], 0, ',', ' ')],
        ['label' => 'Billets vendus', 'value' => $formatFcfa($summary['ticketSales'])],
        ['label' => 'Commissions', 'value' => $formatFcfa($summary['commissions'])],
        ['label' => 'Dépenses', 'value' => $formatFcfa($summary['expenses'])],
        ['label' => 'Bénéfice net', 'value' => $formatFcfa($summary['netProfit']), 'tone' => $summary['netProfit'] >= 0 ? 'positive' : 'negative'],
    ];
@endphp

<div class="mx-auto w-full max-w-6xl">
    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Bilans</flux:heading>
            <flux:text class="mt-1">Billets vendus, location des bus et bénéfice de chaque départ</flux:text>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2">
            @if ($isSelecting)
                <flux:button wire:click="cancelSelecting" variant="ghost">Annuler la sélection</flux:button>
            @else
                <flux:button wire:click="startSelecting" icon="banknotes">Enregistrer plusieurs</flux:button>
            @endif

            <flux:button wire:click="toggleFilters" icon="funnel" :variant="$this->hasActiveFilters() ? 'primary' : 'filled'">
                Filtres
            </flux:button>
        </div>
    </div>

    @if ($flashStatusMessage)
        <flux:callout class="mt-4" variant="success" icon="check-circle" :heading="$flashStatusMessage" />
    @endif

    @if ($flashErrorMessage)
        <flux:callout class="mt-4" variant="danger" icon="exclamation-triangle" :heading="$flashErrorMessage" />
    @endif

    @if ($showFilters)
        <flux:card class="mt-4">
            <div class="grid items-end gap-4 sm:grid-cols-[1fr_1fr_auto]">
                <flux:field>
                    <flux:label>Date de début</flux:label>
                    <flux:input type="date" wire:model.live="startDate" max="{{ $endDate ?: '' }}" />
                </flux:field>

                <flux:field>
                    <flux:label>Date de fin</flux:label>
                    <flux:input type="date" wire:model.live="endDate" min="{{ $startDate ?: '' }}" />
                </flux:field>

                <flux:button wire:click="resetFilters" variant="ghost" :disabled="! $this->hasActiveFilters()">Ce mois-ci</flux:button>
            </div>
        </flux:card>
    @endif

    <flux:separator class="my-6" variant="subtle" />

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($summaryCards as $summaryCard)
            <flux:card class="space-y-1" wire:key="summary-{{ $summaryCard['label'] }}">
                <flux:text class="text-sm">{{ $summaryCard['label'] }}</flux:text>
                <flux:heading
                    size="lg"
                    :class="match ($summaryCard['tone'] ?? null) { 'positive' => 'text-green-600', 'negative' => 'text-red-600', default => '' }"
                >
                    {{ $summaryCard['value'] }}
                </flux:heading>
            </flux:card>
        @endforeach
    </div>

    <div class="mt-8">
        @if (count($this->costReportRows) === 0)
            <flux:callout icon="information-circle">
                <flux:callout.heading>Aucun bilan</flux:callout.heading>
                <flux:callout.text>Aucun bilan pour cette période. Enregistrez le bilan d'un départ depuis la liste des départs.</flux:callout.text>
            </flux:callout>
        @else
            @if ($isSelecting)
                <flux:card class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <flux:text class="text-sm">
                        {{ $this->selectedDepartsToPay->count() }} départ(s) sélectionné(s) —
                        reste à payer : <span class="font-medium">{{ $formatFcfa($this->selectedTotalToPay) }}</span>
                    </flux:text>
                    <flux:button wire:click="openCashOutModal" variant="primary" icon="arrow-right" :disabled="$this->selectedTotalToPay <= 0">
                        Procéder
                    </flux:button>
                </flux:card>
            @endif

            <div class="overflow-x-auto">
                <flux:table>
                    <flux:table.columns>
                        @if ($isSelecting)
                            <flux:table.column></flux:table.column>
                        @endif
                        <flux:table.column>Départ</flux:table.column>
                        <flux:table.column align="center">Bus</flux:table.column>
                        <flux:table.column align="end">Billets vendus</flux:table.column>
                        <flux:table.column align="end">Location des bus</flux:table.column>
                        @if ($isSelecting)
                            <flux:table.column align="end">Reste à payer</flux:table.column>
                        @endif
                        <flux:table.column align="end">Commissions</flux:table.column>
                        <flux:table.column align="end">Bénéfice</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->costReportRows as $costReportRow)
                            <flux:table.row wire:key="cost-report-row-{{ $costReportRow['departId'] }}">
                                @if ($isSelecting)
                                    <flux:table.cell>
                                        <flux:checkbox
                                            wire:model.live="selectedDepartIds"
                                            value="{{ $costReportRow['departId'] }}"
                                            :disabled="$costReportRow['remainingToPay'] === 0"
                                            aria-label="Sélectionner ce départ"
                                        />
                                    </flux:table.cell>
                                @endif
                                <flux:table.cell>
                                    <div class="flex items-center gap-2">
                                        <span class="flex size-5 shrink-0 items-center justify-center">
                                            @switch($costReportRow['busRentalPaymentStatus'])
                                                @case('paid')
                                                    <flux:tooltip content="Payé en caisse">
                                                        <flux:icon.check-circle variant="solid" class="size-5 text-green-600 dark:text-green-400" data-test="bus-rental-paid" />
                                                    </flux:tooltip>
                                                    @break
                                                @case('partial')
                                                    <flux:tooltip content="Partiellement payé : reste {{ $formatFcfa($costReportRow['remainingToPay']) }}">
                                                        <flux:icon.clock variant="solid" class="size-5 text-amber-500" data-test="bus-rental-partial" />
                                                    </flux:tooltip>
                                                    @break
                                                @case('unpaid')
                                                    <flux:tooltip content="Pas encore payé en caisse">
                                                        <flux:icon.exclamation-circle variant="solid" class="size-5 text-zinc-400 dark:text-zinc-500" data-test="bus-rental-unpaid" />
                                                    </flux:tooltip>
                                                    @break
                                            @endswitch
                                        </span>
                                        <div>
                                            <a
                                                href="{{ route('back-office.departs.cost-report', $costReportRow['departId']) }}"
                                                wire:navigate
                                                class="font-medium hover:underline"
                                            >
                                                {{ $costReportRow['departName'] }}
                                            </a>
                                            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $costReportRow['departDate'] }}</div>
                                        </div>
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell align="center">{{ $costReportRow['busesCount'] }}</flux:table.cell>
                                <flux:table.cell align="end">{{ $formatFcfa($costReportRow['ticketSales']) }}</flux:table.cell>
                                <flux:table.cell align="end">{{ $formatFcfa($costReportRow['busRentalFees']) }}</flux:table.cell>
                                @if ($isSelecting)
                                    <flux:table.cell align="end">{{ $formatFcfa($costReportRow['remainingToPay']) }}</flux:table.cell>
                                @endif
                                <flux:table.cell align="end">{{ $formatFcfa($costReportRow['commissions']) }}</flux:table.cell>
                                <flux:table.cell align="end" @class(['font-medium', 'text-green-600' => $costReportRow['profit'] >= 0, 'text-red-600' => $costReportRow['profit'] < 0])>
                                    {{ $formatFcfa($costReportRow['profit']) }}
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>

            <div class="mt-4">
                <flux:pagination :paginator="$this->costReportsPage" />
            </div>
        @endif
    </div>

    @if ($isSelecting)
        <flux:modal wire:model.self="showCashOutModal" class="w-full max-w-2xl">
            <form wire:submit="saveCashOut" class="space-y-6">
                <div>
                    <flux:heading size="lg">Enregistrer en caisse</flux:heading>
                    <flux:text class="mt-1">Sortie d'argent : paiement de la location des bus de {{ $this->selectedDepartsToPay->count() }} départ(s). Chaque départ est enregistré comme une opération distincte.</flux:text>
                </div>

                <div class="space-y-1 text-sm">
                    @foreach ($this->selectedDepartsToPay as $selectedDepart)
                        <div class="flex justify-between gap-4" wire:key="cash-out-depart-{{ $selectedDepart['departId'] }}">
                            <span>{{ $selectedDepart['departName'] }}</span>
                            <span class="font-medium">{{ $formatFcfa($selectedDepart['remainingToPay']) }}</span>
                        </div>
                    @endforeach
                    <flux:separator variant="subtle" class="my-2" />
                    <div class="flex justify-between gap-4 font-medium">
                        <span>Total à payer</span>
                        <span>{{ $formatFcfa($this->selectedTotalToPay) }}</span>
                    </div>
                </div>

                <div class="space-y-3">
                    <flux:heading>Caisses à débiter</flux:heading>
                    @foreach ($cashOutCaisseRows as $rowIndex => $caisseRow)
                        <div class="grid items-start gap-3 sm:grid-cols-[1fr_10rem_auto]" wire:key="cash-out-caisse-row-{{ $rowIndex }}">
                            <flux:field>
                                <flux:select wire:model.live="cashOutCaisseRows.{{ $rowIndex }}.caisseId" placeholder="Choisir une caisse">
                                    @foreach ($this->cashOutCaisseOptions as $caisseOption)
                                        <flux:select.option :value="$caisseOption['id']">{{ $caisseOption['name'] }} ({{ $formatFcfa($caisseOption['balance']) }})</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="cashOutCaisseRows.{{ $rowIndex }}.caisseId" />
                            </flux:field>
                            <flux:field>
                                <flux:input type="number" min="1" step="1" inputmode="numeric" placeholder="Montant"
                                    :disabled="count($cashOutCaisseRows) === 1"
                                    wire:model.live.debounce.300ms="cashOutCaisseRows.{{ $rowIndex }}.amount" />
                                <flux:error name="cashOutCaisseRows.{{ $rowIndex }}.amount" />
                            </flux:field>
                            @if (count($cashOutCaisseRows) > 1)
                                <flux:button wire:click="removeCashOutCaisseRow({{ $rowIndex }})" icon="trash" variant="danger" size="sm" aria-label="Retirer cette caisse" />
                            @endif
                        </div>
                    @endforeach
                    <flux:error name="cashOutCaisseRows" />
                    <flux:button wire:click="addCashOutCaisseRow" icon="plus" size="sm" variant="ghost">Ajouter une caisse</flux:button>
                </div>

                <div class="space-y-3">
                    <flux:heading>Comptes à débiter</flux:heading>
                    @foreach ($cashOutAccountRows as $rowIndex => $accountRow)
                        <div class="grid items-start gap-3 sm:grid-cols-[1fr_10rem_auto]" wire:key="cash-out-account-row-{{ $rowIndex }}">
                            <flux:field>
                                <flux:select wire:model.live="cashOutAccountRows.{{ $rowIndex }}.accountId" placeholder="Choisir un compte">
                                    @foreach ($this->cashOutAccountOptions as $accountOption)
                                        <flux:select.option :value="$accountOption['id']">{{ $accountOption['name'] }} ({{ $formatFcfa($accountOption['balance']) }})</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="cashOutAccountRows.{{ $rowIndex }}.accountId" />
                            </flux:field>
                            <flux:field>
                                <flux:input type="number" min="1" step="1" inputmode="numeric" placeholder="Montant"
                                    :disabled="count($cashOutAccountRows) === 1"
                                    wire:model.live.debounce.300ms="cashOutAccountRows.{{ $rowIndex }}.amount" />
                                <flux:error name="cashOutAccountRows.{{ $rowIndex }}.amount" />
                            </flux:field>
                            @if (count($cashOutAccountRows) > 1)
                                <flux:button wire:click="removeCashOutAccountRow({{ $rowIndex }})" icon="trash" variant="danger" size="sm" aria-label="Retirer ce compte" />
                            @endif
                        </div>
                    @endforeach
                    <flux:error name="cashOutAccountRows" />
                    <flux:button wire:click="addCashOutAccountRow" icon="plus" size="sm" variant="ghost">Ajouter un compte</flux:button>
                </div>

                <div class="flex items-center justify-end gap-2">
                    <flux:button wire:click="closeCashOutModal" variant="ghost">Annuler</flux:button>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveCashOut">
                        Enregistrer la sortie de {{ $formatFcfa($this->selectedTotalToPay) }}
                    </flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
