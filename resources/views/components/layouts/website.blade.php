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
        <div class="max-w-5xl mx-auto px-4 py-4 flex items-center justify-between gap-4">
            <a href="{{ route('website.home') }}" class="flex items-center gap-2 font-bold text-lg" rel="home">
                <span aria-hidden="true">🚌</span>
                <span>{{ $siteName }}</span>
            </a>

            @isset($navigation)
                <nav aria-label="Navigation principale" class="text-sm">
                    {{ $navigation }}
                </nav>
            @endisset
        </div>
    </header>

    <main id="contenu-principal" class="flex-1">
        {{ $slot }}
    </main>

    <footer class="bg-brand-cyan text-brand-navy text-sm">
        <div class="max-w-5xl mx-auto px-4 py-8 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <p>&copy; {{ date('Y') }} {{ $siteName }}. Tous droits réservés.</p>
            <p class="opacity-80">Transport de voyageurs — Saint-Louis · Dakar</p>
        </div>
    </footer>
</body>
</html>
