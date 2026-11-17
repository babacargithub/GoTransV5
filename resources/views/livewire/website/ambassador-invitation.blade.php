<div class="max-w-md mx-auto px-4 py-10 text-brand-navy">
    <h1 class="text-3xl font-bold">Devenir ambassadeur</h1>

    @if ($invitationWasAccepted)
        <p class="mt-6 rounded-2xl border border-brand-cyan bg-brand-cyan/10 px-4 py-6" role="status">
            Merci ! Vous êtes à présent devenu ambassadeur Globe One Transport. Votre compte est en attente ! Notre
            équipe le validera et vous  contactera sur votre numéro de téléphone au besoin.
        </p>
    @elseif ($this->invitedAmbassador === null)
        <p class="mt-6 rounded-2xl border border-red-300 bg-red-50 px-4 py-6 text-red-700" role="alert">
            Ce lien d'invitation n'est plus valide.
        </p>
    @else
        <p class="mt-2">
            Bonjour <span class="font-semibold">{{ $this->invitedAmbassador->first_name }}</span>,
            <span class="font-semibold">{{ $this->invitedAmbassador->sponsor->first_name }}</span> vous invite à
            devenir ambassadeur Globe One Transports.
            Gagnez de l'argent pour chaque nouveau client que vous amenez chez Globe One Transport.
        </p>

        @if ($formError)
            <div class="mt-6 rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
                {{ $formError }}
            </div>
        @endif

        <button type="button" wire:click="acceptInvitation" wire:loading.attr="disabled" class="mt-6 w-full rounded-xl bg-brand-navy px-4 py-3 font-semibold text-white disabled:opacity-60">
            Accepter l'invitation
        </button>
    @endif
</div>
