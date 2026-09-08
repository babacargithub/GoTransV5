<x-layouts.back-office title="Départs — Back Office">
    <div class="mx-auto w-full max-w-5xl">
        <flux:heading size="xl" level="1">Liste des départs</flux:heading>
        <flux:text class="mt-1">Départs à venir</flux:text>

        <flux:separator class="my-6" variant="subtle" />

        <div class="space-y-4">
            @forelse ($departs as $depart)
                <flux:card class="space-y-4">
                    <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
                        <div class="min-w-0">
                            <flux:heading size="lg">{{ $depart['name'] }}</flux:heading>
                            <flux:text class="mt-1 text-sm">
                                {{ \Illuminate\Support\Carbon::parse($depart['date'])->isoFormat('dddd D MMMM YYYY [à] HH[h]mm') }}
                            </flux:text>
                        </div>

                        <div class="ms-auto flex flex-wrap items-center justify-end gap-2">
                            <flux:tooltip content="Ajouter un bus">
                                <flux:button size="sm" icon="plus" variant="primary" aria-label="Ajouter un bus" />
                            </flux:tooltip>

                            <flux:tooltip content="Ventes de billets">
                                <flux:button size="sm" icon="banknotes" variant="filled" aria-label="Ventes de billets" />
                            </flux:tooltip>

                            <flux:tooltip content="Modifier le départ">
                                <flux:button size="sm" icon="pencil-square" variant="filled" aria-label="Modifier le départ" />
                            </flux:tooltip>

                            <flux:tooltip content="Exporter les données">
                                <flux:button size="sm" icon="arrow-down-tray" variant="filled" aria-label="Exporter les données" />
                            </flux:tooltip>

                            <flux:dropdown position="bottom" align="end">
                                <flux:button size="sm" icon="ellipsis-vertical" variant="subtle" inset="right" aria-label="Plus d'actions" />

                                <flux:menu>
                                    <flux:menu.item icon="document-chart-bar">Voir le bilan</flux:menu.item>
                                    <flux:menu.item icon="chart-pie">Répartition des clients</flux:menu.item>
                                    <flux:menu.item icon="clock">Gestion des rendez-vous</flux:menu.item>
                                    <flux:menu.item icon="paper-airplane">Envoi des rendez-vous</flux:menu.item>
                                    <flux:menu.separator />
                                    <flux:menu.item icon="x-circle" variant="danger">Annuler ce départ</flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </div>
                    </div>

                    <flux:separator variant="subtle" />

                    <div class="space-y-2">
                        @forelse ($depart['buses'] as $bus)
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700">
                                <div class="flex items-center gap-1.5">
                                    <flux:text class="font-medium">{{ $bus['name'] }}</flux:text>

                                    @if ($bus['closed'])
                                        <flux:tooltip content="Fermé">
                                            <flux:icon.lock-closed variant="micro" class="text-zinc-400 dark:text-zinc-500" />
                                        </flux:tooltip>
                                    @endif
                                </div>

                                <div class="flex flex-wrap items-center gap-1.5">
                                    <flux:tooltip content="Réservations">
                                        <flux:badge size="sm" color="blue" icon="user-group">
                                            {{ $bus['numberOfBookings'] }}
                                        </flux:badge>
                                    </flux:tooltip>

                                    <flux:tooltip content="Billets payés">
                                        <flux:badge size="sm" color="green" icon="ticket">
                                            {{ $bus['numberOfTicketSold'] }}
                                        </flux:badge>
                                    </flux:tooltip>

                                    <flux:tooltip content="Places réservées">
                                        <flux:badge size="sm" color="amber" icon="check-circle">
                                            {{ $bus['numberOfBookedSeats'] }}
                                        </flux:badge>
                                    </flux:tooltip>
                                </div>

                                <flux:button
                                    :href="route('back-office.buses.bookings', $bus['id'])"
                                    size="xs"
                                    variant="subtle"
                                    icon="user-group"
                                    class="ms-auto"
                                >
                                    Voir les réservations
                                </flux:button>
                            </div>
                        @empty
                            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                Aucun bus pour ce départ.
                            </flux:text>
                        @endforelse
                    </div>
                </flux:card>
            @empty
                <flux:callout icon="information-circle">
                    <flux:callout.heading>Aucun départ à venir</flux:callout.heading>
                    <flux:callout.text>Les nouveaux départs apparaîtront ici une fois créés.</flux:callout.text>
                </flux:callout>
            @endforelse
        </div>
    </div>
</x-layouts.back-office>
