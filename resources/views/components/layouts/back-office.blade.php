<!DOCTYPE html>
<html lang="fr" class="dark:scheme-dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? 'Back Office — Globe One Transport' }}</title>
    <x-favicons />

    @fluxAppearance
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-white dark:bg-zinc-800">
    <flux:header sticky class="!h-[3.85rem] border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
        {{-- Mobile: toggle for the départs-stats sidebar --}}
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

        <flux:brand href="{{ route('back-office.departs.index') }}" name="Globe One Transport" class="me-6 max-sm:hidden">
            <x-slot name="logo">
                <flux:icon.truck variant="solid" class="size-5 text-accent" />
            </x-slot>
        </flux:brand>

        {{-- Desktop: horizontal navigation to the main sections --}}
        <flux:navbar class="max-lg:hidden">
            @if (\App\Enums\PermissionName::FullAccess->allowedForCurrentUser())
                <flux:navbar.item icon="presentation-chart-line" :href="route('back-office.dashboard')" :current="request()->routeIs('back-office.dashboard')">Tableau de bord</flux:navbar.item>
                <flux:dropdown>
                    <flux:navbar.item
                        icon="banknotes"
                        icon:trailing="chevron-down"
                        :current="request()->routeIs('back-office.caisses.*', 'back-office.comptes.*', 'back-office.cost-reports.*', 'back-office.revenus.*', 'back-office.depenses.*', 'back-office.expense-types.*', 'back-office.paiements-om.*', 'back-office.paiements-wave.*')"
                    >
                        Finances
                    </flux:navbar.item>
                    <flux:navmenu>
                        <flux:navmenu.item icon="wallet" :href="route('back-office.caisses.index')" :current="request()->routeIs('back-office.caisses.*')">Caisses</flux:navmenu.item>
                        <flux:navmenu.item icon="banknotes" :href="route('back-office.comptes.index')" :current="request()->routeIs('back-office.comptes.*')">Comptes</flux:navmenu.item>
                        <flux:navmenu.item icon="document-chart-bar" :href="route('back-office.cost-reports.index')" :current="request()->routeIs('back-office.cost-reports.*')">Bilans</flux:navmenu.item>
                        <flux:navmenu.item icon="chart-bar" :href="route('back-office.revenus.index')" :current="request()->routeIs('back-office.revenus.*')">Revenus</flux:navmenu.item>
                        <flux:navmenu.item icon="receipt-percent" :href="route('back-office.depenses.index')" :current="request()->routeIs('back-office.depenses.*')">Gestion dépenses</flux:navmenu.item>
                        <flux:navmenu.item icon="device-phone-mobile" :href="route('back-office.paiements-om.index')" :current="request()->routeIs('back-office.paiements-om.*')">Paiements OM</flux:navmenu.item>
                        <flux:navmenu.item icon="device-phone-mobile" :href="route('back-office.paiements-wave.index')" :current="request()->routeIs('back-office.paiements-wave.*')">Paiements Wave</flux:navmenu.item>
                        <flux:navmenu.item icon="tag" :href="route('back-office.expense-types.index')" :current="request()->routeIs('back-office.expense-types.*')">Types de dépense</flux:navmenu.item>
                    </flux:navmenu>
                </flux:dropdown>
            @endif

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
                    @if (\App\Enums\PermissionName::ConductDepartFeedbackCalls->allowedForCurrentUser())
                        <flux:navmenu.item icon="phone" :href="route('back-office.depart-feedbacks.calls')" :current="request()->routeIs('back-office.depart-feedbacks.calls')">Appels à passer</flux:navmenu.item>
                    @endif
                    @if (\App\Enums\PermissionName::ViewDepartFeedback->allowedForCurrentUser())
                        <flux:navmenu.item icon="star" :href="route('back-office.depart-feedbacks.report')" :current="request()->routeIs('back-office.depart-feedbacks.report')">Avis clients</flux:navmenu.item>
                    @endif
                </flux:navmenu>
            </flux:dropdown>

            @if (\App\Enums\PermissionName::ViewAmbassadors->allowedForCurrentUser())
                <flux:dropdown>
                    <flux:navbar.item
                        icon="megaphone"
                        icon:trailing="chevron-down"
                        :current="request()->routeIs('back-office.ambassadors.*', 'back-office.ambassador-payouts.*', 'back-office.ambassador-reward-rules.*', 'back-office.ambassador-documents.*')"
                    >
                        Ambassadeurs
                    </flux:navbar.item>
                    <flux:navmenu>
                        <flux:navmenu.item icon="list-bullet" :href="route('back-office.ambassadors.index')" :current="request()->routeIs('back-office.ambassadors.*')">Listes</flux:navmenu.item>
                        <flux:navmenu.item icon="banknotes" :href="route('back-office.ambassador-payouts.index')" :current="request()->routeIs('back-office.ambassador-payouts.*')">Commissions</flux:navmenu.item>
                        <flux:navmenu.item icon="adjustments-horizontal" :href="route('back-office.ambassador-reward-rules.index')" :current="request()->routeIs('back-office.ambassador-reward-rules.*')">Règles de récompense</flux:navmenu.item>
                        <flux:navmenu.item icon="document-text" :href="route('back-office.ambassador-documents.index')" :current="request()->routeIs('back-office.ambassador-documents.*')">Documents</flux:navmenu.item>
                    </flux:navmenu>
                </flux:dropdown>
            @endif

            @if (\App\Enums\PermissionName::FullAccess->allowedForCurrentUser())
                <flux:dropdown>
                    <flux:navbar.item icon="cog-6-tooth" icon:trailing="chevron-down">Admin</flux:navbar.item>
                    <flux:navmenu>
                        <flux:navmenu.item icon="users" :href="route('back-office.employes.index')" :current="request()->routeIs('back-office.employes.*')">Employés</flux:navmenu.item>
                        <flux:navmenu.item icon="shield-check" :href="route('back-office.users.index')" :current="request()->routeIs('back-office.users.*')">Gestion des utilisateurs</flux:navmenu.item>
                        <flux:navmenu.item icon="arrows-right-left" :href="route('back-office.trajets.index')" :current="request()->routeIs('back-office.trajets.*')">Trajets</flux:navmenu.item>
                        <flux:navmenu.item icon="truck" :href="route('back-office.vehicules.index')" :current="request()->routeIs('back-office.vehicules.*')">Véhicules</flux:navmenu.item>
                        <flux:navmenu.item icon="adjustments-horizontal" :href="route('back-office.parametres.index')" :current="request()->routeIs('back-office.parametres.*')">Paramètres</flux:navmenu.item>
                    </flux:navmenu>
                </flux:dropdown>
            @endif
        </flux:navbar>

        <flux:spacer />

        <div class="me-2">
            <livewire:back-office.global-search />
        </div>

        @if (\App\Enums\PermissionName::FullAccess->allowedForCurrentUser())
            <div class="me-2">
                <livewire:back-office.total-caisse-balance />
            </div>
        @endif

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
                @if (\App\Enums\PermissionName::FullAccess->allowedForCurrentUser())
                    <flux:menu.item icon="presentation-chart-line" :href="route('back-office.dashboard')">Tableau de bord</flux:menu.item>
                    <flux:menu.group heading="Finances">
                        <flux:menu.item icon="wallet" :href="route('back-office.caisses.index')">Caisses</flux:menu.item>
                        <flux:menu.item icon="banknotes" :href="route('back-office.comptes.index')">Comptes</flux:menu.item>
                        <flux:menu.item icon="document-chart-bar" :href="route('back-office.cost-reports.index')">Bilans</flux:menu.item>
                        <flux:menu.item icon="chart-bar" :href="route('back-office.revenus.index')">Revenus</flux:menu.item>
                        <flux:menu.item icon="receipt-percent" :href="route('back-office.depenses.index')">Gestion dépenses</flux:menu.item>
                        <flux:menu.item icon="tag" :href="route('back-office.expense-types.index')">Types de dépense</flux:menu.item>
                        <flux:menu.item icon="device-phone-mobile" :href="route('back-office.paiements-om.index')">Paiements OM</flux:menu.item>
                        <flux:menu.item icon="device-phone-mobile" :href="route('back-office.paiements-wave.index')">Paiements Wave</flux:menu.item>
                    </flux:menu.group>
                @endif

                <flux:menu.group heading="Départs">
                    <flux:menu.item icon="list-bullet" :href="route('back-office.departs.index')">Liste des départs</flux:menu.item>
                    <flux:menu.item icon="plus-circle" :href="route('back-office.departs.create')">Nouveau départ</flux:menu.item>
                    <flux:menu.item icon="map-pin" :href="route('back-office.point-deps.index')">Points de départ</flux:menu.item>
                    <flux:menu.item icon="map" :href="route('back-office.itineraires.index')">Itinéraires</flux:menu.item>
                    <flux:menu.item icon="clock" :href="route('back-office.horaires.index')">Horaires</flux:menu.item>
                    @if (\App\Enums\PermissionName::ConductDepartFeedbackCalls->allowedForCurrentUser())
                        <flux:menu.item icon="phone" :href="route('back-office.depart-feedbacks.calls')">Appels à passer</flux:menu.item>
                    @endif
                    @if (\App\Enums\PermissionName::ViewDepartFeedback->allowedForCurrentUser())
                        <flux:menu.item icon="star" :href="route('back-office.depart-feedbacks.report')">Avis clients</flux:menu.item>
                    @endif
                </flux:menu.group>

                @if (\App\Enums\PermissionName::ViewAmbassadors->allowedForCurrentUser())
                    <flux:menu.group heading="Ambassadeurs">
                        <flux:menu.item icon="list-bullet" :href="route('back-office.ambassadors.index')">Liste des ambassadeurs</flux:menu.item>
                        <flux:menu.item icon="banknotes" :href="route('back-office.ambassador-payouts.index')">Commissions ambassadeurs</flux:menu.item>
                        <flux:menu.item icon="adjustments-horizontal" :href="route('back-office.ambassador-reward-rules.index')">Règles de récompense</flux:menu.item>
                        <flux:menu.item icon="document-text" :href="route('back-office.ambassador-documents.index')">Documents</flux:menu.item>
                    </flux:menu.group>
                @endif

                @if (\App\Enums\PermissionName::FullAccess->allowedForCurrentUser())
                    <flux:menu.group heading="Admin">
                        <flux:menu.item icon="users" :href="route('back-office.employes.index')">Employés</flux:menu.item>
                        <flux:menu.item icon="shield-check" :href="route('back-office.users.index')">Gestion des utilisateurs</flux:menu.item>
                        <flux:menu.item icon="arrows-right-left" :href="route('back-office.trajets.index')">Trajets</flux:menu.item>
                        <flux:menu.item icon="truck" :href="route('back-office.vehicules.index')">Véhicules</flux:menu.item>
                        <flux:menu.item icon="adjustments-horizontal" :href="route('back-office.parametres.index')">Paramètres</flux:menu.item>
                    </flux:menu.group>
                @endif

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
    <flux:sidebar sticky stashable class="border-e border-zinc-200 bg-zinc-50 lg:w-72 dark:border-zinc-700 dark:bg-zinc-900">
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
