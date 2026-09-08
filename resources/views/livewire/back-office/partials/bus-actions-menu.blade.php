{{--
    Bus "3 dots" actions menu, shown on every bus row of the départ list.

    Mirrors the legacy Vue BusActions.vue card: a header with the bus + départ,
    then a non-compact list of actions with per-icon colours reused from the
    legacy screenshot (violet for the bus-scoped stats/itinéraire, blue for the
    répartition, indigo for the edit/transfer, red for the deletion), and a
    footer block with the filtered exports.

    Every action reuses an untouched controller method through the DepartList
    component — see App\Livewire\BackOffice\DepartList.
--}}
<flux:dropdown position="bottom" align="end" wire:key="bus-actions-{{ $bus['id'] }}">
    <flux:button
        size="xs"
        icon="ellipsis-horizontal"
        variant="primary"
        aria-label="Actions du bus"
        class="[--color-accent:var(--color-indigo-700)] [--color-accent-foreground:var(--color-white)] dark:[--color-accent:var(--color-indigo-600)]"
    />

    <flux:menu class="min-w-72 space-y-1">
        <div class="flex items-center gap-3 px-2 py-1.5">
            <flux:icon.truck variant="outline" class="size-6 shrink-0 text-zinc-400 dark:text-zinc-500" />
            <div class="min-w-0">
                <flux:heading class="truncate">{{ $bus['name'] }}</flux:heading>
                <flux:text size="sm" class="truncate">{{ $depart['name'] }}</flux:text>
            </div>
        </div>

        <flux:menu.separator />

        <flux:menu.item
            icon="banknotes"
            class="py-2 [&_[data-flux-menu-item-icon]]:!text-violet-500"
            wire:click="openBusTicketSales({{ $bus['id'] }})"
        >
            Chiffres
        </flux:menu.item>

        <flux:menu.item
            icon="map"
            class="py-2 [&_[data-flux-menu-item-icon]]:!text-violet-500"
            wire:click="openScheduleManagement({{ $depart['id'] }}, {{ $bus['id'] }})"
        >
            Itinéraire / rendez-vous
        </flux:menu.item>

        <flux:menu.item
            icon="chart-pie"
            class="py-2 [&_[data-flux-menu-item-icon]]:!text-blue-500"
            wire:click="openBookingsRepartition({{ $depart['id'] }}, {{ $bus['id'] }})"
        >
            Répartition des clients
        </flux:menu.item>

        <flux:menu.item
            icon="armchair"
            class="py-2 [&_[data-flux-menu-item-icon]]:!text-violet-500"
            disabled
        >
            Sièges du bus
        </flux:menu.item>

        <flux:menu.item
            :icon="$bus['closed'] ? 'lock-open' : 'lock-closed'"
            class="py-2 {{ $bus['closed'] ? '[&_[data-flux-menu-item-icon]]:!text-emerald-500' : '[&_[data-flux-menu-item-icon]]:!text-amber-500' }}"
            wire:click="toggleBusClosed({{ $bus['id'] }})"
        >
            {{ $bus['closed'] ? 'Réouvrir les réservations' : 'Clôturer les réservations' }}
        </flux:menu.item>

        <flux:menu.item
            icon="pencil-square"
            class="py-2 [&_[data-flux-menu-item-icon]]:!text-indigo-500"
            disabled
        >
            Modifier infos bus
        </flux:menu.item>

        <flux:menu.item
            icon="arrow-right"
            class="py-2 [&_[data-flux-menu-item-icon]]:!text-indigo-500"
            disabled
        >
            Transférer les réservations
        </flux:menu.item>

        <flux:menu.separator />

        <flux:menu.item
            icon="x-circle"
            variant="danger"
            class="py-2"
            disabled
        >
            Supprimer le bus
        </flux:menu.item>

        <flux:menu.separator />

        <flux:menu.item
            icon="arrow-down-tray"
            class="py-2 [&_[data-flux-menu-item-icon]]:!text-emerald-600"
            :href="route('back-office.buses.bookings-export', $bus['id'])"
            target="_blank"
        >
            Exporter les réservations (PDF)
        </flux:menu.item>
    </flux:menu>
</flux:dropdown>
