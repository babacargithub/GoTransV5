<x-layouts.website
    title="Globe One Transport — Bus Saint-Louis (UGB) ↔ Dakar"
    description="Réservez votre trajet en bus entre Saint-Louis (UGB) et Dakar avec Globe One Transport. Départs réguliers, trajets directs et fiables, billets en ligne."
    keywords="bus UGB Dakar, transport Saint-Louis Dakar, réservation bus Sénégal, Globe One Transport"
>
    <section class="max-w-5xl mx-auto px-4 py-12">
        <header class="mb-8 text-brand-navy">
            <h1 class="text-4xl font-bold mb-2">Nos caravanes</h1>
            <p class="text-lg">Choisissez votre caravane pour voir les prochains départs.</p>
        </header>

        @if ($trajets->isEmpty())
            <p class="rounded-lg border border-brand-navy/15 bg-brand-cyan/10 px-4 py-6 text-brand-navy">
                Aucune caravane disponible pour le moment.
            </p>
        @else
            <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($trajets as $trajet)
                    <li>
                        <a
                            href="{{ route('website.caravanes.show', $trajet) }}"
                            class="flex h-full flex-col gap-2 rounded-lg border border-brand-navy/15 bg-brand-white p-5 transition-colors hover:border-brand-cyan hover:bg-brand-cyan/10"
                        >
                            <span class="text-lg font-semibold text-brand-navy">
                                {{ $trajet->public_name ?? $trajet->name }}
                            </span>
                            @if ($trajet->departure_city && $trajet->arrival_city)
                                <span class="text-sm text-brand-navy/70">
                                    {{ $trajet->departure_city }} → {{ $trajet->arrival_city }}
                                </span>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-layouts.website>
