<x-layouts.auth title="Réinitialiser le mot de passe">
    <h2 class="text-xl font-semibold mb-6">Réinitialiser le mot de passe</h2>

    @if ($errors->any())
        <div class="mb-4 text-error text-sm space-y-1">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
        @csrf

        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div>
            <label for="email" class="label-uppercase block mb-1">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email', $request->email) }}" required autofocus class="input-field">
        </div>

        <div>
            <label for="password" class="label-uppercase block mb-1">Nouveau mot de passe</label>
            <input id="password" name="password" type="password" required class="input-field">
        </div>

        <div>
            <label for="password_confirmation" class="label-uppercase block mb-1">Confirmer le mot de passe</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required class="input-field">
        </div>

        <flux:button type="submit" variant="primary" class="w-full">
            Réinitialiser le mot de passe
        </flux:button>
    </form>
</x-layouts.auth>
