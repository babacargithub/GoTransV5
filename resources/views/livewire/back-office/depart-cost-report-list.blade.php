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

        <flux:button wire:click="toggleFilters" icon="funnel" :variant="$this->hasActiveFilters() ? 'primary' : 'filled'">
            Filtres
        </flux:button>
    </div>

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
            <div class="overflow-x-auto">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Départ</flux:table.column>
                        <flux:table.column align="center">Bus</flux:table.column>
                        <flux:table.column align="end">Billets vendus</flux:table.column>
                        <flux:table.column align="end">Location des bus</flux:table.column>
                        <flux:table.column align="end">Commissions</flux:table.column>
                        <flux:table.column align="end">Bénéfice</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->costReportRows as $costReportRow)
                            <flux:table.row wire:key="cost-report-row-{{ $costReportRow['departId'] }}">
                                <flux:table.cell>
                                    <a
                                        href="{{ route('back-office.departs.cost-report', $costReportRow['departId']) }}"
                                        wire:navigate
                                        class="font-medium hover:underline"
                                    >
                                        {{ $costReportRow['departName'] }}
                                    </a>
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $costReportRow['departDate'] }}</div>
                                </flux:table.cell>
                                <flux:table.cell align="center">{{ $costReportRow['busesCount'] }}</flux:table.cell>
                                <flux:table.cell align="end">{{ $formatFcfa($costReportRow['ticketSales']) }}</flux:table.cell>
                                <flux:table.cell align="end">{{ $formatFcfa($costReportRow['busRentalFees']) }}</flux:table.cell>
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
</div>
