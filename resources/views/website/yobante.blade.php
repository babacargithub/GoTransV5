@php
    $yobanteContactNumbers = [
        ['label' => '77 116 30 03', 'telephone' => '+221771163003'],
        ['label' => '77 127 12 12', 'telephone' => '+221771271212'],
    ];

    $yobanteServiceJsonLd = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Service',
        'name' => 'Yobanté — envoi de colis par bus',
        'serviceType' => 'Envoi de colis',
        'description' => 'Envoyer ou recevoir un Yobbante via les bus Globe One Transport entre Saint-Louis (UGB) et Dakar.',
        'provider' => ['@type' => 'Organization', 'name' => 'Globe One Transport', 'url' => url('/')],
        'areaServed' => [
            ['@type' => 'City', 'name' => 'Saint-Louis'],
            ['@type' => 'City', 'name' => 'Dakar'],
        ],
        'url' => route('website.yobante'),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

    $breadcrumbJsonLd = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Accueil', 'item' => route('website.home')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Yobanté', 'item' => route('website.yobante')],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
@endphp

<x-layouts.website
    title="Yobanté — Envoi de colis Saint-Louis Dakar par bus | Globe One Transport"
    description="Envoyer ou recevoir un Yobbante via les bus Globe One Transport entre Saint-Louis (UGB) et Dakar. Dépôt possible la veille du départ, points de récupération à l'UGB et à Dakar."
    keywords="Yobanté, Yobbante, envoi colis Saint-Louis Dakar, envoyer un colis par bus, livraison colis UGB, colis Dakar Pikine, Globe One Transport"
>
    <x-slot:structuredData>
        <script type="application/ld+json">{!! $yobanteServiceJsonLd !!}</script>
        <script type="application/ld+json">{!! $breadcrumbJsonLd !!}</script>
    </x-slot:structuredData>

    <section class="max-w-3xl mx-auto px-4 py-10 text-brand-navy">
        <nav aria-label="Fil d'Ariane" class="mb-6 text-sm">
            <a href="{{ route('website.home') }}" class="underline">Accueil</a>
            <span aria-hidden="true"> / </span>
            <span>Yobanté</span>
        </nav>

        <h1 class="text-3xl md:text-4xl font-bold mb-8">Envoyer ou recevoir un Yobbante via nos bus</h1>

        <div class="rounded-lg border border-brand-navy/15 bg-brand-cyan/10 px-4 py-6 flex flex-col gap-4">
            <p class="text-lg">Pour envoyer un Yobbanté veuillez contacter l'un des numéros ci-dessous</p>

            <ul class="flex flex-wrap gap-x-8 gap-y-2 text-lg font-semibold">
                @foreach ($yobanteContactNumbers as $yobanteContactNumber)
                    <li><a href="tel:{{ $yobanteContactNumber['telephone'] }}" class="hover:underline">{{ $yobanteContactNumber['label'] }}</a></li>
                @endforeach
            </ul>

            <p>Possibilité de déposer votre colis la veille du départ</p>
            <p>Point de récupération UGB: Boutique Globe One en face village B</p>
            <p>Point de récupération DAKAR: Bount Pikine</p>
        </div>
    </section>
</x-layouts.website>
