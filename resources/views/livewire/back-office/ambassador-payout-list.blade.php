<div class="mx-auto w-full max-w-6xl">
    <div>
        <flux:heading size="xl" level="1">Commissions ambassadeurs</flux:heading>
        <flux:text class="mt-1">Les sommes à verser, les paiements Wave et leur statut</flux:text>
    </div>

    <x-flash-toasts />

    <flux:separator class="my-6" variant="subtle" />

    <flux:heading size="lg" level="2">À payer</flux:heading>
    <flux:text class="mt-1 mb-3 text-sm">Montant minimum par paiement : {{ number_format($this->minimumPayoutAmount, 0, ',', ' ') }} FCFA</flux:text>

    @if (count($this->ambassadorsWithPayableBalance) === 0)
        <flux:callout icon="information-circle">
            <flux:callout.heading>Rien à payer</flux:callout.heading>
            <flux:callout.text>Aucun ambassadeur actif n'a de gain approuvé en attente de paiement.</flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Ambassadeur</flux:table.column>
                    <flux:table.column>Numéro Wave</flux:table.column>
                    <flux:table.column align="end">Montant dû</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->ambassadorsWithPayableBalance as $payableRow)
                        <flux:table.row wire:key="payable-ambassador-{{ $payableRow['ambassador']->id }}">
                            <flux:table.cell variant="strong">{{ $payableRow['ambassador']->full_name }}</flux:table.cell>
                            <flux:table.cell>{{ $payableRow['ambassador']->payout_phone_number }}</flux:table.cell>
                            <flux:table.cell align="end">
                                @if ($payableRow['payableAmount'] === null)
                                    <flux:badge size="sm" color="red">Montant invalide</flux:badge>
                                @else
                                    {{ number_format($payableRow['payableAmount'], 0, ',', ' ') }} FCFA
                                    @unless ($payableRow['canBePaid'])
                                        <flux:badge size="sm" color="zinc">Sous le minimum</flux:badge>
                                    @endunless
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @if ($this->abilities['pay'] && $payableRow['canBePaid'])
                                    <div class="flex justify-end">
                                        <flux:button
                                            size="sm"
                                            variant="primary"
                                            icon="banknotes"
                                            wire:click="payAmbassador({{ $payableRow['ambassador']->id }})"
                                            wire:confirm="Envoyer {{ number_format($payableRow['payableAmount'], 0, ',', ' ') }} FCFA à {{ $payableRow['ambassador']->full_name }} par Wave ?"
                                        >Payer</flux:button>
                                    </div>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif

    <flux:separator class="my-8" variant="subtle" />

    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <flux:heading size="lg" level="2">Historique des paiements</flux:heading>

        <div class="w-full sm:w-64">
            <flux:select wire:model.live="statusFilter" placeholder="Tous les statuts">
                <flux:select.option value="">Tous les statuts</flux:select.option>
                @foreach ($this->statusOptions as $statusValue => $statusLabel)
                    <flux:select.option :value="$statusValue">{{ $statusLabel }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>

    @if ($this->payouts->isEmpty())
        <flux:callout icon="information-circle">
            <flux:callout.heading>Aucun paiement</flux:callout.heading>
            <flux:callout.text>Aucun paiement ne correspond à ce filtre.</flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Date</flux:table.column>
                    <flux:table.column>Ambassadeur</flux:table.column>
                    <flux:table.column align="end">Montant</flux:table.column>
                    <flux:table.column align="center">Statut</flux:table.column>
                    <flux:table.column align="center">Tentatives</flux:table.column>
                    <flux:table.column>Détail</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->payouts as $payout)
                        <flux:table.row wire:key="payout-{{ $payout->id }}">
                            <flux:table.cell>{{ $payout->created_at->format('d/m/Y H:i') }}</flux:table.cell>
                            <flux:table.cell variant="strong">{{ $payout->ambassador->full_name }}</flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($payout->amount, 0, ',', ' ') }} FCFA</flux:table.cell>
                            <flux:table.cell align="center">
                                <flux:badge size="sm" :color="$payout->status->badgeColor()">{{ $payout->status->label() }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell align="center">{{ $payout->attempts_count }} / {{ \App\Models\AmbassadorPayout::MAX_ATTEMPTS }}</flux:table.cell>
                            <flux:table.cell class="max-w-xs whitespace-normal text-sm">
                                @if ($payout->failure_reason)
                                    <span class="text-red-600 dark:text-red-400">{{ $payout->failure_reason }}</span>
                                @elseif ($payout->paid_at)
                                    Payé le {{ $payout->paid_at->format('d/m/Y H:i') }}
                                @elseif ($payout->provider_payout_id)
                                    Accepté par Wave, en attente de confirmation
                                @else
                                    —
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @if ($this->abilities['pay'] && $payout->canBeSent())
                                    <div class="flex justify-end">
                                        <flux:button
                                            size="sm"
                                            icon="arrow-path"
                                            wire:click="retryPayout({{ $payout->id }})"
                                            wire:confirm="Renvoyer {{ number_format($payout->amount, 0, ',', ' ') }} FCFA à {{ $payout->ambassador->full_name }} par Wave ?"
                                        >Renvoyer</flux:button>
                                    </div>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>

        <div class="mt-4">{{ $this->payouts->links() }}</div>
    @endif
</div>
