<div class="mx-auto w-full max-w-6xl">
    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Gestion dépenses</flux:heading>
            <flux:text class="mt-1">Charges fixes prévues, par type de dépense, pour le mois en cours et les 6 mois suivants</flux:text>
        </div>

        <flux:button variant="primary" icon="check" wire:click="saveBudgets" wire:loading.attr="disabled" wire:target="saveBudgets">
            Enregistrer
        </flux:button>
    </div>

    <x-flash-toasts />

    <flux:separator class="my-6" variant="subtle" />

    @error('budgetAmounts.*.*')
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-4" :heading="$message" />
    @enderror

    {{-- Amount cells show space-separated thousands while typing; the Livewire property keeps the plain digits. --}}
    <div
        class="overflow-x-auto"
        x-data="{
            formatThousands(value) {
                return String(value ?? '').replace(/\D/g, '').replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
            },
        }"
    >
        <flux:table data-test="fixed-costs-grid">
            <flux:table.columns>
                <flux:table.column>Type de dépense</flux:table.column>
                @foreach ($this->plannedMonths as $monthKey => $month)
                    <flux:table.column align="end" wire:key="month-header-{{ $monthKey }}">
                        <div class="capitalize">{{ $month->locale('fr')->translatedFormat('F Y') }}</div>
                        <button
                            type="button"
                            wire:click="copyPreviousMonth('{{ $monthKey }}')"
                            class="mt-0.5 text-xs font-normal normal-case text-blue-600 hover:underline dark:text-blue-400"
                            data-test="copy-previous-month-{{ $monthKey }}"
                        >Copier le mois précédent</button>
                    </flux:table.column>
                @endforeach
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->expenseTypeRows as $expenseTypeRow)
                    <flux:table.row wire:key="budget-row-{{ $expenseTypeRow['id'] }}">
                        <flux:table.cell variant="strong" class="whitespace-nowrap">
                            {{ $expenseTypeRow['name'] }}
                            @unless ($expenseTypeRow['isActive'])
                                <flux:badge size="sm" color="zinc">Inactif</flux:badge>
                            @endunless
                        </flux:table.cell>
                        @foreach ($this->plannedMonths as $monthKey => $month)
                            <flux:table.cell wire:key="budget-cell-{{ $expenseTypeRow['id'] }}-{{ $monthKey }}">
                                <flux:input
                                    type="text"
                                    inputmode="numeric"
                                    autocomplete="off"
                                    placeholder="0"
                                    class="min-w-32 text-end"
                                    x-init="
                                        $el.value = formatThousands($wire.get('budgetAmounts.{{ $expenseTypeRow['id'] }}.{{ $monthKey }}'));
                                        $wire.$watch('budgetAmounts.{{ $expenseTypeRow['id'] }}.{{ $monthKey }}', (amount) => $el.value = formatThousands(amount));
                                    "
                                    x-on:input="
                                        const digits = $event.target.value.replace(/\D/g, '');
                                        $event.target.value = formatThousands(digits);
                                        $wire.set('budgetAmounts.{{ $expenseTypeRow['id'] }}.{{ $monthKey }}', digits, false);
                                    "
                                    x-on:change="$wire.set('budgetAmounts.{{ $expenseTypeRow['id'] }}.{{ $monthKey }}', $event.target.value.replace(/\D/g, ''))"
                                    aria-label="{{ $expenseTypeRow['name'] }} — {{ $month->locale('fr')->translatedFormat('F Y') }}"
                                />
                            </flux:table.cell>
                        @endforeach
                    </flux:table.row>
                @endforeach

                <flux:table.row>
                    <flux:table.cell variant="strong">Total charges fixes</flux:table.cell>
                    @foreach ($this->monthTotals as $monthKey => $monthTotal)
                        <flux:table.cell align="end" variant="strong" class="whitespace-nowrap tabular-nums" data-test="month-total-{{ $monthKey }}">
                            {{ number_format($monthTotal, 0, ',', ' ') }} FCFA
                        </flux:table.cell>
                    @endforeach
                </flux:table.row>
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:text size="sm" class="mt-4">
        Laissez une case vide (ou à 0) si vous ne prévoyez rien pour ce type ce mois-là. Les montants ne sont pris en compte qu'après « Enregistrer ». Pour ajouter un type à cette grille, activez « Planifié chaque mois » dans <a href="{{ route('back-office.expense-types.index') }}" wire:navigate class="underline">Types de dépense</a>.
    </flux:text>
</div>
