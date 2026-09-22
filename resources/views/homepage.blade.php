<!DOCTYPE html>
<html lang="fr" class="dark:scheme-dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $pageTitle ?? 'Globe One Transport' }}</title>

    @fluxAppearance
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-white dark:bg-zinc-800">
    <flux:header sticky class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

        <flux:brand href="{{ url('/') }}" name="Globe One Transport" class="max-lg:hidden">
            <x-slot name="logo">
                <flux:icon.truck variant="solid" class="size-5 text-accent" />
            </x-slot>
        </flux:brand>

        <flux:spacer />

        <flux:button href="{{ route('login') }}" variant="ghost" size="sm">Connexion</flux:button>
        <flux:button href="{{ route('register') }}" variant="primary" size="sm">Créer un compte</flux:button>
    </flux:header>

    <flux:sidebar sticky stashable class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

        <flux:brand href="{{ url('/') }}" name="Globe One Transport" class="px-2">
            <x-slot name="logo">
                <flux:icon.truck variant="solid" class="size-5 text-accent" />
            </x-slot>
        </flux:brand>

        <flux:sidebar.nav>
            {{-- No navigation items yet --}}
        </flux:sidebar.nav>

        <flux:sidebar.spacer />

        <flux:text class="px-3 text-xs text-zinc-400 dark:text-zinc-500">
            Menu à venir
        </flux:text>
    </flux:sidebar>

    <flux:main container>
        <flux:heading size="xl" level="1">Bienvenue sur Globe One Transport</flux:heading>

        <flux:text class="mt-2 max-w-2xl text-base">
            Cette page d'accueil est un point de départ. L'en-tête et la barre latérale
            sont en place ; les éléments de navigation seront ajoutés prochainement.
        </flux:text>

        <flux:separator class="my-8" variant="subtle" />

        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            <flux:card>
                <flux:heading size="lg">Réservations</flux:heading>
                <flux:text class="mt-2">Bientôt disponible depuis cette interface.</flux:text>
            </flux:card>
            <flux:card>
                <flux:heading size="lg">Départs</flux:heading>
                <flux:text class="mt-2">Consultez les prochains trajets planifiés.</flux:text>
            </flux:card>
            <flux:card>
                <flux:heading size="lg">Support</flux:heading>
                <flux:text class="mt-2">Contactez notre équipe pour toute question.</flux:text>
            </flux:card>
        </div>
    </flux:main>

    @fluxScripts
</body>
</html>
