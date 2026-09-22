<div class="max-w-2xl mx-auto space-y-8">
    <h1 class="text-2xl font-bold">Mon profil</h1>

    @if (session('status'))
        <p class="text-success text-sm">{{ __(session('status')) }}</p>
    @endif

    <div class="card p-6">
        <h2 class="text-lg font-semibold mb-4">Informations du profil</h2>
        <form wire:submit="updateProfileInformation" class="space-y-4">
            <div>
                <label for="name" class="label-uppercase block mb-1">Nom</label>
                <input wire:model="name" id="name" type="text" required class="input-field">
                @error('name') <p class="text-error text-sm mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="email" class="label-uppercase block mb-1">Email</label>
                <input wire:model="email" id="email" type="email" required class="input-field">
                @error('email') <p class="text-error text-sm mt-1">{{ $message }}</p> @enderror
            </div>
            <flux:button type="submit" variant="primary">Enregistrer</flux:button>
        </form>
    </div>

    <div class="card p-6">
        <h2 class="text-lg font-semibold mb-4">Changer le mot de passe</h2>
        <form wire:submit="updatePassword" class="space-y-4">
            <div>
                <label for="current_password" class="label-uppercase block mb-1">Mot de passe actuel</label>
                <input wire:model="current_password" id="current_password" type="password" class="input-field">
                @error('current_password') <p class="text-error text-sm mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="password" class="label-uppercase block mb-1">Nouveau mot de passe</label>
                <input wire:model="password" id="password" type="password" class="input-field">
                @error('password') <p class="text-error text-sm mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="password_confirmation" class="label-uppercase block mb-1">Confirmer le mot de passe</label>
                <input wire:model="password_confirmation" id="password_confirmation" type="password" class="input-field">
            </div>
            <flux:button type="submit" variant="primary">Mettre à jour</flux:button>
        </form>
    </div>

    <div class="card p-6">
        <h2 class="text-lg font-semibold mb-4">Authentification à deux facteurs</h2>

        @if ($twoFactorConfirmed)
            <p class="text-success text-sm mb-4">Activée.</p>

            @if (count($recoveryCodes))
                <div class="bg-secondary rounded-lg p-4 mb-4 font-mono text-sm space-y-1">
                    @foreach ($recoveryCodes as $code)
                        <div>{{ $code }}</div>
                    @endforeach
                </div>
            @endif

            <flux:button wire:click="disableTwoFactorAuthentication" variant="danger">Désactiver</flux:button>
        @elseif ($confirmingTwoFactor)
            <div class="mb-4">
                {!! Auth::user()->twoFactorQrCodeSvg() !!}
            </div>
            <form wire:submit="confirmTwoFactorAuthentication" class="space-y-4">
                <div>
                    <label for="twoFactorCode" class="label-uppercase block mb-1">Code de l'application d'authentification</label>
                    <input wire:model="twoFactorCode" id="twoFactorCode" type="text" inputmode="numeric" class="input-field">
                    @error('code') <p class="text-error text-sm mt-1">{{ $message }}</p> @enderror
                </div>
                <flux:button type="submit" variant="primary">Confirmer</flux:button>
            </form>
        @else
            <p class="text-sm text-muted-foreground mb-4">Non activée.</p>
            <flux:button wire:click="enableTwoFactorAuthentication" variant="primary">Activer</flux:button>
        @endif
    </div>

    <div class="card p-6 border-error">
        <h2 class="text-lg font-semibold mb-4">Supprimer le compte</h2>
        <p class="text-sm text-muted-foreground mb-4">Cette action est irréversible.</p>
        <flux:button wire:click="deleteAccount" wire:confirm="Êtes-vous sûr de vouloir supprimer votre compte ?" variant="danger">
            Supprimer mon compte
        </flux:button>
    </div>
</div>
