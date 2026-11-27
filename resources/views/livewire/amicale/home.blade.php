<div class="w-full space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ $this->amicale->name }}</flux:heading>
        <flux:text class="mt-1">Bienvenue dans l'espace de votre amicale.</flux:text>
    </div>

    <div class="rounded-xl bg-brand-navy p-5 text-center text-white sm:p-6">
        <div class="text-sm text-white/80">Solde de votre amicale</div>
        <div class="mt-2 text-4xl font-bold sm:text-5xl">{{ number_format($this->balance, 0, ',', ' ') }} <span class="text-2xl font-medium">FCFA</span></div>
        <div class="mt-5 grid grid-cols-2 divide-x divide-white/20 border-t border-white/20 pt-4 text-sm">
            <div class="px-1">
                <div class="text-xs text-white/70 sm:text-sm">Billets payés</div>
                <div class="mt-1 font-semibold">{{ number_format($this->paidBookingsCount, 0, ',', ' ') }}</div>
            </div>
            <div class="px-1">
                <div class="text-xs text-white/70 sm:text-sm">Commission par billet</div>
                <div class="mt-1 font-semibold">{{ number_format($this->amicale->commission_per_ticket, 0, ',', ' ') }} FCFA</div>
            </div>
        </div>
        <div class="mt-3 text-xs text-white/70">Seuls les billets payés sont comptés.</div>
    </div>

    <div
        class="rounded-xl border border-brand-sky/30 p-4 text-center"
        x-data="{
            websiteUrl: @js($this->websiteUrl),
            shareMessage: @js('Réserve ton billet de bus avec '.$this->amicale->name.' en partenariat avec Globe One Transports : '.$this->websiteUrl),
            linkWasCopied: false,
            canUseNativeShare: typeof navigator.share === 'function',
            async share() {
                try {
                    await navigator.share({ title: @js($this->amicale->name), text: this.shareMessage, url: this.websiteUrl });
                } catch (shareError) {
                    // The member closed the share sheet: nothing to do.
                }
            },
            async copyLink() {
                try {
                    await navigator.clipboard.writeText(this.websiteUrl);
                    this.linkWasCopied = true;
                    setTimeout(() => this.linkWasCopied = false, 2500);
                } catch (copyError) {
                    window.prompt('Copiez le lien :', this.websiteUrl);
                }
            },
            get whatsappShareUrl() {
                return 'https://wa.me/?text=' + encodeURIComponent(this.shareMessage);
            },
        }"
    >
        <flux:text>Le site de votre amicale</flux:text>
        <a href="{{ $this->websiteUrl }}" class="mt-1 block break-all font-semibold underline" target="_blank" rel="noopener">{{ $this->websiteUrl }}</a>

        <div class="mt-4 grid grid-cols-2 gap-2">
            <flux:button x-show="canUseNativeShare" x-on:click="share()" variant="primary" icon="share" class="col-span-2 !bg-brand-cyan !text-white hover:!bg-brand-cyan/90">
                Partager
            </flux:button>
            <flux:button x-show="! canUseNativeShare" x-bind:href="whatsappShareUrl" target="_blank" rel="noopener" variant="primary" icon="share" class="!bg-brand-cyan !text-white hover:!bg-brand-cyan/90">
                Partager sur WhatsApp
            </flux:button>
            <flux:button x-on:click="copyLink()" icon="clipboard-document" x-bind:class="canUseNativeShare ? 'col-span-2' : ''">
                <span x-show="! linkWasCopied">Copier le lien</span>
                <span x-show="linkWasCopied" x-cloak>Lien copié ✓</span>
            </flux:button>
        </div>
    </div>

    <div class="space-y-3">
        <flux:heading size="lg" level="2">Réservations de vos membres</flux:heading>

        @forelse ($this->bookings as $booking)
            <div wire:key="amicale-booking-{{ $booking->id }}" class="rounded-xl border border-brand-sky/30 p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="font-semibold">{{ $booking->customer->full_name }}</div>
                    @if ($booking->paye)
                        <flux:badge color="green">Payé · {{ number_format($this->amicale->commission_per_ticket, 0, ',', ' ') }} FCFA</flux:badge>
                    @else
                        <flux:badge color="zinc">Non payé</flux:badge>
                    @endif
                </div>
                <div class="mt-1 text-sm text-zinc-600">{{ $booking->depart->identifier(with_trajet_prefix: true) }}</div>
                <div class="mt-1 text-xs text-zinc-500">Réservé le {{ $booking->created_at?->format('d/m/Y H:i') ?? '—' }}</div>
            </div>
        @empty
            <flux:text>Aucune réservation pour le moment.</flux:text>
        @endforelse

        {{ $this->bookings->links() }}
    </div>
</div>
