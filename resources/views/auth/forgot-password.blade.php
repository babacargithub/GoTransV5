<x-layouts.auth title="Mot de passe oublié">
    <h2 class="text-xl font-semibold mb-4">Mot de passe oublié</h2>
    <p class="text-sm text-muted-foreground mb-6">
        Indiquez votre adresse email, nous vous enverrons un lien de réinitialisation.
    </p>

    @if (session('status'))
        <p class="text-success text-sm mb-4">{{ session('status') }}</p>
    @endif

    @if ($errors->any())
        <div class="mb-4 text-error text-sm space-y-1">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <div>
            <label for="email" class="label-uppercase block mb-1">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus class="input-field">
        </div>

        <flux:button type="submit" variant="primary" class="w-full">
            Envoyer le lien de réinitialisation
        </flux:button>
    </form>

    <p class="text-sm text-center mt-6">
        <a href="{{ route('login') }}" class="text-accent hover:underline">Retour à la connexion</a>
    </p>
</x-layouts.auth>
