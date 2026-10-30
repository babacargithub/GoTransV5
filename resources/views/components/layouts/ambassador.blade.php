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

    <flux:main>
        {{ $slot }}
    </flux:main>

    @fluxScripts
</body>
</html>
