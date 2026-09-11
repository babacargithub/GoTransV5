@props([
    // SEO — every public website page MUST pass a unique title and description.
    'title' => 'Globe One Transport',
    'description' => 'Globe One Transport — voyagez en bus entre Saint-Louis (UGB) et Dakar. Trajets directs, fiables et confortables, réservation en ligne.',
    'keywords' => null,
    'canonical' => null,
    'robots' => 'index, follow',
    'ogType' => 'website',
    'ogImage' => null,
    'locale' => 'fr_SN',
])

@php
    $canonicalUrl = $canonical ?? url()->current();
    $ogImageUrl = $ogImage ?? asset('images/website/og-default.jpg');
    $siteName = 'Globe One Transport';

    $organizationJsonLd = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => $siteName,
        'url' => url('/'),
        'logo' => asset('images/website/logo.png'),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
@endphp

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    @if ($keywords)
        <meta name="keywords" content="{{ $keywords }}">
    @endif
    <meta name="robots" content="{{ $robots }}">
    <link rel="canonical" href="{{ $canonicalUrl }}">

    {{-- Open Graph --}}
    <meta property="og:site_name" content="{{ $siteName }}">
    <meta property="og:type" content="{{ $ogType }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    <meta property="og:image" content="{{ $ogImageUrl }}">
    <meta property="og:locale" content="{{ $locale }}">

    {{-- Twitter --}}
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $description }}">
    <meta name="twitter:image" content="{{ $ogImageUrl }}">

    <meta name="theme-color" content="#1DC8FE">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- Base Organization structured data, present on every page. --}}
    <script type="application/ld+json">{!! $organizationJsonLd !!}</script>

    {{-- Page-specific structured data (BusTrip, FAQPage, BreadcrumbList, …). --}}
    {{ $structuredData ?? '' }}

    {{-- Escape hatch for any extra per-page head tags. --}}
    {{ $head ?? '' }}
</head>
<body class="bg-brand-white text-brand-navy antialiased min-h-screen flex flex-col">
    <a href="#contenu-principal" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-brand-navy focus:px-4 focus:py-2 focus:text-brand-white">
        Aller au contenu
    </a>

    <header class="bg-brand-cyan text-brand-navy">
        <div class="max-w-5xl mx-auto px-4 py-4 flex flex-wrap items-center justify-between gap-4">
            <a href="{{ route('website.home') }}" class="font-bold text-lg" rel="home">
                {{ $siteName }}
            </a>

            {{-- Pure-CSS mobile menu toggle: the checkbox drives `peer-checked` on the nav below. --}}
            <input type="checkbox" id="menu-principal-toggle" class="peer hidden">
            <label
                for="menu-principal-toggle"
                class="md:hidden cursor-pointer text-brand-white"
                aria-label="Ouvrir le menu de navigation"
            >
                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </label>

            <nav
                aria-label="Navigation principale"
                class="hidden w-full flex-col gap-3 text-sm font-medium peer-checked:flex md:flex md:w-auto md:flex-row md:items-center md:gap-6"
            >
                <a href="{{ route('website.home') }}" class="hover:underline">Voyages</a>
                <a href="{{ route('website.yobante') }}" class="hover:underline">Yobanté</a>
                <a href="{{ route('website.aide') }}" class="hover:underline">Aide</a>
            </nav>
        </div>
    </header>

    <main id="contenu-principal" class="flex-1">
        {{ $slot }}
    </main>

    <footer class="bg-brand-cyan text-brand-navy text-sm">
        <div class="max-w-5xl mx-auto px-4 py-8 flex flex-col gap-6 sm:flex-row sm:justify-between">
            <div class="flex flex-col gap-1">
                <p class="font-semibold uppercase tracking-wide">Contact</p>
                <a href="tel:+221771273535" class="hover:underline">77 127 35 35</a>
                <a href="tel:+221771163003" class="hover:underline">77 116 30 03</a>
            </div>

            <address class="not-italic flex flex-col gap-1 sm:max-w-xs sm:text-right">
                <p class="font-semibold uppercase tracking-wide">Adresse</p>
                <p>Campus Social UGB en face village B</p>
            </address>
        </div>

        <div class="border-t border-brand-navy/15">
            <div class="max-w-5xl mx-auto px-4 py-4">
                <p>&copy; {{ date('Y') }} TEKKI PUB SARL. Tous droits réservés.</p>
            </div>
        </div>
    </footer>

    @stack('scripts')
</body>
</html>
