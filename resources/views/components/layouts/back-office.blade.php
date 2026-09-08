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
    <flux:header sticky class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
        {{-- Mobile: toggle for the départs-stats sidebar --}}
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

        <flux:brand href="{{ route('back-office.departs.index') }}" name="Globe One Transport" class="me-6 max-sm:hidden">
            <x-slot name="logo">
                <flux:icon.truck variant="solid" class="size-5 text-accent" />
            </x-slot>
        </flux:brand>

        {{-- Desktop: horizontal navigation to the main sections --}}
        <flux:navbar class="max-lg:hidden">
            <flux:dropdown>
                <flux:navbar.item
                    icon="banknotes"
                    icon:trailing="chevron-down"
                    :current="request()->routeIs('back-office.caisses.*', 'back-office.paiements-om.*', 'back-office.paiements-wave.*')"
                >
                    Finances
                </flux:navbar.item>
                <flux:navmenu>
                    <flux:navmenu.item icon="wallet" :href="route('back-office.caisses.index')" :current="request()->routeIs('back-office.caisses.*')">Solde des caisses</flux:navmenu.item>
                    <flux:navmenu.item icon="device-phone-mobile" :href="route('back-office.paiements-om.index')" :current="request()->routeIs('back-office.paiements-om.*')">Paiements OM</flux:navmenu.item>
                    <flux:navmenu.item icon="device-phone-mobile" :href="route('back-office.paiements-wave.index')" :current="request()->routeIs('back-office.paiements-wave.*')">Paiements Wave</flux:navmenu.item>
                </flux:navmenu>
            </flux:dropdown>

            <flux:dropdown>
                <flux:navbar.item
                    icon="rocket-launch"
                    icon:trailing="chevron-down"
                    :current="request()->routeIs('back-office.departs.*')"
                >
                    Départs
                </flux:navbar.item>
                <flux:navmenu>
                    <flux:navmenu.item icon="list-bullet" :href="route('back-office.departs.index')">Liste des départs</flux:navmenu.item>
                    <flux:navmenu.item icon="plus-circle" :href="route('back-office.departs.create')">Nouveau départ</flux:navmenu.item>
                    <flux:navmenu.item icon="map-pin" :href="route('back-office.point-deps.index')" :current="request()->routeIs('back-office.point-deps.*')">Points de départ</flux:navmenu.item>
                    <flux:navmenu.item icon="map" :href="route('back-office.itineraires.index')" :current="request()->routeIs('back-office.itineraires.*')">Itinéraires</flux:navmenu.item>
                    <flux:navmenu.item icon="clock" :href="route('back-office.horaires.index')" :current="request()->routeIs('back-office.horaires.*')">Horaires</flux:navmenu.item>
                </flux:navmenu>
            </flux:dropdown>

            <flux:dropdown>
                <flux:navbar.item icon="cog-6-tooth" icon:trailing="chevron-down">Admin</flux:navbar.item>
                <flux:navmenu>
                    <flux:navmenu.item icon="users" :href="route('back-office.employes.index')" :current="request()->routeIs('back-office.employes.*')">Employés</flux:navmenu.item>
                    <flux:navmenu.item icon="arrows-right-left" :href="route('back-office.trajets.index')" :current="request()->routeIs('back-office.trajets.*')">Trajets</flux:navmenu.item>
                    <flux:navmenu.item icon="truck" :href="route('back-office.vehicules.index')" :current="request()->routeIs('back-office.vehicules.*')">Véhicules</flux:navmenu.item>
                    <flux:navmenu.item icon="adjustments-horizontal" :href="route('back-office.parametres.index')" :current="request()->routeIs('back-office.parametres.*')">Paramètres</flux:navmenu.item>
                </flux:navmenu>
            </flux:dropdown>
        </flux:navbar>

        <flux:spacer />

        <div class="me-2 max-lg:hidden">
            <livewire:back-office.global-search />
        </div>

        <form method="POST" action="{{ route('logout') }}" class="max-lg:hidden">
            @csrf
            <flux:button type="submit" variant="subtle" size="sm" icon="arrow-right-start-on-rectangle">
                Déconnexion
            </flux:button>
        </form>

        {{-- Mobile: the same navigation collapsed into a right-aligned hamburger --}}
        <flux:dropdown class="lg:hidden" align="end">
            <flux:button variant="subtle" size="sm" icon="bars-3" square aria-label="Menu de navigation" />
            <flux:menu>
                <flux:menu.group heading="Finances">
                    <flux:menu.item icon="wallet" :href="route('back-office.caisses.index')">Solde des caisses</flux:menu.item>
                    <flux:menu.item icon="device-phone-mobile" :href="route('back-office.paiements-om.index')">Paiements OM</flux:menu.item>
                    <flux:menu.item icon="device-phone-mobile" :href="route('back-office.paiements-wave.index')">Paiements Wave</flux:menu.item>
                </flux:menu.group>

                <flux:menu.group heading="Départs">
                    <flux:menu.item icon="list-bullet" :href="route('back-office.departs.index')">Liste des départs</flux:menu.item>
                    <flux:menu.item icon="plus-circle" :href="route('back-office.departs.create')">Nouveau départ</flux:menu.item>
                    <flux:menu.item icon="map-pin" :href="route('back-office.point-deps.index')">Points de départ</flux:menu.item>
                    <flux:menu.item icon="map" :href="route('back-office.itineraires.index')">Itinéraires</flux:menu.item>
                    <flux:menu.item icon="clock" :href="route('back-office.horaires.index')">Horaires</flux:menu.item>
                </flux:menu.group>

                <flux:menu.group heading="Admin">
                    <flux:menu.item icon="users" :href="route('back-office.employes.index')">Employés</flux:menu.item>
                    <flux:menu.item icon="arrows-right-left" :href="route('back-office.trajets.index')">Trajets</flux:menu.item>
                    <flux:menu.item icon="truck" :href="route('back-office.vehicules.index')">Véhicules</flux:menu.item>
                    <flux:menu.item icon="adjustments-horizontal" :href="route('back-office.parametres.index')">Paramètres</flux:menu.item>
                </flux:menu.group>

                <flux:menu.separator />

                <form method="POST" action="{{ route('logout') }}" class="p-1">
                    @csrf
                    <flux:button type="submit" variant="subtle" size="sm" icon="arrow-right-start-on-rectangle" class="w-full justify-start">
                        Déconnexion
                    </flux:button>
                </form>
            </flux:menu>
        </flux:dropdown>
    </flux:header>

    {{-- Sidebar: booking-count stats for the current départs. Wider on desktop; a
         self-contained Livewire component (own poll / refresh). --}}
    <flux:sidebar sticky stashable class="border-e border-zinc-200 bg-zinc-50 lg:w-96 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

        <livewire:back-office.depart-stats-sidebar />
    </flux:sidebar>

    <flux:main>
        {{-- Mobile: open the départs-stats sidebar by default on each page load --}}
        <div
            x-data
            x-init="window.matchMedia('(max-width: 1023px)').matches && $dispatch('flux-sidebar-toggle')"
            hidden
        ></div>

        {{ $slot }}
    </flux:main>

    @fluxScripts
</body>
</html>
