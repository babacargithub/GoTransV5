<!DOCTYPE html>
<html lang="fr" style="color-scheme: light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? 'Espace amicale — Globe One Transport' }}</title>
    <x-favicons />

    <meta name="theme-color" content="#0EA5E9">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{-- Always light, like the public website: no dark appearance script, Flux accent switched to the sky blue. --}}
<body class="min-h-screen bg-brand-white text-brand-navy antialiased" style="--color-accent: var(--color-brand-sky-deep); --color-accent-content: var(--color-brand-sky-deep); --color-accent-foreground: #ffffff;">
    {{-- Plain markup on purpose: flux:header / flux:main switch <body> to Flux's sidebar grid layout. --}}
    <header class="sticky top-0 z-10 bg-brand-sky text-brand-navy">
        <div class="mx-auto flex w-full max-w-3xl items-center justify-between gap-3 px-4 py-2 sm:px-6">
            <a href="{{ route('amicale.home') }}" wire:navigate class="flex min-w-0 items-center gap-2 font-bold">
                <flux:icon.truck variant="solid" class="size-5 shrink-0 text-brand-navy" />
                <span class="truncate">Globe One Transport</span>
            </a>

            <form method="POST" action="{{ route('logout') }}" class="shrink-0">
                @csrf
                <flux:button type="submit" variant="ghost" size="sm" icon="arrow-right-start-on-rectangle" class="!text-brand-navy hover:!bg-white/30">
                    <span class="hidden sm:inline">Déconnexion</span>
                    <span class="sr-only sm:hidden">Déconnexion</span>
                </flux:button>
            </form>
        </div>
    </header>

    <main class="mx-auto w-full max-w-3xl px-4 py-6 sm:px-6 sm:py-8 [&_[data-flux-heading]]:text-brand-navy">
        {{ $slot }}
    </main>

    @fluxScripts
    {{-- Flux defaults to the visitor's system appearance and adds the `dark` class: pin it to light so Flux components match the page. --}}
    <script>window.Flux.applyAppearance('light')</script>
</body>
</html>
