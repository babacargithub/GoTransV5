<div class="max-w-md mx-auto px-4 py-10 text-brand-navy">
    <h1 class="text-3xl font-bold">Devenir ambassadeur</h1>

    @if ($invitationWasAccepted)
        <p class="mt-6 rounded-2xl border border-brand-cyan bg-brand-cyan/10 px-4 py-6" role="status">
            Merci ! Vous êtes à présent devenu ambassadeur Globe One Transport. Votre compte est en attente ! Notre
            équipe le validera et vous  contactera sur votre numéro de téléphone au besoin.
        </p>
    @else
        <p class="mt-6 rounded-2xl border border-red-300 bg-red-50 px-4 py-6 text-red-700" role="alert">
            Ce lien d'invitation n'est plus valide.
        </p>
    @endif
</div>
