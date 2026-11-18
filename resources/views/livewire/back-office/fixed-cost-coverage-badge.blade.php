@php
    $formatAmount = fn (int $amount): string => number_format($amount, 0, ',', ' ').' FCFA';
@endphp

<a
    href="{{ route('back-office.revenus.index') }}"
    wire:navigate
    wire:poll.60s
    data-test="fixed-cost-coverage-badge"
    class="hidden flex-col items-end gap-px rounded-md px-2 py-1 leading-none md:flex
        {{ ! $this->coverage->isPlanned ? 'bg-zinc-200/70 text-zinc-600 dark:bg-zinc-700/60 dark:text-zinc-300' : ($this->coverage->isCovered ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300') }}"
    title="Charges fixes de {{ $this->coverage->month->locale('fr')->translatedFormat('F Y') }}"
>
    <span class="flex items-center gap-1.5 text-sm font-medium">
        <flux:icon.scale class="size-4" />
        @if (! $this->coverage->isPlanned)
            Charges fixes : non planifié
        @elseif ($this->coverage->isCovered)
            Charges fixes couvertes
        @else
            Charges fixes : {{ $this->coverage->coveragePercent }} %
        @endif
    </span>
    @if ($this->coverage->isPlanned && ! $this->coverage->isCovered)
        <span class="text-[11px] font-medium">Reste {{ $formatAmount($this->coverage->remainingToCover) }}</span>
    @endif
</a>
