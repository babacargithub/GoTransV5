{{--
    Bookings table for the header customer-search result dialog. Shared by the
    "Réservations" (current) and "Voyages passés" (history) tabs.

    @param array  $rows          BookingResource rows
    @param bool   $canModify      show the "Modifier" row action
    @param bool   $canCancel      show the "Annuler" row action
    @param bool   $canTransfer    show the "Transférer" row action
    @param string $emptyMessage   text shown when there are no rows
--}}
@php
    $canModify ??= true;
    $canCancel ??= true;
    $canTransfer ??= true;
@endphp

@if (count($rows) === 0)
    <flux:callout icon="information-circle">
        <flux:callout.text>{{ $emptyMessage }}</flux:callout.text>
    </flux:callout>
@else
    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Siège</flux:table.column>
                <flux:table.column>Départ</flux:table.column>
                <flux:table.column>Bus</flux:table.column>
                <flux:table.column>Ticket</flux:table.column>
                <flux:table.column>P. départ</flux:table.column>
                <flux:table.column>Destination</flux:table.column>
                <flux:table.column>Paiement</flux:table.column>
                <flux:table.column>Actions</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($rows as $booking)
                    @php($rowIsCancelled = ! empty($booking['isCancelled']))
                    <flux:table.row wire:key="search-booking-{{ $booking['id'] }}">
                        <flux:table.cell variant="strong">
                            <div class="flex items-center gap-2">
                                @if ($booking['seatNumber'])
                                    <flux:badge size="sm" color="green">{{ $booking['seatNumber'] }}</flux:badge>
                                @else
                                    <flux:text class="text-zinc-400 dark:text-zinc-500">—</flux:text>
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

                        <flux:table.cell class="whitespace-nowrap">
                            <div class="flex items-center gap-1.5">
                                @if ($rowIsCancelled)
                                    <flux:tooltip content="Réservation annulée">
                                        <flux:icon.x-circle variant="mini" class="shrink-0 text-red-500 dark:text-red-400" />
                                    </flux:tooltip>
                                @endif
                                <span @class(['line-through decoration-red-400' => $rowIsCancelled])>
                                    {{ $booking['departLabel'] ?? '—' }}
                                </span>
                            </div>
                            @if (! empty($booking['createdAtLabel']))
                                <div class="text-xs font-normal text-zinc-500 dark:text-zinc-400">
                                    Réservé {{ $booking['createdAtLabel'] }}
                                </div>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>{{ $booking['busName'] ?? '—' }}</flux:table.cell>

                        <flux:table.cell>
                            @if ($booking['hasTicket'])
                                <flux:badge size="sm" color="green">Payé</flux:badge>
                            @else
                                <flux:badge size="sm" color="amber">Non payé</flux:badge>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>{{ $booking['pointDep'] }}</flux:table.cell>

                        <flux:table.cell>{{ $booking['destination'] }}</flux:table.cell>

                        <flux:table.cell>
                            @if (! empty($booking['isCancelled']))
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <flux:badge size="sm" color="red">Annulé</flux:badge>
                                    @if (! empty($booking['cancelledBy']))
                                        <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">par {{ $booking['cancelledBy'] }}</flux:text>
                                    @endif
                                </div>
                            @elseif ($booking['paymentMethod'])
                                <flux:badge size="sm" color="blue">{{ $booking['paymentMethod'] }}</flux:badge>
                            @else
                                <flux:text class="text-zinc-400 dark:text-zinc-500">—</flux:text>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell class="py-0">
                            @include('livewire.back-office.partials.booking-row-actions', [
                                'booking' => $booking,
                                'canModify' => $canModify && empty($booking['isCancelled']),
                                'canCancel' => $canCancel && empty($booking['isCancelled']),
                                'canTransfer' => $canTransfer && empty($booking['isCancelled']),
                            ])
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
@endif
