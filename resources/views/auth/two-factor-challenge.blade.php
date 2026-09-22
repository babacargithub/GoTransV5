<x-layouts.auth title="Vérification en deux étapes">
    <h2 class="text-xl font-semibold mb-4">Vérification en deux étapes</h2>
    <p class="text-sm text-muted-foreground mb-6">
        Entrez le code généré par votre application d'authentification.
    </p>

    @if ($errors->any())
        <div class="mb-4 text-error text-sm space-y-1">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('two-factor.login.store') }}" class="space-y-4">
        @csrf

        <div>
            <label for="code" class="label-uppercase block mb-1">Code de vérification</label>
            <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" autofocus class="input-field">
        </div>

        <details class="text-sm">
            <summary class="text-accent hover:underline cursor-pointer">Utiliser un code de récupération</summary>
            <div class="mt-3">
                <label for="recovery_code" class="label-uppercase block mb-1">Code de récupération</label>
                <input id="recovery_code" name="recovery_code" type="text" class="input-field">
            </div>
        </details>

        <flux:button type="submit" variant="primary" class="w-full">
            Vérifier
        </flux:button>
    </form>
</x-layouts.auth>
