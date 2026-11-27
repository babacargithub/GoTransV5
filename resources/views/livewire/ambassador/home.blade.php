<div class="w-full space-y-6">
    <div>
        <flux:heading size="xl" level="1">Bonjour {{ $this->ambassador->first_name }} 👋</flux:heading>
        <flux:text class="mt-1">Bienvenue dans votre espace ambassadeur.</flux:text>
    </div>

    <div class="rounded-xl bg-brand-navy p-5 text-center sm:p-6 text-white">
        <div class="text-sm text-white/80">Solde total de vos gains</div>
        <div class="mt-2 text-4xl font-bold sm:text-5xl">{{ number_format($this->accountBalance->totalBalance, 0, ',', ' ') }} <span class="text-2xl font-medium">FCFA</span></div>
        <div class="mt-5 grid grid-cols-3 divide-x divide-white/20 border-t border-white/20 pt-4 text-sm">
            <div class="px-1">
                <div class="text-xs text-white/70 sm:text-sm">Disponible</div>
                <div class="mt-1 font-semibold">{{ number_format($this->accountBalance->availableBalance, 0, ',', ' ') }}</div>
                <div class="text-xs text-white/70">FCFA</div>
            </div>
            <div class="px-1">
                <div class="text-xs text-white/70 sm:text-sm">En attente</div>
                <div class="mt-1 font-semibold">{{ number_format($this->accountBalance->pendingBalance, 0, ',', ' ') }}</div>
                <div class="text-xs text-white/70">FCFA</div>
            </div>
            <div class="px-1">
                <div class="text-xs text-white/70 sm:text-sm">Déjà reçu</div>
                <div class="mt-1 font-semibold">{{ number_format($this->accountBalance->paidBalance, 0, ',', ' ') }}</div>
                <div class="text-xs text-white/70">FCFA</div>
            </div>
        </div>
        <div class="mt-3 text-xs text-white/70">Un gain devient disponible 24h après le départ du client.</div>
    </div>

    <div class="-mt-3">
        <flux:modal.trigger name="payout-reminder">
            <flux:button variant="primary" icon="banknotes" class="w-full !h-14 !bg-brand-cyan !text-base !font-semibold !text-white hover:!bg-brand-cyan/90">
                Retirer mes gains
            </flux:button>
        </flux:modal.trigger>
    </div>

    <flux:modal name="payout-reminder" class="w-full max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg" level="2">Paiement de vos gains</flux:heading>
            <flux:text>Vos gains sont payés  le 15 et le dernier jour de chaque mois. Le retrait par vous même pourra être activé quand vous aurez atteint une certaine base clientèle !</flux:text>
            <div class="flex justify-end">
                <flux:modal.close>
                    <flux:button variant="primary">J'ai compris</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>

    <div class="rounded-xl border border-brand-sky/30 p-6 text-center">
        <flux:text>Votre code ambassadeur</flux:text>
        <div class="mt-2 break-all font-mono text-4xl font-bold tracking-widest sm:text-5xl">{{ $this->ambassador->code }}</div>
        <flux:text class="mt-3 text-sm">Partagez ce code : vous êtes rémunéré pour chaque nouveau client que vous amenez.</flux:text>

        <div
            x-data="{
                linkWasCopied: false,
                linkCopyFailed: false,
                shareUrl: @js($this->shareUrl),
                linkPlaceholder: @js(\App\Livewire\Ambassador\Home::SHARE_LINK_PLACEHOLDER),
                shareMessage: '',
                init() {
                    // The last message used is remembered in this browser.
                    try {
                        this.shareMessage = localStorage.getItem('ambassadorShareMessage') || @js(\App\Livewire\Ambassador\Home::DEFAULT_SHARE_MESSAGE);
                    } catch (storageError) {
                        this.shareMessage = @js(\App\Livewire\Ambassador\Home::DEFAULT_SHARE_MESSAGE);
                    }
                },
                get hasLinkPlaceholder() {
                    return this.shareMessage.includes(this.linkPlaceholder);
                },
                get sharePreview() {
                    return this.shareMessage.replaceAll(this.linkPlaceholder, this.shareUrl);
                },
                get whatsappShareUrl() {
                    return 'https://wa.me/?text=' + encodeURIComponent(this.sharePreview);
                },
                rememberShareMessage(clickEvent) {
                    if (! this.hasLinkPlaceholder) {
                        clickEvent.preventDefault();

                        return;
                    }

                    try {
                        localStorage.setItem('ambassadorShareMessage', this.shareMessage);
                    } catch (storageError) {
                        // Remembering the message is a convenience: sharing still works without it.
                    }
                },
                async copyLink() {
                    const shareUrl = this.shareUrl;
                    this.linkCopyFailed = false;

                    try {
                        if (navigator.clipboard && window.isSecureContext) {
                            await navigator.clipboard.writeText(shareUrl);
                        } else {
                            // Plain-HTTP pages have no clipboard API: fall back to selecting a hidden field.
                            const temporaryField = document.createElement('textarea');
                            temporaryField.value = shareUrl;
                            temporaryField.style.position = 'fixed';
                            temporaryField.style.opacity = '0';
                            document.body.appendChild(temporaryField);
                            temporaryField.focus();
                            temporaryField.select();
                            const wasCopied = document.execCommand('copy');
                            document.body.removeChild(temporaryField);
                            if (! wasCopied) {
                                throw new Error('copy command refused');
                            }
                        }
                    } catch (copyError) {
                        this.linkCopyFailed = true;
                        this.$nextTick(() => this.$refs.shareUrlField.select());

                        return;
                    }

                    this.linkWasCopied = true;
                    setTimeout(() => this.linkWasCopied = false, 2500);
                },
            }"
            class="mt-4 space-y-3"
        >
            <div class="flex flex-wrap justify-center gap-2">
                <flux:modal.trigger :name="$this->hasSignedContract ? 'share-message' : 'contract-required'">
                    <flux:button type="button" variant="primary" icon="share">
                        Partager le lien
                    </flux:button>
                </flux:modal.trigger>
                <flux:button type="button" icon="clipboard-document" x-on:click="{{ $this->hasSignedContract ? 'copyLink()' : '$flux.modal(\'contract-required\').show()' }}">
                    <span x-show="! linkWasCopied">Copier le lien</span>
                    <span x-show="linkWasCopied" x-cloak>Lien copié ✓</span>
                </flux:button>
            </div>

            <div x-show="linkCopyFailed" x-cloak class="space-y-1">
                <flux:text class="text-sm">Copie automatique impossible : copiez le lien ci-dessous.</flux:text>
                <input
                    type="text"
                    readonly
                    x-ref="shareUrlField"
                    value="{{ $this->shareUrl }}"
                    class="w-full rounded-lg border border-brand-sky/30 bg-white px-3 py-2 text-center text-sm"
                    x-on:focus="$el.select()"
                >
            </div>

            <flux:modal name="share-message" class="w-full max-w-md text-left">
                <div class="space-y-4">
                    <div>
                        <flux:heading size="lg" level="2">Votre message de partage</flux:heading>
                        <flux:text class="mt-1 text-sm">Modifiez le message à envoyer. <span class="font-mono font-semibold">@{{lien}}</span> sera remplacé par votre vrai lien.</flux:text>
                    </div>

                    <flux:textarea x-model="shareMessage" rows="4" maxlength="500" />
                    <p x-show="! hasLinkPlaceholder" x-cloak class="text-sm text-red-600">Le message doit contenir <span class="font-mono font-semibold">@{{lien}}</span>, remplacé par votre lien.</p>

                    <div>
                        <div class="mb-1 text-sm text-zinc-600">Aperçu</div>
                        <div class="whitespace-pre-line break-words rounded-xl rounded-tl-none bg-brand-sky/10 p-3 text-sm text-brand-navy" x-text="sharePreview"></div>
                    </div>

                    <flux:button variant="primary" icon="paper-airplane" class="w-full" href="#" target="_blank" rel="noopener" x-bind:href="whatsappShareUrl" x-bind:class="hasLinkPlaceholder ? '' : 'pointer-events-none opacity-50'" x-on:click="rememberShareMessage($event)">
                        Envoyer sur WhatsApp
                    </flux:button>
                </div>
            </flux:modal>
        </div>
    </div>

    @if ($this->ambassador->status === \App\Enums\AmbassadorStatus::Active && $this->ambassador->hasAbility(\App\Enums\AmbassadorAbilityName::Sponsor))
        <div class="rounded-xl border border-brand-sky/30 p-6 text-center">
            <flux:heading size="lg" level="2">Parrainer un ambassadeur</flux:heading>
            <flux:text class="mt-1 text-sm">Invitez un proche à devenir ambassadeur. Vous recevez une prime quand il atteint son objectif de clients amenés.</flux:text>
            <flux:button class="mt-4" icon="user-plus" :href="route('ambassador.sponsored-ambassadors')" wire:navigate>
                Inviter un ambassadeur
            </flux:button>
        </div>
    @endif

    @unless ($this->hasSignedContract)
        <flux:callout variant="warning" icon="exclamation-triangle" heading="Contrat à signer">
            <flux:callout.text>Signez votre contrat pour que votre code attribue de nouveaux clients et que vos gains soient payés.</flux:callout.text>
            <x-slot name="actions">
                <flux:button :href="route('ambassador.contract')" wire:navigate>Lire et signer</flux:button>
            </x-slot>
        </flux:callout>

        <flux:modal name="contract-required" class="w-full max-w-md">
            <div class="space-y-4">
                <flux:heading size="lg" level="2">Contrat à signer</flux:heading>
                <flux:text>Vous devez d'abord lire et signer votre contrat avant de partager votre lien : sans signature, votre code n'attribue aucun client et vos gains ne peuvent pas être payés.</flux:text>
                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">Plus tard</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" :href="route('ambassador.contract')" wire:navigate>Lire et signer mon contrat</flux:button>
                </div>
            </div>
        </flux:modal>
    @endunless

    <div class="grid grid-cols-2 gap-3 sm:gap-4">
        <div class="rounded-xl border border-brand-sky/30 p-4">
            <flux:text class="text-sm">Statut de votre compte</flux:text>
            <div class="mt-1">
                <flux:badge :color="$this->ambassador->status->badgeColor()">{{ $this->ambassador->status->label() }}</flux:badge>
            </div>
        </div>

        <div class="rounded-xl border border-brand-sky/30 p-4">
            <flux:text class="text-sm">Fin de contrat</flux:text>
            <div class="mt-1 text-sm font-medium sm:text-base">
                @if ($this->ambassador->contract_ends_at)
                    {{ $this->ambassador->contract_ends_at->locale('fr')->isoFormat('D MMM YYYY') }}
                @else
                    —
                @endif
                <flux:badge size="sm" :color="$this->hasSignedContract ? 'green' : 'amber'">{{ $this->hasSignedContract ? 'Signé' : 'Non signé' }}</flux:badge>
            </div>
        </div>

        <div class="col-span-2 rounded-xl border border-brand-sky/30 p-4">
            <flux:text class="text-sm">Clients amenés</flux:text>
            <div class="mt-1 text-2xl font-semibold">{{ $this->acquiredCustomersCount }}</div>
        </div>
    </div>

    <div class="space-y-3">
        <flux:heading size="lg" level="2">Mes réservations</flux:heading>

        @forelse ($this->referredBookings as $booking)
            @php($commission = $booking->ambassadorBookingEarning)
            <div wire:key="referred-booking-{{ $booking->id }}" class="rounded-xl border border-brand-sky/30 p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="font-semibold">{{ $booking->customer->full_name }}</div>
                    @if ($commission)
                        <flux:badge :color="$commission->status->badgeColor()">{{ $commission->status->label() }}</flux:badge>
                    @else
                        <flux:badge color="zinc">Aucune commission</flux:badge>
                    @endif
                </div>
                <div class="mt-1 text-sm text-zinc-600">{{ $booking->depart->identifier(with_trajet_prefix: true) }}</div>
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
