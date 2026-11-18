@php
    $formatFcfa = fn (int $amount): string => number_format($amount, 0, ',', ' ').' FCFA';
    $periodLabel = $dateFrom !== '' && $dateTo !== ''
        ? 'Du '.\Illuminate\Support\Carbon::parse($dateFrom)->locale('fr')->translatedFormat('d F Y').' au '.\Illuminate\Support\Carbon::parse($dateTo)->locale('fr')->translatedFormat('d F Y')
        : 'Toute la période';
@endphp

<div class="mx-auto w-full max-w-5xl">
    <flux:button :href="$this->backToReportUrl()" wire:navigate variant="ghost" icon="arrow-left" size="sm" class="-ms-2 mb-2">Retour au bilan</flux:button>

    <div>
        <flux:heading size="xl" level="1">{{ $this->expenseTypeName() }}</flux:heading>
        <flux:text class="mt-1">Dépenses · {{ $periodLabel }}</flux:text>
    </div>

    <flux:separator class="my-6" variant="subtle" />

    @if (count($this->operationRows) === 0)
        <flux:callout icon="information-circle">
            <flux:callout.text>Aucune opération pour ce type de dépense sur cette période.</flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Date</flux:table.column>
                    <flux:table.column>Libellé</flux:table.column>
                    <flux:table.column>Caisse</flux:table.column>
                    <flux:table.column>Compte</flux:table.column>
                    <flux:table.column>Saisi par</flux:table.column>
                    <flux:table.column align="end">Montant</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->operationRows as $operationRow)
                        <flux:table.row wire:key="expense-operation-{{ $operationRow['id'] }}">
                            <flux:table.cell class="whitespace-nowrap">{{ $operationRow['date'] }}</flux:table.cell>
                            <flux:table.cell variant="strong">{{ $operationRow['label'] }}</flux:table.cell>
                            <flux:table.cell>{{ $operationRow['caisseNames'] }}</flux:table.cell>
                            <flux:table.cell>{{ $operationRow['accountName'] }}</flux:table.cell>
                            <flux:table.cell>{{ $operationRow['userName'] }}</flux:table.cell>
                            <flux:table.cell align="end" class="whitespace-nowrap">{{ $formatFcfa($operationRow['amount']) }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach

                    <flux:table.row>
                        <flux:table.cell colspan="5" variant="strong">Total ({{ count($this->operationRows) }} opération(s))</flux:table.cell>
                        <flux:table.cell align="end" variant="strong" class="whitespace-nowrap" data-test="operations-total">{{ $formatFcfa($this->totalAmount) }}</flux:table.cell>
                    </flux:table.row>
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</div>
