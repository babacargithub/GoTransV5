<div wire:poll.10s>
    <flux:badge color="zinc" icon="banknotes">
        Caisse : {{ number_format($this->totalBalance, 0, ',', ' ') }} FCFA
    </flux:badge>
</div>
