@php
    $bookingRows = collect($this->bookingRows);
    $numberOfBookings = $bookingRows->count();
    $numberOfAssignedSeats = $bookingRows->where('hasSeat', true)->count();
    $numberOfPaidTickets = $bookingRows->where('hasTicket', true)->count();
    $numberOfGpBookings = $bookingRows->where('isForGp', true)->count();
@endphp

<div class="mx-auto w-full max-w-6xl">
    <flux:button
        :href="route('back-office.departs.index')"
        variant="ghost"
        size="sm"
        icon="arrow-left"
        class="mb-4"
    >
        Retour aux départs
    </flux:button>

    <flux:heading size="xl" level="1">Réservations — {{ $bus->name }}</flux:heading>
    <flux:text class="mt-1">{{ $this->departLabel() }}</flux:text>

    @if ($flashStatusMessage)
        <flux:callout class="mt-4" variant="success" icon="check-circle" wire:key="flash-status">
            <flux:callout.text>{{ $flashStatusMessage }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($flashErrorMessage)
        <flux:callout class="mt-4" variant="danger" icon="exclamation-triangle" wire:key="flash-error">
            <flux:callout.text>{{ $flashErrorMessage }}</flux:callout.text>
        </flux:callout>
    @endif

    <div class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <flux:card class="space-y-1">
            <flux:text class="text-sm">Total réservations</flux:text>
            <flux:heading size="lg">{{ $numberOfBookings }}</flux:heading>
        </flux:card>

        <flux:card class="space-y-1">
            <flux:text class="text-sm">Sièges attribués</flux:text>
            <flux:heading size="lg">{{ $numberOfAssignedSeats }}</flux:heading>
        </flux:card>

        <flux:card class="space-y-1">
            <flux:text class="text-sm">Billets payés</flux:text>
            <flux:heading size="lg">{{ $numberOfPaidTickets }}</flux:heading>
        </flux:card>

        <flux:card class="space-y-1">
            <flux:text class="text-sm">Réservations GP</flux:text>
            <flux:heading size="lg">{{ $numberOfGpBookings }}</flux:heading>
        </flux:card>
    </div>

    <flux:separator class="my-6" variant="subtle" />

    @if ($numberOfBookings === 0)
        <flux:callout icon="information-circle">
            <flux:callout.heading>Aucune réservation</flux:callout.heading>
            <flux:callout.text>Ce bus n'a pas encore de passagers.</flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Siège</flux:table.column>
                    <flux:table.column>Client</flux:table.column>
                    <flux:table.column>Téléphone</flux:table.column>
                    <flux:table.column>Destination</flux:table.column>
                    <flux:table.column>P. départ</flux:table.column>
                    <flux:table.column>Payé par</flux:table.column>
                    <flux:table.column>Actions</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($bookingRows as $booking)
                        <flux:table.row wire:key="booking-{{ $booking['id'] }}">
                            <flux:table.cell variant="strong">
                                <div class="flex items-center gap-2">
                                    @if ($booking['seatNumber'])
                                        <flux:badge size="sm" color="green">{{ $booking['seatNumber'] }}</flux:badge>
                                    @else
                                        <flux:text class="text-zinc-400 dark:text-zinc-500">—</flux:text>
                                    @endif

                                    @if ($booking['isForGp'])
                                        <flux:tooltip content="Réservation GP">
                                            <flux:icon.user variant="mini" class="text-red-500 dark:text-red-400" />
                                        </flux:tooltip>
                                    @endif

                                    @if ($booking['belongsToGroup'])
                                        <flux:tooltip content="Fait partie d'un groupe">
                                            <flux:icon.user-group variant="mini" class="text-violet-500 dark:text-violet-400" />
                                        </flux:tooltip>
                                    @endif

                                    @if ($booking['isRoundTrip'])
                                        <flux:tooltip content="Aller-retour">
                                            <flux:icon.arrows-right-left variant="mini" class="text-sky-500 dark:text-sky-400" />
                                        </flux:tooltip>
                                    @endif
                                </div>
                            </flux:table.cell>

                            <flux:table.cell variant="strong">{{ $booking['client']['fullName'] }}</flux:table.cell>

                            <flux:table.cell class="whitespace-nowrap">
                                <a
                                    href="tel:{{ $booking['client']['phoneNumber'] }}"
                                    class="font-medium text-indigo-600 hover:underline dark:text-indigo-400"
                                >
                                    {{ $booking['client']['phoneNumber'] }}
                                </a>
                            </flux:table.cell>

                            <flux:table.cell>{{ $booking['destination'] }}</flux:table.cell>

                            <flux:table.cell>{{ $booking['pointDep'] }}</flux:table.cell>

                            <flux:table.cell>
                                @if ($booking['hasTicket'])
                                    @if ($booking['paymentMethod'])
                                        <flux:badge size="sm" color="blue">{{ $booking['paymentMethod'] }}</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="zinc">Payé</flux:badge>
                                    @endif
                                @else
                                    <div class="flex flex-wrap items-center gap-1" wire:target="askToConfirmTicketPayment({{ $booking['id'] }}),sendWavePaymentReminder({{ $booking['id'] }}),sendOrangeMoneyPaymentReminder({{ $booking['id'] }})" wire:loading.class="opacity-50 pointer-events-none">
                                        <flux:tooltip content="Encaisser le paiement maintenant">
                                            <flux:button
                                                size="xs"
                                                variant="primary"
                                                icon="banknotes"
                                                wire:click="askToConfirmTicketPayment({{ $booking['id'] }})"
                                            >
                                                Payer
                                            </flux:button>
                                        </flux:tooltip>

                                        <flux:tooltip content="Envoyer une relance de paiement Wave au client">
                                            <flux:button
                                                size="xs"
                                                variant="filled"
                                                wire:click="sendWavePaymentReminder({{ $booking['id'] }})"
                                                wire:loading.attr="disabled"
                                                wire:target="sendWavePaymentReminder({{ $booking['id'] }})"
                                            >
                                                Wave
                                            </flux:button>
                                        </flux:tooltip>

                                        <flux:tooltip content="Envoyer une relance de paiement Orange Money au client">
                                            <flux:button
                                                size="xs"
                                                variant="filled"
                                                wire:click="sendOrangeMoneyPaymentReminder({{ $booking['id'] }})"
                                                wire:loading.attr="disabled"
                                                wire:target="sendOrangeMoneyPaymentReminder({{ $booking['id'] }})"
                                            >
                                                OM
                                            </flux:button>
                                        </flux:tooltip>
                                    </div>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell class="py-0">
                                @include('livewire.back-office.partials.booking-row-actions', ['booking' => $booking])
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif

    @include('livewire.back-office.partials.booking-action-modals')

    <flux:modal wire:model.self="showPaymentMethodModal" wire:key="booking-payment-method-modal" class="w-full max-w-lg">
        @if ($showPaymentMethodModal)
        <form wire:submit="submitTicketPayment" class="space-y-6">
            <div>
                <flux:heading size="lg">Encaisser le paiement</flux:heading>
                <flux:text class="mt-2">Comment ce client a-t-il payé ? Un siège disponible sera attribué automatiquement et le client sera notifié.</flux:text>
            </div>

            <flux:radio.group wire:model.live="paymentMethodChoice" label="Moyen de paiement">
                <flux:radio value="cash" label="Cash / Transfert reçu" />
                <flux:radio value="wave" label="Wave non traité" description="Le client a payé mais le callback Wave n'a pas été traité. La caisse Wave sera créditée." />
                <flux:radio value="om" label="O.M non traité" description="Le client a payé mais le callback Orange Money n'a pas été traité. La caisse OM sera créditée." />
            </flux:radio.group>

            @if ($paymentMethodChoice !== 'cash')
                <flux:field>
                    <flux:label>ID de transaction {{ $paymentMethodChoice === 'wave' ? 'Wave' : 'Orange Money' }} (obligatoire)</flux:label>
                    <flux:input wire:model="providerTransactionId" placeholder="{{ $paymentMethodChoice === 'wave' ? 'T_xxxxxxxx…' : 'MP261008.1207.A33744' }}" />
                    <flux:error name="providerTransactionId" />
                </flux:field>

                <flux:field>
                    <flux:label>Capture d'écran de la transaction (optionnel, plusieurs possibles)</flux:label>
                    <input type="file" wire:model="proofScreenshots" accept="image/*" multiple class="block w-full text-sm text-zinc-700 file:me-3 file:rounded file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 dark:text-zinc-300 dark:file:bg-zinc-700" />
                    <div wire:loading wire:target="proofScreenshots" class="mt-1 text-xs text-zinc-500">Chargement…</div>
                    <flux:error name="proofScreenshots" />
                    <flux:error name="proofScreenshots.*" />
                </flux:field>

                <flux:field>
                    <flux:label>Texte de preuve (optionnel, ex. SMS de confirmation)</flux:label>
                    <flux:textarea wire:model="proofNote" rows="3" />
                    <flux:error name="proofNote" />
                </flux:field>
            @endif

            <div class="flex items-center justify-end gap-2">
                <flux:button variant="ghost" wire:click="closePaymentMethodModal">Annuler</flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="submitTicketPayment,proofScreenshots">
                    Encaisser le paiement
                </flux:button>
            </div>
        </form>
        @endif
    </flux:modal>
</div>
