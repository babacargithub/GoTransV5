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

    <div>
        <flux:heading size="lg">Revenus par compte</flux:heading>

        @if (count($this->profitReport['revenueByAccount']) === 0)
            <flux:callout class="mt-4" icon="information-circle">
                <flux:callout.text>Aucun revenu sur cette période.</flux:callout.text>
            </flux:callout>
        @else
            <div class="mt-4 overflow-x-auto">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Compte</flux:table.column>
                        <flux:table.column align="end">Montant</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->profitReport['revenueByAccount'] as $revenueAccountRow)
                            <flux:table.row wire:key="revenue-account-{{ $revenueAccountRow['accountId'] }}">
                                <flux:table.cell variant="strong">{{ $revenueAccountRow['name'] }}</flux:table.cell>
                                <flux:table.cell align="end">{{ number_format($revenueAccountRow['amount'], 0, ',', ' ') }} FCFA</flux:table.cell>
                            </flux:table.row>
                        @endforeach

                        <flux:table.row wire:key="revenue-account-total">
                            <flux:table.cell variant="strong">Total</flux:table.cell>
                            <flux:table.cell align="end" variant="strong">
                                {{ number_format($this->profitReport['totalRevenue'], 0, ',', ' ') }} FCFA
                            </flux:table.cell>
                        </flux:table.row>
                    </flux:table.rows>
                </flux:table>
            </div>
        @endif
    </div>

    <div>
        <flux:heading size="lg">Charges par compte</flux:heading>

        @if (count($this->profitReport['expensesByAccount']) === 0)
            <flux:callout class="mt-4" icon="information-circle">
                <flux:callout.text>Aucune charge sur cette période.</flux:callout.text>
            </flux:callout>
        @else
            <div class="mt-4 overflow-x-auto">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Compte</flux:table.column>
                        <flux:table.column align="end">Montant</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->profitReport['expensesByAccount'] as $expenseAccountRow)
                            <flux:table.row wire:key="expense-account-{{ $expenseAccountRow['accountId'] }}">
                                <flux:table.cell variant="strong">{{ $expenseAccountRow['name'] }}</flux:table.cell>
                                <flux:table.cell align="end">{{ number_format($expenseAccountRow['amount'], 0, ',', ' ') }} FCFA</flux:table.cell>
                            </flux:table.row>
                        @endforeach

                        <flux:table.row wire:key="expense-account-total">
                            <flux:table.cell variant="strong">Total</flux:table.cell>
                            <flux:table.cell align="end" variant="strong">
                                {{ number_format($this->profitReport['totalExpenses'], 0, ',', ' ') }} FCFA
                            </flux:table.cell>
                        </flux:table.row>
                    </flux:table.rows>
                </flux:table>
            </div>
        @endif
    </div>
</div>
