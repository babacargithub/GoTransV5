<div>
    <h2 class="text-xl font-semibold mb-6">Connexion</h2>

    <form wire:submit="login" class="space-y-4">
        <div>
            <label for="username" class="label-uppercase block mb-1">Nom d'utilisateur</label>
            <input wire:model="username" id="username" type="text" autocomplete="username" required autofocus class="input-field">
            @error('username') <p class="text-error text-sm mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password" class="label-uppercase block mb-1">Mot de passe</label>
            <input wire:model="password" id="password" type="password" autocomplete="current-password" required class="input-field">
            @error('password') <p class="text-error text-sm mt-1">{{ $message }}</p> @enderror
        </div>

        <div class="flex items-center justify-between">
            <label class="flex items-center gap-2 text-sm">
                <input wire:model="remember" type="checkbox" class="rounded border-border">
                Se souvenir de moi
            </label>

            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" class="text-sm text-accent hover:underline">Mot de passe oublié ?</a>
            @endif
        </div>

        <flux:button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled">
            Se connecter
        </flux:button>
    </form>
</div>
