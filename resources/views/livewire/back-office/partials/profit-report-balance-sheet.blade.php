@php
    $formatAmount = fn (int $amount): string => number_format($amount, 0, ',', ' ').' FCFA';
    $percentageOf = fn (int $part, int $whole): string => $whole > 0 ? number_format($part / $whole * 100, 1, ',', ' ').' %' : '—';
    $totalRevenue = $this->profitReport['totalRevenue'];
    $totalExpenses = $this->profitReport['totalExpenses'];
    $profit = $this->profitReport['profit'];
    $periodLabel = $profitReportDateFrom !== '' && $profitReportDateTo !== ''
        ? 'Du '.\Illuminate\Support\Carbon::parse($profitReportDateFrom)->locale('fr')->translatedFormat('d F Y').' au '.\Illuminate\Support\Carbon::parse($profitReportDateTo)->locale('fr')->translatedFormat('d F Y')
        : 'Période sélectionnée';
@endphp

<flux:card class="space-y-6 text-zinc-800 dark:text-zinc-100" data-test="profit-balance-sheet">
    <div>
        <flux:heading size="lg">Bilan de la période</flux:heading>
        <flux:text size="sm">{{ $periodLabel }}</flux:text>
    </div>

    <div class="grid gap-6 lg:grid-cols-2 lg:gap-0 lg:divide-x lg:divide-zinc-200 dark:lg:divide-zinc-600">
        {{-- Revenus --}}
        <section class="flex flex-col lg:pe-6">
            <flux:heading class="mb-3 text-blue-600 dark:text-blue-400">Revenus</flux:heading>

            <dl class="flex-1 divide-y divide-zinc-100 dark:divide-zinc-700">
                @forelse ($this->profitReport['revenueByAccount'] as $revenueAccountRow)
                    <div class="flex justify-between gap-4 py-2 text-sm" wire:key="balance-revenue-{{ $revenueAccountRow['accountId'] }}">
                        <dt>{{ $revenueAccountRow['name'] }}</dt>
                        <dd class="whitespace-nowrap tabular-nums">{{ $formatAmount($revenueAccountRow['amount']) }}</dd>
                    </div>
                @empty
                    <div class="py-2 text-sm text-zinc-500">Aucun revenu sur cette période.</div>
                @endforelse
            </dl>

            <div class="mt-2 flex justify-between gap-4 border-t-2 border-zinc-300 py-2 font-semibold text-zinc-900 dark:border-zinc-500 dark:text-white" data-test="total-revenue">
                <span>Total revenus</span>
                <span class="whitespace-nowrap tabular-nums text-blue-600 dark:text-blue-400">{{ $formatAmount($totalRevenue) }}</span>
            </div>
        </section>

        {{-- Dépenses --}}
        <section class="flex flex-col lg:ps-6">
            <flux:heading class="mb-3 text-orange-600 dark:text-orange-400">Dépenses</flux:heading>

            <dl class="flex-1 divide-y divide-zinc-100 dark:divide-zinc-700">
                @forelse ($this->profitReport['expensesByType'] as $expenseTypeRow)
                    <div class="flex justify-between gap-4 py-2 text-sm" wire:key="balance-expense-{{ $expenseTypeRow['expenseTypeId'] ?? 'unclassified' }}">
                        <dt class="{{ $expenseTypeRow['expenseTypeId'] === null ? 'italic text-zinc-500' : '' }}">{{ $expenseTypeRow['name'] }}</dt>
                        <dd class="whitespace-nowrap tabular-nums">
                            {{ $formatAmount($expenseTypeRow['amount']) }}
                            <span class="ms-2 inline-block w-14 text-end text-xs text-zinc-500 dark:text-zinc-400">{{ $percentageOf($expenseTypeRow['amount'], $totalExpenses) }}</span>
                        </dd>
                    </div>
                @empty
                    <div class="py-2 text-sm text-zinc-500">Aucune dépense sur cette période.</div>
                @endforelse
            </dl>

            <div class="mt-2 flex justify-between gap-4 border-t-2 border-zinc-300 py-2 font-semibold text-zinc-900 dark:border-zinc-500 dark:text-white" data-test="total-expenses">
                <span>Total dépenses</span>
                <span class="whitespace-nowrap tabular-nums text-orange-600 dark:text-orange-400">{{ $formatAmount($totalExpenses) }}</span>
            </div>
        </section>
    </div>

    {{-- Résultat --}}
    <div class="flex flex-wrap items-baseline justify-between gap-2 rounded-lg bg-zinc-100 px-4 py-3 text-zinc-900 dark:bg-zinc-700 dark:text-white" data-test="net-result">
        <span class="font-semibold">{{ $profit >= 0 ? 'Résultat net (bénéfice)' : 'Résultat net (déficit)' }}</span>
        <span class="text-xl font-bold tabular-nums {{ $profit < 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">
            {{ $profit < 0 ? '−' : '' }}{{ $formatAmount(abs($profit)) }}
            @if ($totalRevenue > 0)
                <span class="ms-2 text-sm font-normal text-zinc-500 dark:text-zinc-300">{{ $percentageOf(abs($profit), $totalRevenue) }} des revenus</span>
            @endif
        </span>
    </div>
</flux:card>
