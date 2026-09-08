<!DOCTYPE html>
<html lang="fr" class="dark:scheme-dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Back Office — Globe One Transport' }}</title>

    @fluxAppearance
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-white dark:bg-zinc-800">
    <flux:sidebar sticky stashable class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

        <flux:brand href="{{ route('back-office.departs.index') }}" name="Globe One Transport" class="px-2">
            <x-slot name="logo">
                <flux:icon.truck variant="solid" class="size-5 text-accent" />
            </x-slot>
        </flux:brand>

        <flux:navlist variant="outline">
            <flux:navlist.item
                icon="rocket-launch"
                :href="route('back-office.departs.index')"
                :current="request()->routeIs('back-office.departs.*')"
            >
                Départs
            </flux:navlist.item>
        </flux:navlist>

        <flux:spacer />

        <form method="POST" action="{{ route('logout') }}" class="px-2">
            @csrf
            <flux:button
                type="submit"
                variant="subtle"
                size="sm"
                icon="arrow-right-start-on-rectangle"
                class="w-full justify-start"
            >
                Déconnexion
            </flux:button>
        </form>
    </flux:sidebar>

    <flux:header sticky class="border-b border-zinc-200 bg-zinc-50 lg:hidden dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />
        <flux:spacer />
        <flux:brand href="{{ route('back-office.departs.index') }}" name="Globe One Transport">
            <x-slot name="logo">
                <flux:icon.truck variant="solid" class="size-5 text-accent" />
            </x-slot>
        </flux:brand>
    </flux:header>

    <flux:main>
        {{ $slot }}
    </flux:main>

    @fluxScripts
</body>
</html>
