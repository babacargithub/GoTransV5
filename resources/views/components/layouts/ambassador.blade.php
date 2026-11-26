<!DOCTYPE html>
<html lang="fr" style="color-scheme: light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? 'Espace ambassadeur — Globe One Transport' }}</title>
    <x-favicons />

    <meta name="theme-color" content="#0EA5E9">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{-- Always light, like the public website: no dark appearance script, Flux accent switched to the sky blue. --}}
<body class="min-h-screen bg-brand-white text-brand-navy antialiased" style="--color-accent: var(--color-brand-sky-deep); --color-accent-content: var(--color-brand-sky-deep); --color-accent-foreground: #ffffff;">
    {{-- Plain markup on purpose: flux:header / flux:main switch <body> to Flux's sidebar grid layout. --}}
    <header class="sticky top-0 z-10 bg-brand-sky text-brand-navy">
        <div class="mx-auto flex w-full max-w-2xl items-center justify-between gap-3 px-4 py-2 sm:px-6">
            <a href="{{ route('ambassador.home') }}" wire:navigate class="flex min-w-0 items-center gap-2 font-bold">
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

        <nav class="mx-auto flex w-full max-w-2xl gap-1 overflow-x-auto px-4 pb-2 sm:px-6">
            <flux:button size="sm" variant="ghost" class="shrink-0 !text-brand-navy hover:!bg-white/30" :href="route('ambassador.home')" wire:navigate :class="request()->routeIs('ambassador.home') ? '!bg-white/50 font-semibold' : ''">Accueil</flux:button>
            <flux:button size="sm" variant="ghost" class="shrink-0 !text-brand-navy hover:!bg-white/30" :href="route('ambassador.earnings')" wire:navigate :class="request()->routeIs('ambassador.earnings') ? '!bg-white/50 font-semibold' : ''">Mes gains</flux:button>
            @if (\App\Models\Ambassador::query()->where('user_account_id', auth()->id())->first()?->hasAbility(\App\Enums\AmbassadorAbilityName::Sponsor))
    <flux:button size="sm" variant="ghost" class="shrink-0 !text-brand-navy hover:!bg-white/30" :href="route('ambassador.sponsored-ambassadors')" wire:navigate :class="request()->routeIs('ambassador.sponsored-ambassadors') ? '!bg-white/50 font-semibold' : ''">Mon équipe</flux:button>
            @endif
            <flux:button size="sm" variant="ghost" class="shrink-0 !text-brand-navy hover:!bg-white/30" :href="route('ambassador.payouts')" wire:navigate :class="request()->routeIs('ambassador.payouts') ? '!bg-white/50 font-semibold' : ''">Mes paiements</flux:button>
            <flux:button size="sm" variant="ghost" class="shrink-0 !text-brand-navy hover:!bg-white/30" :href="route('ambassador.contract')" wire:navigate :class="request()->routeIs('ambassador.contract') ? '!bg-white/50 font-semibold' : ''">Mon contrat</flux:button>
        </nav>
    </header>

    <main class="mx-auto w-full max-w-2xl px-4 py-6 sm:px-6 sm:py-8 [&_[data-flux-heading]]:text-brand-navy">
        {{ $slot }}
    </main>

    @fluxScripts
</body>
</html>
