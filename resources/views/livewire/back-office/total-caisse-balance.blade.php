<div wire:poll.10s class="flex flex-col items-end gap-px rounded-md bg-zinc-200/70 px-2 py-1 leading-none dark:bg-zinc-700/60">
    <span class="flex items-center gap-1.5 text-sm font-medium text-zinc-700 dark:text-zinc-200">
        <flux:icon.banknotes class="size-4" />
        Caisse : {{ number_format($this->totalBalance, 0, ',', ' ') }} FCFA
    </span>
    <span class="text-[11px] font-medium text-red-600 dark:text-red-400" title="Ventes de billets des départs à venir">
        − {{ number_format($this->upcomingTicketSales, 0, ',', ' ') }} FCFA
    </span>
    <span class="text-[11px] font-medium text-green-600 dark:text-green-400" title="Encaissé : solde global moins les ventes à venir">
        + {{ number_format($this->cashedBalance, 0, ',', ' ') }} FCFA
    </span>
</div>
