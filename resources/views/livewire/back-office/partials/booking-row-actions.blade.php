{{--
    Shared booking row actions: Modifier / Annuler / Transférer + a "Détails"
    dropdown (ID, transaction ID, group ID, Wave refund, ticket download).

    Driven by the ManagesBookingActions trait, so it works identically on the bus
    passengers page and the header customer-search result dialog.

    @param array $booking      one BookingResource row
    @param bool  $canModify    show the "Modifier" button (default true)
    @param bool  $canCancel    show the "Annuler" button (default true)
    @param bool  $canTransfer  show the "Transférer" button (default true)
--}}
@php
    $canModify ??= true;
    $canCancel ??= true;
    $canTransfer ??= true;
    $transactionId = data_get($booking, 'extra_info.transactionId');
    $groupId = data_get($booking, 'extra_info.group_id');
    $canBeRefunded = $booking['hasTicket'] && data_get($booking, 'paymentMethod') === 'wave';
@endphp

<div class="flex items-center gap-0.5">
    @if ($canModify)
        <flux:tooltip content="Modifier la réservation">
            <flux:button
                size="sm"
                variant="ghost"
                icon="pencil-square"
                icon:class="text-amber-600 dark:text-amber-400"
                aria-label="Modifier la réservation"
                wire:click="openBookingEditModal({{ $booking['id'] }})"
            />
        </flux:tooltip>
    @endif

    @if ($canCancel)
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
    @endif

    @if ($canTransfer)
        <flux:tooltip content="Transférer vers un autre bus">
            <flux:button
                size="sm"
                variant="ghost"
                icon="arrow-right-circle"
                icon:class="text-emerald-600 dark:text-emerald-400"
                aria-label="Transférer la réservation"
                wire:click="openBookingTransferModal({{ $booking['id'] }})"
            />
        </flux:tooltip>
    @endif

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
