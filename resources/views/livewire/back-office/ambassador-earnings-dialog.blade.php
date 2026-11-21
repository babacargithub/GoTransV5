<div class="space-y-4">
    <div>
        <flux:heading size="lg" level="2">Gains de {{ $this->ambassador->full_name }}</flux:heading>
        <flux:text class="mt-1">Approuver un gain en attente le rend payable sans attendre la fin de son délai.</flux:text>
    </div>

    <x-flash-toasts />

    <flux:select wire:model.live="statusFilter" aria-label="Filtrer les gains">
        <flux:select.option value="">Tous les gains</flux:select.option>
        @foreach (\App\Enums\AmbassadorEarningStatus::cases() as $earningStatus)
            <flux:select.option value="{{ $earningStatus->value }}">{{ $earningStatus->label() }}</flux:select.option>
        @endforeach
    </flux:select>

    @if ($this->earnings->isEmpty())
        <flux:callout icon="information-circle">
            <flux:callout.text>Aucun gain pour ce filtre.</flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    @if ($this->canApproveEarnings())
                        <flux:table.column>
                            <flux:checkbox wire:click="toggleAllPendingOfPage" aria-label="Sélectionner les gains en attente de la page" />
                        </flux:table.column>
                    @endif
                    <flux:table.column>Gain</flux:table.column>
                    <flux:table.column align="end">Montant</flux:table.column>
                    <flux:table.column>Échéance</flux:table.column>
                    <flux:table.column align="center">Statut</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->earnings as $earning)
                        <flux:table.row wire:key="dialog-earning-{{ $earning->id }}">
                            @if ($this->canApproveEarnings())
                                <flux:table.cell>
                                    @if ($earning->status === \App\Enums\AmbassadorEarningStatus::Pending)
                                        <flux:checkbox wire:model.live="selectedEarningIds" value="{{ $earning->id }}" aria-label="Sélectionner ce gain" />
                                    @endif
                                </flux:table.cell>
                            @endif
                            <flux:table.cell>
                                <div class="font-medium">{{ $earning->type->label() }}</div>
                                @if ($earning->label)
                                    <div class="text-xs text-zinc-500">{{ $earning->label }}</div>
                                @endif
                                @if ($earning->early_approval_reason)
                                    <div class="text-xs text-zinc-500">Approuvé avant échéance : {{ $earning->early_approval_reason }}</div>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($earning->amount, 0, ',', ' ') }} FCFA</flux:table.cell>
                            <flux:table.cell>{{ ($earning->approved_at ?? $earning->approvable_at)?->format('d/m/Y H:i') ?? '—' }}</flux:table.cell>
                            <flux:table.cell align="center">
                                <flux:badge size="sm" :color="$earning->status->badgeColor()">{{ $earning->status->label() }}</flux:badge>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>

        <div>{{ $this->earnings->links() }}</div>
    @endif

    @if ($this->canApproveEarnings() && count($selectedEarningIds) > 0)
        <div class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:field>
                <flux:label>Motif de l'approbation ({{ count($selectedEarningIds) }} gain(s) sélectionné(s))</flux:label>
                <flux:input wire:model="approvalReason" placeholder="Ex. test bêta validé par l'équipe" />
                <flux:error name="approvalReason" />
                <flux:error name="selectedEarningIds" />
            </flux:field>

            <div class="flex justify-end">
                <flux:button
                    variant="primary"
                    icon="check"
                    wire:click="approveSelectedEarnings"
                    wire:confirm="Approuver {{ count($selectedEarningIds) }} gain(s) avant leur échéance ? Ils deviendront payables."
                    wire:loading.attr="disabled"
                    wire:target="approveSelectedEarnings"
                >Approuver la sélection</flux:button>
            </div>
        </div>
    @endif
</div>
