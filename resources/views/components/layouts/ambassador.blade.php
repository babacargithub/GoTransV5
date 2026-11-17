<!DOCTYPE html>
<html lang="fr" class="dark:scheme-dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? 'Espace ambassadeur — Globe One Transport' }}</title>
    <x-favicons />

    @fluxAppearance
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-white dark:bg-zinc-800">
    <flux:header class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:brand href="{{ route('ambassador.home') }}" name="Globe One Transport">
            <x-slot name="logo">
                <flux:icon.truck variant="solid" class="size-5 text-accent" />
            </x-slot>
        </flux:brand>

        <flux:spacer />

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <flux:button type="submit" variant="ghost" size="sm" icon="arrow-right-start-on-rectangle">Déconnexion</flux:button>
        </form>
    </flux:header>

    <nav class="flex gap-1 overflow-x-auto border-b border-zinc-200 bg-white px-4 py-2 dark:border-zinc-700 dark:bg-zinc-800">
        <flux:button size="sm" variant="ghost" :href="route('ambassador.home')" wire:navigate :class="request()->routeIs('ambassador.home') ? 'bg-zinc-100 dark:bg-zinc-700' : ''">Accueil</flux:button>
        <flux:button size="sm" variant="ghost" :href="route('ambassador.earnings')" wire:navigate :class="request()->routeIs('ambassador.earnings') ? 'bg-zinc-100 dark:bg-zinc-700' : ''">Mes gains</flux:button>
        <flux:button size="sm" variant="ghost" :href="route('ambassador.sponsored-ambassadors')" wire:navigate :class="request()->routeIs('ambassador.sponsored-ambassadors') ? 'bg-zinc-100 dark:bg-zinc-700' : ''">Mon équipe</flux:button>
        <flux:button size="sm" variant="ghost" :href="route('ambassador.payouts')" wire:navigate :class="request()->routeIs('ambassador.payouts') ? 'bg-zinc-100 dark:bg-zinc-700' : ''">Mes paiements</flux:button>
    </nav>

    <flux:main>
        {{ $slot }}
    </flux:main>

    @fluxScripts
</body>
</html>
