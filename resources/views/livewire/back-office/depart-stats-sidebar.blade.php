<div class="flex h-full flex-col gap-4" wire:poll.60s="refreshDepartStats">
    <div class="flex items-start justify-between gap-2 px-1">
        <div>
            <flux:heading size="sm">Statistiques des départs</flux:heading>
            <flux:text class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                {{ $this->totalBookingsCount() }} réservation(s) au total
            </flux:text>
        </div>

        <flux:tooltip content="Actualiser">
            <flux:button
                size="sm"
                variant="subtle"
                icon="arrow-path"
                square
                aria-label="Actualiser les statistiques"
                wire:click="refreshDepartStats"
                wire:loading.attr="disabled"
                wire:target="refreshDepartStats"
            />
        </flux:tooltip>
    </div>

    @if (count($this->departStatsRows) === 0)
        <flux:callout icon="information-circle" class="text-sm">
            <flux:callout.text>Aucun départ à venir.</flux:callout.text>
        </flux:callout>
    @else
        <div class="space-y-4">
            @foreach ($this->departStatsRows as $departStatsRow)
                <div class="space-y-2.5" wire:key="sidebar-depart-{{ $loop->index }}">
                    <flux:heading size="sm" class="px-1 leading-snug">{{ $departStatsRow['depart'] }}</flux:heading>

                    @forelse ($departStatsRow['buses'] as $sidebarBus)
                        <div
                            class="space-y-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700"
                            wire:key="sidebar-bus-{{ $loop->parent->index }}-{{ $loop->index }}"
                        >
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex items-center gap-1.5">
                                    <flux:text class="font-medium">{{ $sidebarBus['name'] }}</flux:text>

                                    @if ($sidebarBus['closed'])
                                        <flux:tooltip content="Fermé">
                                            <flux:icon.lock-closed variant="micro" class="text-zinc-400 dark:text-zinc-500" />
                                        </flux:tooltip>
                                    @endif
                                </div>

                                @if (! $sidebarBus['closed'] && ! $sidebarBus['hasSeatsLeft'])
                                    <flux:badge size="sm" color="red">Complet</flux:badge>
                                @endif
                            </div>

                            <div class="flex flex-wrap gap-1.5">
                                <flux:tooltip content="Réservations">
                                    <flux:badge size="sm" color="blue" icon="user-group">
                                        {{ $sidebarBus['bookingsCount'] }}
                                    </flux:badge>
                                </flux:tooltip>

                                <flux:tooltip content="Sièges réservés">
                                    <flux:badge size="sm" color="amber" icon="check-circle">
                                        {{ $sidebarBus['bookedSeatsCount'] }}
                                    </flux:badge>
                                </flux:tooltip>

                                <flux:tooltip content="Billets vendus">
                                    <flux:badge size="sm" color="green" icon="ticket">
                                        {{ $sidebarBus['ticketsSoldCount'] }}
                                    </flux:badge>
                                </flux:tooltip>
                            </div>
                        </div>
                    @empty
                        <flux:text class="px-1 text-xs text-zinc-500 dark:text-zinc-400">Aucun bus.</flux:text>
                    @endforelse
                </div>
            @endforeach
        </div>
    @endif
</div>
