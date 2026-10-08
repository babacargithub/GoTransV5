{{--
    Shared booking-action modals (modifier / transférer / annuler-rembourser)
    driven by the ManagesBookingActions trait. Included once per host component
    (bus passengers page, header customer-search dialog).
--}}

<flux:modal wire:model.self="showEditModal" wire:key="booking-edit-modal" class="w-full max-w-md">
    @if ($showEditModal)
    @php($editableTrajetStops = $this->editableTrajetStops)

    <form wire:submit="saveBookingEdit" class="space-y-6">
        <div>
            <flux:heading size="lg">Modifier la réservation</flux:heading>
            <flux:text class="mt-2">
                Seuls le point de départ et la destination peuvent être modifiés. Les choix sont limités aux arrêts du trajet de la réservation.
            </flux:text>
        </div>

        <flux:field>
            <flux:label>Point de départ</flux:label>
            <flux:select wire:model="editPointDepId" placeholder="Choisir un point de départ">
                @foreach ($editableTrajetStops['pointDeps'] as $pointDep)
                    <flux:select.option :value="$pointDep['id']">{{ $pointDep['name'] }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="editPointDepId" />
        </flux:field>

        <flux:field>
            <flux:label>Destination</flux:label>
            <flux:select wire:model="editDestinationId" placeholder="Choisir une destination">
                @foreach ($editableTrajetStops['destinations'] as $destination)
                    <flux:select.option :value="$destination['id']">{{ $destination['name'] }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="editDestinationId" />
        </flux:field>

        <div class="flex items-center justify-end gap-2">
            <flux:button variant="ghost" wire:click="closeBookingEditModal">Annuler</flux:button>

            <flux:button
                type="submit"
                variant="primary"
                wire:loading.attr="disabled"
                wire:target="saveBookingEdit"
            >
                Enregistrer
            </flux:button>
        </div>
    </form>
    @endif
</flux:modal>

<flux:modal wire:model.self="showTransferModal" wire:key="booking-transfer-modal" class="w-full max-w-2xl">
    @if ($showTransferModal)
    <div class="space-y-6">
        <div>
            <flux:heading size="lg">Transférer la réservation</flux:heading>
            <flux:text class="mt-2">
                Choisissez le bus de destination parmi les départs à venir. Le siège sera réattribué automatiquement et le client sera notifié.
            </flux:text>
        </div>

        @if ($transferErrorMessage)
            <flux:callout variant="danger" icon="exclamation-triangle" wire:key="transfer-error">
                <flux:callout.text>{{ $transferErrorMessage }}</flux:callout.text>
            </flux:callout>
        @endif

        @php($transferDepartOptions = $this->transferDepartOptions)

        @if (count($transferDepartOptions) === 0)
            <flux:callout icon="information-circle">
                <flux:callout.text>Aucun autre bus disponible sur les départs à venir.</flux:callout.text>
            </flux:callout>
        @else
            <div class="max-h-[26rem] space-y-4 overflow-y-auto pr-1">
                @foreach ($transferDepartOptions as $departOption)
                    <div wire:key="transfer-depart-{{ $departOption['id'] }}">
                        <div class="flex items-baseline justify-between gap-2">
                            <flux:heading size="sm">{{ $departOption['label'] }}</flux:heading>
                            <flux:text class="text-xs whitespace-nowrap">{{ $departOption['date'] }}</flux:text>
                        </div>

                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            @foreach ($departOption['buses'] as $candidateBus)
                                <div
                                    class="flex items-center justify-between gap-2 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700"
                                    wire:key="transfer-bus-{{ $candidateBus['id'] }}"
                                >
                                    <div class="min-w-0">
                                        <flux:text class="truncate font-medium text-zinc-900 dark:text-white">{{ $candidateBus['name'] }}</flux:text>
                                        <flux:text class="text-xs">
                                            @if ($candidateBus['isFull'])
                                                Complet
                                            @else
                                                {{ $candidateBus['numberOfSeatsLeft'] }} place(s) restante(s)
                                            @endif
                                        </flux:text>
                                    </div>

                                    <flux:button
                                        size="xs"
                                        variant="primary"
                                        icon="arrow-right-circle"
                                        :disabled="$candidateBus['isFull']"
                                        wire:click="transferBookingToBus({{ $candidateBus['id'] }})"
                                        wire:loading.attr="disabled"
                                        wire:target="transferBookingToBus({{ $candidateBus['id'] }})"
                                    >
                                        Transférer
                                    </flux:button>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="flex items-center justify-end">
            <flux:button variant="ghost" wire:click="closeBookingTransferModal">Fermer</flux:button>
        </div>
    </div>
    @endif
</flux:modal>

<flux:modal wire:model.self="showConfirmationModal" wire:key="booking-confirmation-modal" class="min-w-[22rem] max-w-md">
    @if ($showConfirmationModal)
    <div class="space-y-6">
        <div>
            <flux:heading size="lg">{{ $this->confirmationModalCopy['heading'] }}</flux:heading>
            <flux:text class="mt-2">{{ $this->confirmationModalCopy['body'] }}</flux:text>
        </div>

        <div class="flex items-center justify-end gap-2">
            <flux:button variant="ghost" wire:click="$set('showConfirmationModal', false)">
                Retour
            </flux:button>

            <flux:button
                :variant="$this->confirmationModalCopy['confirmVariant']"
                wire:click="confirmPendingAction"
                wire:loading.attr="disabled"
                wire:target="confirmPendingAction"
            >
                {{ $this->confirmationModalCopy['confirmLabel'] }}
            </flux:button>
        </div>
    </div>
    @endif
</flux:modal>

<flux:modal wire:model.self="showPaymentDetailsModal" wire:key="booking-payment-details-modal" class="w-full max-w-2xl">
    @if ($showPaymentDetailsModal)
    @php($paymentDetails = $this->paymentDetails)

    <div class="space-y-6">
        <flux:heading size="lg">Détails du paiement</flux:heading>

        @if (! $paymentDetails || ! $paymentDetails['hasTicket'])
            <flux:callout icon="information-circle">
                <flux:callout.text>Aucun paiement enregistré pour cette réservation.</flux:callout.text>
            </flux:callout>
        @else
            <dl class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                @foreach ([
                    'Billet' => '#'.$paymentDetails['ticketNumber'],
                    'Prix' => number_format($paymentDetails['ticketPrice'], 0, ',', ' ').' FCFA',
                    'Moyen de paiement' => strtoupper((string) $paymentDetails['ticketPaymentMethod']) ?: 'N/A',
                    'Vendu par' => $paymentDetails['soldBy'] ?: 'N/A',
                    'Date' => $paymentDetails['soldAt'] ? \Illuminate\Support\Carbon::parse($paymentDetails['soldAt'])->locale('fr')->translatedFormat('d F Y H:i') : 'N/A',
                    'Référence' => $paymentDetails['ticketComment'] ?: 'N/A',
                ] as $detailLabel => $detailValue)
                    <div>
                        <dt class="text-[0.65rem] font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ $detailLabel }}</dt>
                        <dd class="mt-0.5 break-words text-sm font-semibold text-zinc-900 dark:text-white">{{ $detailValue }}</dd>
                    </div>
                @endforeach
            </dl>

            @forelse ($paymentDetails['payments'] as $payment)
                <div class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" wire:key="payment-detail-{{ $payment['id'] }}">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:badge size="sm" color="blue">{{ strtoupper((string) $payment['method']) }}</flux:badge>
                        <flux:badge size="sm" :color="$payment['status'] === 'SUCCESS' ? 'green' : 'zinc'">{{ $payment['status'] }}</flux:badge>
                        @if ($payment['isManual'])
                            <flux:badge size="sm" color="amber">Saisie manuelle</flux:badge>
                        @endif
                        <span class="ms-auto text-sm font-semibold text-zinc-900 dark:text-white">{{ number_format($payment['amount'], 0, ',', ' ') }} FCFA</span>
                    </div>

                    <dl class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <dt class="text-[0.65rem] font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">ID de transaction</dt>
                            <dd class="mt-0.5 break-all font-mono text-sm text-zinc-900 dark:text-white">{{ $payment['providerTransactionId'] ?: 'N/A' }}</dd>
                        </div>
                        @if ($payment['phoneNumber'])
                            <div>
                                <dt class="text-[0.65rem] font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Téléphone</dt>
                                <dd class="mt-0.5 text-sm text-zinc-900 dark:text-white">{{ $payment['phoneNumber'] }}</dd>
                            </div>
                        @endif
                        @if ($payment['recordedBy'])
                            <div>
                                <dt class="text-[0.65rem] font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Enregistré par</dt>
                                <dd class="mt-0.5 text-sm text-zinc-900 dark:text-white">{{ $payment['recordedBy'] }}</dd>
                            </div>
                        @endif
                        @if ($payment['createdAt'])
                            <div>
                                <dt class="text-[0.65rem] font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Enregistré le</dt>
                                <dd class="mt-0.5 text-sm text-zinc-900 dark:text-white">{{ \Illuminate\Support\Carbon::parse($payment['createdAt'])->locale('fr')->translatedFormat('d F Y H:i') }}</dd>
                            </div>
                        @endif
                    </dl>

                    @if ($payment['proofNote'])
                        <div>
                            <flux:text class="text-[0.65rem] font-semibold uppercase tracking-wide">Texte de preuve</flux:text>
                            <p class="mt-1 whitespace-pre-line rounded bg-zinc-50 p-2 text-sm dark:bg-zinc-800">{{ $payment['proofNote'] }}</p>
                        </div>
                    @endif

                    @if (count($payment['proofUrls']) > 0)
                        <div>
                            <flux:text class="text-[0.65rem] font-semibold uppercase tracking-wide">Captures d'écran</flux:text>
                            <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3">
                                @foreach ($payment['proofUrls'] as $proofUrl)
                                    <a href="{{ $proofUrl }}" target="_blank" rel="noopener" wire:key="proof-{{ $payment['id'] }}-{{ $loop->index }}">
                                        <img src="{{ $proofUrl }}" alt="Preuve de paiement {{ $loop->iteration }}" loading="lazy" class="h-32 w-full rounded border border-zinc-200 object-cover dark:border-zinc-700" />
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            @empty
                <flux:callout icon="information-circle">
                    <flux:callout.text>Aucun détail de transaction enregistré pour ce billet (paiement antérieur à l'enregistrement des preuves).</flux:callout.text>
                </flux:callout>
            @endforelse
        @endif

        <div class="flex justify-end">
            <flux:button variant="ghost" wire:click="closePaymentDetails">Fermer</flux:button>
        </div>
    </div>
    @endif
</flux:modal>
