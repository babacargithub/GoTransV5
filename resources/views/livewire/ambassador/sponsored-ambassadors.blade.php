<div class="w-full space-y-6">
    <div>
        <flux:heading size="xl" level="1">Mon équipe</flux:heading>
        <flux:text class="mt-1">
            @if ($this->sponsorshipBonusAmount !== null)
                Vous recevez {{ number_format($this->sponsorshipBonusAmount, 0, ',', ' ') }} FCFA quand un ambassadeur que vous parrainez
                atteint {{ $this->sponsorshipThreshold }} clients amenés.
            @else
                Parrainez des ambassadeurs et suivez leurs clients amenés.
            @endif
        </flux:text>
    </div>

    @if ($this->ambassador->status === \App\Enums\AmbassadorStatus::Active && $this->canSponsor)
        @if ($invitedFirstName)
            <flux:callout variant="success" icon="check-circle" heading="Invitation envoyée à {{ $invitedFirstName }}." />
        @endif

        @if ($showInvitationForm)
            <form wire:submit="inviteAmbassador" class="space-y-4 rounded-xl border border-brand-sky/30 p-4">
                <flux:heading size="lg" level="2">Inviter un ambassadeur</flux:heading>
                <flux:text class="text-sm">La personne reçoit un SMS avec un lien pour accepter. Notre équipe valide ensuite son compte.</flux:text>

                @if ($formError)
                    <flux:callout variant="danger" icon="exclamation-triangle" :heading="$formError" />
                @endif

                <flux:input wire:model="firstName" label="Prénom" autocomplete="off" />
                <flux:input wire:model="lastName" label="Nom" autocomplete="off" />
                <flux:input wire:model="phoneNumber" type="tel" label="Numéro de téléphone" placeholder="77 123 45 67" autocomplete="off" />
                <flux:input wire:model="waveNumber" type="tel" label="Numéro Wave (si différent)" autocomplete="off" />

                <flux:button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled">Envoyer l'invitation</flux:button>
            </form>
        @else
            <flux:button wire:click="$set('showInvitationForm', true)" variant="primary" icon="user-plus" class="w-full">Inviter</flux:button>
        @endif
    @endif

    <div class="space-y-3">
        @forelse ($this->sponsoredAmbassadors as $sponsoredAmbassador)
            @php($progressPercentage = min(100, (int) floor($sponsoredAmbassador->acquired_customers_count / max(1, $this->sponsorshipThreshold) * 100)))
            <div wire:key="sponsored-ambassador-{{ $sponsoredAmbassador->id }}" class="rounded-xl border border-brand-sky/30 p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="font-semibold">{{ $sponsoredAmbassador->full_name }}</div>
                    <flux:badge :color="$sponsoredAmbassador->status->badgeColor()">{{ $sponsoredAmbassador->status->label() }}</flux:badge>
                </div>
                <div class="mt-3 flex items-center justify-between text-sm">
                    <span>{{ $sponsoredAmbassador->acquired_customers_count }} / {{ $this->sponsorshipThreshold }} clients amenés</span>
                    @if ($sponsoredAmbassador->sponsorship_bonus_earned)
                        <flux:badge color="green">Prime obtenue</flux:badge>
                    @endif
                </div>
                <div class="mt-2 h-2 overflow-hidden rounded-full bg-zinc-200">
                    <div class="h-full rounded-full bg-green-500" style="width: {{ $progressPercentage }}%"></div>
                </div>
            </div>
        @empty
            <flux:text>Votre équipe est vide pour l'instant : parrainez un ambassadeur pour la constituer.</flux:text>
        @endforelse

        {{ $this->sponsoredAmbassadors->links() }}
    </div>
</div>
