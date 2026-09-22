<x-layouts.auth title="Inscription">
    <h2 class="text-xl font-semibold mb-6">Créer un compte</h2>

    @if ($errors->any())
        <div class="mb-4 text-error text-sm space-y-1">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('register.store') }}" class="space-y-4">
        @csrf

        <div>
            <label for="name" class="label-uppercase block mb-1">Nom</label>
            <input id="name" name="name" type="text" value="{{ old('name') }}" required autofocus class="input-field">
        </div>

        <div>
            <label for="email" class="label-uppercase block mb-1">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required class="input-field">
        </div>

        <div>
            <label for="password" class="label-uppercase block mb-1">Mot de passe</label>
            <input id="password" name="password" type="password" required class="input-field">
        </div>

        <div>
            <label for="password_confirmation" class="label-uppercase block mb-1">Confirmer le mot de passe</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required class="input-field">
        </div>

        <flux:button type="submit" variant="primary" class="w-full">
            S'inscrire
        </flux:button>
    </form>

    <p class="text-sm text-center mt-6">
        Déjà un compte ? <a href="{{ route('login') }}" class="text-accent hover:underline">Se connecter</a>
    </p>
</x-layouts.auth>
