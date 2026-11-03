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

        <div
            x-data="{
                linkWasCopied: false,
                async copyLink() {
                    await navigator.clipboard.writeText(@js($this->shareUrl));
                    this.linkWasCopied = true;
                    setTimeout(() => this.linkWasCopied = false, 2500);
                },
            }"
            class="mt-4 flex flex-wrap justify-center gap-2"
        >
            <flux:button variant="primary" icon="share" href="{{ $this->whatsappShareUrl }}" target="_blank" rel="noopener">
                Partager le lien
            </flux:button>
            <flux:button icon="clipboard-document" x-on:click="copyLink()">
                <span x-show="! linkWasCopied">Copier le lien</span>
                <span x-show="linkWasCopied" x-cloak>Lien copié ✓</span>
            </flux:button>
        </div>
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

    <div class="space-y-3">
        <flux:heading size="lg" level="2">Mes réservations</flux:heading>

        @forelse ($this->referredBookings as $booking)
            @php($commission = $booking->ambassadorAcquisitionEarning)
            <div wire:key="referred-booking-{{ $booking->id }}" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex items-start justify-between gap-3">
                    <div class="font-semibold">{{ $booking->customer->full_name }}</div>
                    @if ($commission)
                        <flux:badge :color="$commission->status->badgeColor()">{{ $commission->status->label() }}</flux:badge>
                    @else
                        <flux:badge color="zinc">Aucune commission</flux:badge>
                    @endif
                </div>
                <div class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ $booking->depart->identifier(with_trajet_prefix: true) }}</div>
                <div class="mt-3 grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                    <div>
                        <div class="text-zinc-500">Bus</div>
                        <div class="font-medium">{{ $booking->bus?->name ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-500">Siège</div>
                        <div class="font-medium">{{ $booking->seat_number ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-500">Heure de départ</div>
                        <div class="font-medium">{{ $booking->formatted_schedule ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-500">Commission</div>
                        <div class="font-medium">{{ $commission ? number_format($commission->amount, 0, ',', ' ').' FCFA' : '—' }}</div>
                    </div>
                </div>
            </div>
        @empty
            <flux:text>Aucune réservation pour le moment.</flux:text>
        @endforelse

        {{ $this->referredBookings->links() }}
    </div>
</div>
