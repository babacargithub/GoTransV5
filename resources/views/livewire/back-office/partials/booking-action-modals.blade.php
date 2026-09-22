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
