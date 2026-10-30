<div class="mx-auto w-full max-w-2xl space-y-6">
    <div>
        <flux:heading size="xl" level="1">Bonjour {{ $this->ambassador->first_name }} 👋</flux:heading>
        <flux:text class="mt-1">Bienvenue dans votre espace ambassadeur.</flux:text>
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
    </div>
</div>
