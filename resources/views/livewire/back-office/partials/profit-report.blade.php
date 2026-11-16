<div class="mt-6 space-y-6">
    <div class="grid gap-4 sm:grid-cols-2">
        <flux:field>
            <flux:label>Du</flux:label>
            <flux:input type="date" wire:model="profitReportDateFrom" wire:change="applyProfitReportFilter" />
        </flux:field>
        <flux:field>
            <flux:label>Au</flux:label>
            <flux:input type="date" wire:model="profitReportDateTo" wire:change="applyProfitReportFilter" />
        </flux:field>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <flux:card class="space-y-1">
            <flux:text size="sm">Total revenus</flux:text>
            <flux:heading size="xl">{{ number_format($this->profitReport['totalRevenue'], 0, ',', ' ') }} FCFA</flux:heading>
        </flux:card>

        <flux:card class="space-y-1">
            <flux:text size="sm">Total charges</flux:text>
            <flux:heading size="xl">{{ number_format($this->profitReport['totalExpenses'], 0, ',', ' ') }} FCFA</flux:heading>
        </flux:card>

        <flux:card class="space-y-1">
            <flux:text size="sm">Profit net</flux:text>
            <flux:heading size="xl" class="{{ $this->profitReport['profit'] < 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">
                {{ number_format($this->profitReport['profit'], 0, ',', ' ') }} FCFA
            </flux:heading>
        </flux:card>
    </div>

    @include('livewire.back-office.partials.profit-report-balance-sheet')
</div>
