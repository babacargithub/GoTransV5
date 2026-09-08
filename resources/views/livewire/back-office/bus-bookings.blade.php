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

                            <flux:table.cell class="whitespace-nowrap">{{ $booking['client']['phoneNumber'] }}</flux:table.cell>

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
                                <div class="flex items-center gap-0.5">
                                    <flux:tooltip content="Modifier la réservation">
                                        <flux:button
                                            size="sm"
                                            variant="ghost"
                                            icon="pencil-square"
                                            icon:class="text-amber-600 dark:text-amber-400"
                                            aria-label="Modifier la réservation"
                                        />
                                    </flux:tooltip>

                                    <flux:tooltip content="Annuler la réservation">
                                        <flux:button
                                            size="sm"
                                            variant="ghost"
                                            icon="x-circle"
                                            icon:class="text-red-600 dark:text-red-400"
                                            aria-label="Annuler la réservation"
                                            wire:click="askToConfirmBookingCancellation({{ $booking['id'] }})"
                                        />
                                    </flux:tooltip>

                                    <flux:tooltip content="Transférer vers un autre bus">
                                        <flux:button
                                            size="sm"
                                            variant="ghost"
                                            icon="arrow-right-circle"
                                            icon:class="text-emerald-600 dark:text-emerald-400"
                                            aria-label="Transférer la réservation"
                                        />
                                    </flux:tooltip>

                                    @php
                                        $transactionId = data_get($booking, 'extra_info.transactionId');
                                        $groupId = data_get($booking, 'extra_info.group_id');
                                        $canBeRefunded = $booking['hasTicket'] && data_get($booking, 'paymentMethod') === 'wave';
                                    @endphp

                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button
                                            size="sm"
                                            variant="ghost"
                                            icon="information-circle"
                                            icon:class="text-indigo-600 dark:text-indigo-400"
                                            aria-label="Détails de la réservation"
                                        />

                                        <flux:menu class="w-72">
                                            <div class="space-y-4 p-2">
                                                <div class="flex items-center gap-2.5">
                                                    <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-white">
                                                        <flux:icon.information-circle variant="mini" class="size-4" />
                                                    </span>
                                                    <flux:heading class="text-zinc-900 dark:text-white">Informations réservation</flux:heading>
                                                </div>

                                                <flux:separator variant="subtle" />

                                                <dl class="space-y-3">
                                                    <div>
                                                        <dt class="text-[0.65rem] font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">ID</dt>
                                                        <dd class="mt-0.5 text-sm font-semibold text-zinc-900 dark:text-white">{{ $booking['id'] }}</dd>
                                                    </div>

                                                    <div>
                                                        <dt class="text-[0.65rem] font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Transaction ID</dt>
                                                        <dd class="mt-0.5 flex flex-wrap items-center gap-2">
                                                            <span class="font-mono text-sm font-semibold text-zinc-900 dark:text-white">{{ $transactionId ?: 'N/A' }}</span>

                                                            @if ($canBeRefunded)
                                                                <flux:button
                                                                    size="xs"
                                                                    variant="danger"
                                                                    icon="arrow-uturn-left"
                                                                    wire:click="askToConfirmRefund({{ $booking['id'] }})"
                                                                >
                                                                    Rembourser
                                                                </flux:button>
                                                            @endif
                                                        </dd>
                                                    </div>

                                                    <div>
                                                        <dt class="text-[0.65rem] font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Group ID</dt>
                                                        <dd class="mt-0.5 text-sm font-semibold text-zinc-900 dark:text-white">{{ $groupId ?: 'N/A' }}</dd>
                                                    </div>
                                                </dl>

                                                <flux:separator variant="subtle" />

                                                @if ($booking['hasTicket'])
                                                    <flux:button
                                                        :href="route('back-office.bookings.ticket', $booking['id'])"
                                                        target="_blank"
                                                        size="sm"
                                                        variant="primary"
                                                        icon="arrow-down-tray"
                                                        class="w-full"
                                                    >
                                                        Télécharger le ticket
                                                    </flux:button>
                                                @else
                                                    <flux:text class="text-zinc-500 dark:text-zinc-400">Aucun billet émis pour cette réservation.</flux:text>
                                                @endif
                                            </div>
                                        </flux:menu>
                                    </flux:dropdown>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif

    <flux:modal wire:model.self="showConfirmationModal" class="min-w-[22rem] max-w-md">
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
    </flux:modal>
</div>
