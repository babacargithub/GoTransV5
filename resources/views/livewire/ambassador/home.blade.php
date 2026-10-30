<div class="mx-auto w-full max-w-2xl space-y-6">
    <div>
        <flux:heading size="xl" level="1">Bonjour {{ $this->ambassador->first_name }} 👋</flux:heading>
        <flux:text class="mt-1">Bienvenue dans votre espace ambassadeur.</flux:text>
    </div>

    <div class="rounded-xl bg-zinc-900 p-6 text-center text-white dark:bg-zinc-800">
        <div class="text-sm text-zinc-300">Solde total</div>
        <div class="mt-2 text-5xl font-bold">{{ number_format($this->accountBalance->totalBalance, 0, ',', ' ') }} <span class="text-2xl font-medium">FCFA</span></div>
        <div class="mt-4 grid grid-cols-3 gap-2 text-sm">
            <div>
                <div class="text-zinc-400">Disponible</div>
                <div class="font-semibold">{{ number_format($this->accountBalance->availableBalance, 0, ',', ' ') }} FCFA</div>
            </div>
            <div>
                <div class="text-zinc-400">En attente</div>
                <div class="font-semibold">{{ number_format($this->accountBalance->pendingBalance, 0, ',', ' ') }} FCFA</div>
            </div>
            <div>
                <div class="text-zinc-400">Déjà reçu</div>
                <div class="font-semibold">{{ number_format($this->accountBalance->paidBalance, 0, ',', ' ') }} FCFA</div>
            </div>
        </div>
        <div class="mt-3 text-xs text-zinc-400">Un gain devient disponible 24h après le départ du client.</div>
    </div>

    <div class="rounded-xl border border-zinc-200 p-6 text-center dark:border-zinc-700">
        <flux:text>Votre code ambassadeur</flux:text>
        <div class="mt-2 font-mono text-5xl font-bold tracking-widest">{{ $this->ambassador->code }}</div>
        <flux:text class="mt-3 text-sm">Partagez ce code : vous êtes rémunéré pour chaque nouveau client que vous amenez.</flux:text>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:text class="text-sm">Statut</flux:text>
            <div class="mt-1">
                <flux:badge :color="$this->ambassador->status->badgeColor()">{{ $this->ambassador->status->label() }}</flux:badge>
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:text class="text-sm">Contrat</flux:text>
            <div class="mt-1 font-medium">
                @if ($this->ambassador->contract_ends_at)
                    {{ $this->ambassador->contract_starts_at?->format('d/m/Y') ?? '…' }} → {{ $this->ambassador->contract_ends_at->format('d/m/Y') }}
                @else
                    —
                @endif
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700 sm:col-span-2">
            <flux:text class="text-sm">Clients amenés</flux:text>
            <div class="mt-1 text-2xl font-semibold">{{ $this->acquiredCustomersCount }}</div>
        </div>
    </div>
</div>
