{{-- Summary bar of the balance calculator: count and summed balance of the ticked cards. --}}
@props(['noun' => 'compte', 'participle' => 'sélectionné'])

<flux:card x-show="showCalculator" x-cloak x-transition class="mt-6 flex flex-wrap items-center justify-between gap-3 !py-4" x-bind:class="selectedIds.length ? 'ring-2 ring-accent' : ''">
    <div class="flex items-center gap-3">
        <flux:icon name="sigma" class="size-6 text-zinc-500" />
        <div>
            <flux:text size="sm" x-text="selectedIds.length === 0 ? 'Cochez des {{ $noun }}s pour additionner leurs soldes' : selectedIds.length + ' {{ $noun }}' + (selectedIds.length === 1 ? '' : 's') + ' {{ $participle }}' + (selectedIds.length === 1 ? '' : 's')"></flux:text>
            <flux:heading size="xl"><span x-text="formatted(total)">0</span> FCFA</flux:heading>
        </div>
    </div>
    <div class="flex items-center gap-2">
        <flux:button size="sm" variant="ghost" x-on:click="selectAll()">Tout sélectionner</flux:button>
        <flux:button size="sm" variant="ghost" x-on:click="selectedIds = []" x-bind:disabled="selectedIds.length === 0">Effacer</flux:button>
    </div>
</flux:card>
