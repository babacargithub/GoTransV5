<div class="mx-auto w-full max-w-5xl">
    <flux:heading size="xl" level="1">Liste des départs</flux:heading>
    <flux:text class="mt-1">Départs à venir</flux:text>

    @if (session('status'))
        <flux:callout class="mt-4" variant="success" icon="check-circle">
            <flux:callout.text>{{ session('status') }}</flux:callout.text>
        </flux:callout>
    @endif

    @if (session('error'))
        <flux:callout class="mt-4" variant="danger" icon="exclamation-triangle">
            <flux:callout.text>{{ session('error') }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:separator class="my-6" variant="subtle" />

    <div class="space-y-4">
        @forelse ($this->departRows as $depart)
            <flux:card class="space-y-4" wire:key="depart-{{ $depart['id'] }}">
                <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
                    <div class="min-w-0">
                        <flux:heading size="lg">{{ $depart['name'] }}</flux:heading>
                        <flux:text class="mt-1 text-sm">
                            {{ \Illuminate\Support\Carbon::parse($depart['date'])->isoFormat('dddd D MMMM YYYY [à] HH[h]mm') }}
                        </flux:text>
                    </div>

                    <div class="ms-auto flex flex-wrap items-center justify-end gap-2">
                        <flux:tooltip content="Ajouter un bus">
                            <flux:button
                                :href="route('back-office.departs.add-bus', $depart['id'])"
                                size="sm"
                                icon="plus"
                                variant="primary"
                                aria-label="Ajouter un bus"
                            />
                        </flux:tooltip>

                        <flux:tooltip content="Ventes de billets">
                            <flux:button
                                size="sm"
                                icon="banknotes"
                                variant="filled"
                                aria-label="Ventes de billets"
                                wire:click="openTicketSales({{ $depart['id'] }})"
                            />
                        </flux:tooltip>

                        <flux:tooltip content="Modifier le départ">
                            <flux:button size="sm" icon="pencil-square" variant="filled" aria-label="Modifier le départ" />
                        </flux:tooltip>

                        <flux:tooltip content="Exporter les réservations (PDF)">
                            <flux:button
                                :href="route('back-office.departs.bookings-export', $depart['id'])"
                                target="_blank"
                                size="sm"
                                icon="arrow-down-tray"
                                variant="filled"
                                aria-label="Exporter les réservations"
                            />
                        </flux:tooltip>

                        <flux:dropdown position="bottom" align="end">
                            <flux:button size="sm" icon="ellipsis-vertical" variant="subtle" inset="right" aria-label="Plus d'actions" />

                            <flux:menu class="space-y-1">
                                <flux:menu.item
                                    icon="document-chart-bar"
                                    class="py-2 [&_[data-flux-menu-item-icon]]:!text-sky-500"
                                >
                                    Voir le bilan
                                </flux:menu.item>
                                <flux:menu.item
                                    icon="chart-pie"
                                    class="py-2 [&_[data-flux-menu-item-icon]]:!text-violet-500"
                                    wire:click="openBookingsRepartition({{ $depart['id'] }})"
                                >
                                    Répartition des clients
                                </flux:menu.item>
                                <flux:menu.item
                                    icon="clock"
                                    class="py-2 [&_[data-flux-menu-item-icon]]:!text-amber-500"
                                    wire:click="openScheduleManagement({{ $depart['id'] }})"
                                >
                                    Gestion des rendez-vous
                                </flux:menu.item>
                                <flux:menu.item
                                    icon="paper-airplane"
                                    class="py-2 [&_[data-flux-menu-item-icon]]:!text-emerald-500"
                                >
                                    Envoi des rendez-vous
                                </flux:menu.item>
                                <flux:menu.separator />
                                <flux:menu.item
                                    icon="x-circle"
                                    variant="danger"
                                    class="py-2"
                                    wire:click="askToCancelDepart({{ $depart['id'] }})"
                                >
                                    Annuler ce départ
                                </flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </div>
                </div>

                <flux:separator variant="subtle" />

                <div class="space-y-2">
                    @forelse ($depart['buses'] as $bus)
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700" wire:key="bus-{{ $bus['id'] }}">
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

                                <flux:tooltip content="Voir les réservations">
                                    <flux:button
                                        :href="route('back-office.buses.bookings', $bus['id'])"
                                        size="xs"
                                        variant="primary"
                                        icon="eye"
                                        aria-label="Voir les réservations"
                                        class="[--color-accent:var(--color-indigo-700)] [--color-accent-foreground:var(--color-white)] dark:[--color-accent:var(--color-indigo-600)]"
                                    />
                                </flux:tooltip>

                                <flux:tooltip content="Exporter les réservations du bus (PDF)">
                                    <flux:button
                                        :href="route('back-office.buses.bookings-export', $bus['id'])"
                                        target="_blank"
                                        size="xs"
                                        variant="filled"
                                        icon="arrow-down-tray"
                                        aria-label="Exporter les réservations du bus"
                                    />
                                </flux:tooltip>
                            </div>
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

    {{-- Ventes de billets --}}
    <flux:modal wire:model.self="showTicketSalesModal" wire:key="ticket-sales-modal" class="w-full max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Ventes de billets</flux:heading>
                <flux:text class="mt-1">{{ $this->ticketSalesDepartLabel() }}</flux:text>
            </div>

            @php($ticketSalesRows = $this->ticketSalesRows)

            @if (count($ticketSalesRows) === 0)
                <flux:callout icon="information-circle">
                    <flux:callout.text>Aucun billet vendu pour ce départ.</flux:callout.text>
                </flux:callout>
            @else
                <div class="overflow-x-auto">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Vendu par</flux:table.column>
                            <flux:table.column align="end">Total</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($ticketSalesRows as $ticketSaleRow)
                                <flux:table.row wire:key="ticket-sale-{{ $loop->index }}">
                                    <flux:table.cell variant="strong">{{ $ticketSaleRow['soldBy'] ?? 'Non renseigné' }}</flux:table.cell>
                                    <flux:table.cell align="end">{{ number_format($ticketSaleRow['total'], 0, ',', ' ') }} FCFA</flux:table.cell>
                                </flux:table.row>
                            @endforeach

                            <flux:table.row wire:key="ticket-sale-total">
                                <flux:table.cell variant="strong">Total</flux:table.cell>
                                <flux:table.cell align="end" variant="strong">
                                    {{ number_format($this->ticketSalesTotal(), 0, ',', ' ') }} FCFA
                                </flux:table.cell>
                            </flux:table.row>
                        </flux:table.rows>
                    </flux:table>
                </div>
            @endif

            <div class="flex justify-end">
                <flux:button variant="ghost" wire:click="closeTicketSales">Fermer</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Répartition des clients --}}
    <flux:modal wire:model.self="showBookingsRepartitionModal" wire:key="bookings-repartition-modal" class="w-full max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Répartition des clients</flux:heading>
                <flux:text class="mt-1">
                    {{ $this->bookingsRepartitionDepartLabel() }} — nombre de billets payés par point de départ
                </flux:text>
            </div>

            @php($bookingsRepartitionRows = $this->bookingsRepartitionRows)

            @if (count($bookingsRepartitionRows) === 0)
                <flux:callout icon="information-circle">
                    <flux:callout.text>Aucun billet payé pour ce départ.</flux:callout.text>
                </flux:callout>
            @else
                <div class="overflow-x-auto">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Point de départ</flux:table.column>
                            <flux:table.column align="end">Billets</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($bookingsRepartitionRows as $bookingsRepartitionRow)
                                <flux:table.row wire:key="repartition-{{ $loop->index }}">
                                    <flux:table.cell variant="strong">{{ $bookingsRepartitionRow['name'] }}</flux:table.cell>
                                    <flux:table.cell align="end">{{ $bookingsRepartitionRow['bookingsCount'] }}</flux:table.cell>
                                </flux:table.row>
                            @endforeach

                            <flux:table.row wire:key="repartition-total">
                                <flux:table.cell variant="strong">Total</flux:table.cell>
                                <flux:table.cell align="end" variant="strong">{{ $this->bookingsRepartitionTotal() }}</flux:table.cell>
                            </flux:table.row>
                        </flux:table.rows>
                    </flux:table>
                </div>
            @endif

            <div class="flex justify-end">
                <flux:button variant="ghost" wire:click="closeBookingsRepartition">Fermer</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Gestion des rendez-vous --}}
    <flux:modal wire:model.self="showScheduleManagementModal" wire:key="schedule-management-modal" class="w-full max-w-2xl">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Gestion des rendez-vous</flux:heading>
                <flux:text class="mt-1">{{ $this->scheduleManagementDepartLabel() }}</flux:text>
            </div>

            <flux:field>
                <flux:label>Rendez-vous à gérer</flux:label>
                <flux:select wire:model.live="scheduleManagementScope">
                    <flux:select.option value="depart">Départ (tous les bus)</flux:select.option>
                    @foreach ($this->scheduleManagementBuses as $scheduleManagementBus)
                        <flux:select.option value="bus:{{ $scheduleManagementBus['id'] }}">
                            {{ $scheduleManagementBus['name'] }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            @if ($scheduleManagementFlashMessage)
                <flux:callout variant="success" icon="check-circle">
                    <flux:callout.text>{{ $scheduleManagementFlashMessage }}</flux:callout.text>
                </flux:callout>
            @endif

            @if (count($scheduleManagementRows) === 0)
                <flux:callout icon="information-circle">
                    <flux:callout.text>Aucun rendez-vous configuré pour cette sélection.</flux:callout.text>
                </flux:callout>
            @else
                <form wire:submit="saveScheduleManagementRows" class="space-y-4">
                    <div class="space-y-3">
                        @foreach ($scheduleManagementRows as $scheduleManagementRowIndex => $scheduleManagementRow)
                            <div
                                class="grid grid-cols-1 gap-3 rounded-lg border border-zinc-200 p-3 sm:grid-cols-[auto_1fr_1fr_auto] sm:items-start dark:border-zinc-700"
                                wire:key="schedule-row-{{ $scheduleManagementRow['id'] }}"
                            >
                                <flux:field variant="inline" class="sm:pt-7">
                                    <flux:switch wire:model="scheduleManagementRows.{{ $scheduleManagementRowIndex }}.isActive" />
                                    <flux:label>Actif</flux:label>
                                </flux:field>

                                <flux:field>
                                    <flux:label>Arrêt</flux:label>
                                    <flux:input readonly variant="filled" value="{{ $scheduleManagementRow['pointDepName'] }}" />
                                </flux:field>

                                <flux:field>
                                    <flux:label>Point de rendez-vous</flux:label>
                                    <flux:input wire:model="scheduleManagementRows.{{ $scheduleManagementRowIndex }}.rendezVousPoint" />
                                    <flux:error name="scheduleManagementRows.{{ $scheduleManagementRowIndex }}.rendezVousPoint" />
                                </flux:field>

                                <flux:field>
                                    <flux:label>Heure</flux:label>
                                    <flux:input type="time" wire:model="scheduleManagementRows.{{ $scheduleManagementRowIndex }}.rendezVousSchedule" />
                                    <flux:error name="scheduleManagementRows.{{ $scheduleManagementRowIndex }}.rendezVousSchedule" />
                                </flux:field>
                            </div>
                        @endforeach
                    </div>

                    <div class="flex justify-end gap-2">
                        <flux:button variant="ghost" wire:click="closeScheduleManagement">Fermer</flux:button>
                        <flux:button type="submit" variant="primary">Enregistrer les modifications</flux:button>
                    </div>
                </form>
            @endif

            @if (count($scheduleManagementRows) === 0)
                <div class="flex justify-end">
                    <flux:button variant="ghost" wire:click="closeScheduleManagement">Fermer</flux:button>
                </div>
            @endif
        </div>
    </flux:modal>

    {{-- Annuler ce départ --}}
    <flux:modal wire:model.self="showCancelDepartModal" wire:key="cancel-depart-modal" class="min-w-[22rem] max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Annuler ce départ ?</flux:heading>
                <flux:text class="mt-2">
                    Voulez-vous vraiment annuler le départ {{ $this->cancelDepartLabel() }} ? Cette action est irréversible.
                </flux:text>
            </div>

            <div class="flex items-center justify-end gap-2">
                <flux:button variant="ghost" wire:click="closeCancelDepartModal">Retour</flux:button>
                <flux:button
                    variant="danger"
                    wire:click="confirmCancelDepart"
                    wire:loading.attr="disabled"
                    wire:target="confirmCancelDepart"
                >
                    Annuler le départ
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
