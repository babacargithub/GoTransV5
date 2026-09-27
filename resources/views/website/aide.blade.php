@php
    $frequentlyAskedQuestions = [
        [
            'question' => 'Comment Payer Mon Ticket ?',
            'answer' => 'Vous pouvez payer votre ticket par Wave ou Orange Money. Si vous avez des problèmes de paiement, contactez nous par Whatsapp au 77 127 35 35',
        ],
        [
            'question' => 'Que faire en cas de retard ?',
            'answer' => 'En cas de retard, vous perdez votre ticket car il est non remboursable',
        ],
        [
            'question' => 'Le ticket est il-remboursable en cas d\'annulation ?',
            'answer' => 'Le ticket n\'est pas remboursable',
        ],
        [
            'question' => 'Comment réclamer des bagages perdus?',
            'answer' => 'En cas de bagages perdus, vous pouvez le signaler au 77 127 35 35 pour que nos agents puissent s\'en occuper.',
        ],
        [
            'question' => 'Comment signaler ou dénoncer un fait ?',
            'answer' => 'Si vous avez remarqué un fait quelque blamable vous pouvez le signaler directement au directeur général via whatsapp au 77 330 08 53',
        ],
        [
            'question' => 'Comment faire réserver une autre personne?',
            'answer' => 'Vous pouvez réserver pour une autre personne dans l\'application',
        ],
        [
            'question' => 'Les conditions du voyage',
            'answer' => "Pour voyager avec nous, vous devez respecter les conditions fixées. \n Avoir acheté un ticket \n Être sur le lieu de rendez-vous à temps\n Ne pas avoir d'objets prohibés dans vos bagages",
        ],
        [
            'question' => 'Nous contacter',
            'answer' => 'Vous pouvez nous contacter via nos numéros respectifs: 77 127 35 35 / 77 116 30 94/ 77 116 30 03',
        ],
    ];

    $faqPageJsonLd = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => array_map(fn (array $item): array => [
            '@type' => 'Question',
            'name' => $item['question'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['answer']],
        ], $frequentlyAskedQuestions),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

    $breadcrumbJsonLd = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Accueil', 'item' => route('website.home')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Aide', 'item' => route('website.aide')],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
@endphp

<x-layouts.website
    title="Foire aux questions — Aide | Globe One Transport"
    description="Foire aux questions Globe One Transport : paiement du ticket par Wave ou Orange Money, retard, remboursement, bagages perdus, conditions du voyage et numéros de contact."
    keywords="aide Globe One Transport, foire aux questions, paiement ticket Wave Orange Money, ticket remboursable, bagages perdus, réservation bus UGB Dakar, contact transport Saint-Louis"
>
    <x-slot:structuredData>
        <script type="application/ld+json">{!! $faqPageJsonLd !!}</script>
        <script type="application/ld+json">{!! $breadcrumbJsonLd !!}</script>
    </x-slot:structuredData>

    <section class="max-w-3xl mx-auto px-4 py-10 text-brand-navy">
        <nav aria-label="Fil d'Ariane" class="mb-6 text-sm">
            <a href="{{ route('website.home') }}" class="underline">Accueil</a>
            <span aria-hidden="true"> / </span>
            <span>Aide</span>
        </nav>

        <h1 class="text-3xl md:text-4xl font-bold mb-8">Foire aux questions</h1>

        <div class="flex flex-col gap-3">
            @foreach ($frequentlyAskedQuestions as $item)
                <details class="group rounded-lg border border-brand-navy/15 bg-brand-white">
                    <summary class="cursor-pointer list-none px-4 py-4 flex items-center justify-between gap-4">
                        <h2 class="text-base md:text-lg font-semibold">{{ $item['question'] }}</h2>
                        <x-website.icon.chevron-right class="h-5 w-5 shrink-0 transition-transform group-open:rotate-90" />
                    </summary>
                    <p class="px-4 pb-4 whitespace-pre-line">{{ $item['answer'] }}</p>
                </details>
            @endforeach
        </div>
    </section>
</x-layouts.website>
