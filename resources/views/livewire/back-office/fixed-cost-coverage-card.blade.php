@php
    $coverage = $this->coverage;
    $formatAmount = fn (int $amount): string => number_format($amount, 0, ',', ' ').' FCFA';
@endphp

<flux:card class="space-y-5 text-zinc-800 dark:text-zinc-100" data-test="fixed-cost-coverage-card">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="lg">Charges fixes du mois</flux:heading>
            <flux:text size="sm">Marge du mois (revenus − dépenses variables) face aux charges fixes prévues</flux:text>
        </div>

        <flux:select wire:model.live="coverageMonthKey" class="max-w-48" aria-label="Mois">
            @foreach ($this->selectableMonths() as $monthKey => $monthLabel)
                <flux:select.option :value="$monthKey">{{ $monthLabel }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @if (! $coverage->isPlanned)
        <flux:callout icon="information-circle" data-test="fixed-costs-not-planned">
            <flux:callout.heading>Charges fixes non planifiées pour ce mois</flux:callout.heading>
            <x-slot name="actions">
                <flux:button :href="route('back-office.depenses.index')" wire:navigate size="sm">Planifier les charges fixes</flux:button>
            </x-slot>
        </flux:callout>
    @else
        <div class="space-y-2">
            <div class="flex items-center justify-between gap-4 text-sm font-medium" data-test="coverage-status">
                @if ($coverage->isCovered)
                    <span class="text-green-600 dark:text-green-400">Charges fixes couvertes</span>
                @else
                    <span class="text-amber-600 dark:text-amber-400">Reste à couvrir : {{ $formatAmount($coverage->remainingToCover) }}</span>
                @endif
                <span class="tabular-nums">{{ $coverage->coveragePercent }} %</span>
            </div>
            <div class="h-3 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                <div
                    class="h-full rounded-full {{ $coverage->isCovered ? 'bg-green-500' : 'bg-amber-500' }}"
                    style="width: {{ $coverage->coveragePercent }}%"
                ></div>
            </div>
        </div>

        <dl class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="text-zinc-500 dark:text-zinc-400">Revenus du mois</dt>
                <dd class="font-semibold tabular-nums">{{ $formatAmount($coverage->totalRevenue) }}</dd>
            </div>
            <div>
                <dt class="text-zinc-500 dark:text-zinc-400">Dépenses variables</dt>
                <dd class="font-semibold tabular-nums">− {{ $formatAmount($coverage->variableCosts) }}</dd>
            </div>
            <div>
                <dt class="text-zinc-500 dark:text-zinc-400">Marge</dt>
                <dd class="font-semibold tabular-nums {{ $coverage->margin < 0 ? 'text-red-600 dark:text-red-400' : '' }}">{{ $formatAmount($coverage->margin) }}</dd>
            </div>
            <div>
                <dt class="text-zinc-500 dark:text-zinc-400">Charges fixes prévues</dt>
                <dd class="font-semibold tabular-nums">{{ $formatAmount($coverage->fixedCostsTotal) }}</dd>
            </div>
        </dl>

        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Charge fixe</flux:table.column>
                    <flux:table.column align="end">Prévu</flux:table.column>
                    <flux:table.column align="end">Payé</flux:table.column>
                    <flux:table.column align="end">Reste à payer</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($coverage->lines as $coverageLine)
                        <flux:table.row wire:key="coverage-line-{{ $coverageLine['expenseTypeId'] }}">
                            <flux:table.cell variant="strong">{{ $coverageLine['name'] }}</flux:table.cell>
                            <flux:table.cell align="end" class="whitespace-nowrap tabular-nums">{{ $formatAmount($coverageLine['budget']) }}</flux:table.cell>
                            <flux:table.cell align="end" class="whitespace-nowrap tabular-nums">
                                {{ $formatAmount($coverageLine['paid']) }}
                                @if ($coverageLine['overBudget'] > 0)
                                    <flux:badge size="sm" color="red">+ {{ $formatAmount($coverageLine['overBudget']) }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end" class="whitespace-nowrap tabular-nums">
                                @if ($coverageLine['remaining'] === 0)
                                    <flux:badge size="sm" color="green">Payé</flux:badge>
                                @else
                                    {{ $formatAmount($coverageLine['remaining']) }}
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</flux:card>
