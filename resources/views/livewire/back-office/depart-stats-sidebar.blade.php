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
                <div class="space-y-2 px-1" wire:key="sidebar-depart-{{ $loop->index }}">
                    <flux:heading size="sm" class="leading-snug">{{ $departStatsRow['depart'] }}</flux:heading>

                    <div class="space-y-2 ps-3">
                    @forelse ($departStatsRow['buses'] as $sidebarBus)
                        <div
                            class="flex items-center justify-between gap-2"
                            wire:key="sidebar-bus-{{ $loop->parent->index }}-{{ $loop->index }}"
                        >
                            <div class="flex min-w-0 items-center gap-1.5">
                                <a
                                    href="{{ route('back-office.buses.bookings', $sidebarBus['id']) }}"
                                    wire:navigate
                                    class="truncate font-medium text-zinc-800 underline-offset-2 hover:text-accent hover:underline dark:text-zinc-200"
                                >
                                    {{ $sidebarBus['name'] }}
                                </a>

                                @if ($sidebarBus['closed'])
                                    <flux:tooltip content="Fermé">
                                        <flux:icon.lock-closed variant="micro" class="shrink-0 text-zinc-400 dark:text-zinc-500" />
                                    </flux:tooltip>
                                @elseif (! $sidebarBus['hasSeatsLeft'])
                                    <flux:badge size="sm" color="red" rounded class="shrink-0 px-2 py-0 text-[0.6rem] leading-[1.4]">Complet</flux:badge>
                                @endif
                            </div>

                            <div class="flex shrink-0 items-center gap-1">
                                <flux:tooltip content="Réservations">
                                    <flux:badge size="sm" color="blue" variant="solid" rounded class="px-2 py-0 text-[0.65rem] leading-[1.4]">
                                        {{ $sidebarBus['bookingsCount'] }}
                                    </flux:badge>
                                </flux:tooltip>

                                <flux:tooltip content="Sièges réservés">
                                    <flux:badge size="sm" color="amber" variant="solid" rounded class="px-2 py-0 text-[0.65rem] leading-[1.4]">
                                        {{ $sidebarBus['bookedSeatsCount'] }}
                                    </flux:badge>
                                </flux:tooltip>

                                <flux:tooltip content="Billets vendus">
                                    <flux:badge size="sm" color="green" variant="solid" rounded class="px-2 py-0 text-[0.65rem] leading-[1.4]">
                                        {{ $sidebarBus['ticketsSoldCount'] }}
                                    </flux:badge>
                                </flux:tooltip>
                            </div>
                        </div>

                        @unless ($loop->last)
                            <flux:separator variant="subtle" class="my-1" />
                        @endunless
                    @empty
                        <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">Aucun bus.</flux:text>
                    @endforelse
                    </div>
                </div>

                @unless ($loop->last)
                    <flux:separator />
                @endunless
            @endforeach
        </div>
    @endif
</div>
