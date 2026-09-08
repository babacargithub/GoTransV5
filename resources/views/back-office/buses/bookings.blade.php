<x-layouts.back-office :title="$bus->name . ' — Réservations'">
    @php
        $bookingsCollection = collect($bookings);
        $numberOfBookings = $bookingsCollection->count();
        $numberOfAssignedSeats = $bookingsCollection->where('hasSeat', true)->count();
        $numberOfPaidTickets = $bookingsCollection->where('hasTicket', true)->count();
        $numberOfGpBookings = $bookingsCollection->where('isForGp', true)->count();
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
        <flux:text class="mt-1">{{ $departLabel }}</flux:text>

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
                        @foreach ($bookingsCollection as $booking)
                            <flux:table.row :key="$booking['id']">
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
                                    @if ($booking['paymentMethod'])
                                        <flux:badge size="sm" color="blue">{{ $booking['paymentMethod'] }}</flux:badge>
                                    @elseif ($booking['hasTicket'])
                                        <flux:badge size="sm" color="zinc">Payé</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="amber">Non payé</flux:badge>
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

                                        <flux:tooltip content="Détails de la réservation">
                                            <flux:button
                                                size="sm"
                                                variant="ghost"
                                                icon="information-circle"
                                                icon:class="text-indigo-600 dark:text-indigo-400"
                                                aria-label="Détails de la réservation"
                                            />
                                        </flux:tooltip>
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        @endif
    </div>
</x-layouts.back-office>
