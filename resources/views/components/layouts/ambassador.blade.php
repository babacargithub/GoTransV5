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
    {{-- Plain markup on purpose: flux:header / flux:main switch <body> to Flux's sidebar grid layout. --}}
    <header class="sticky top-0 z-10 border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="mx-auto flex w-full max-w-2xl items-center justify-between gap-3 px-4 py-2 sm:px-6">
            <a href="{{ route('ambassador.home') }}" wire:navigate class="flex min-w-0 items-center gap-2 font-semibold">
                <flux:icon.truck variant="solid" class="size-5 shrink-0 text-accent" />
                <span class="truncate">Globe One Transport</span>
            </a>

            <form method="POST" action="{{ route('logout') }}" class="shrink-0">
                @csrf
                <flux:button type="submit" variant="ghost" size="sm" icon="arrow-right-start-on-rectangle">
                    <span class="hidden sm:inline">Déconnexion</span>
                    <span class="sr-only sm:hidden">Déconnexion</span>
                </flux:button>
            </form>
        </div>

        <nav class="mx-auto flex w-full max-w-2xl gap-1 overflow-x-auto px-4 pb-2 sm:px-6">
            <flux:button size="sm" variant="ghost" class="shrink-0" :href="route('ambassador.home')" wire:navigate :class="request()->routeIs('ambassador.home') ? 'bg-zinc-200/70 dark:bg-zinc-700' : ''">Accueil</flux:button>
            <flux:button size="sm" variant="ghost" class="shrink-0" :href="route('ambassador.earnings')" wire:navigate :class="request()->routeIs('ambassador.earnings') ? 'bg-zinc-200/70 dark:bg-zinc-700' : ''">Mes gains</flux:button>
            <flux:button size="sm" variant="ghost" class="shrink-0" :href="route('ambassador.sponsored-ambassadors')" wire:navigate :class="request()->routeIs('ambassador.sponsored-ambassadors') ? 'bg-zinc-200/70 dark:bg-zinc-700' : ''">Mon équipe</flux:button>
            <flux:button size="sm" variant="ghost" class="shrink-0" :href="route('ambassador.payouts')" wire:navigate :class="request()->routeIs('ambassador.payouts') ? 'bg-zinc-200/70 dark:bg-zinc-700' : ''">Mes paiements</flux:button>
        </nav>
    </header>

    <main class="mx-auto w-full max-w-2xl px-4 py-6 sm:px-6 sm:py-8">
        {{ $slot }}
    </main>

    @fluxScripts
</body>
</html>
