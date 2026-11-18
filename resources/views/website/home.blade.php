<x-layouts.website
    title="Globe One Transport — Caravanes UGB-DAKAR, DAKAR-UGB"
    description="Réservation Caravanes étudiants UGB, DAKAR-UGB et UGB-DAKAR avec Globe One Transports"
    keywords="caravanes ugb, Globe One Transport, caravane dakar ugb, réservation caravane ugb dakar, lien globe one, e-transport, UGB, réservation caravane, caravanes étudiants ugb"
>
    <section class="max-w-5xl mx-auto px-4 py-12">
        <header class="mb-8 text-brand-navy">
            <h1 class="text-4xl font-bold mb-2">Caravanes UGB-DAKAR, DAKAR-UGB Globe One Transports</h1>
            <p class="text-lg">Réservations caravane étudiants UGB.</p>
        </header>

        @if ($trajets->isEmpty())
            <p class="rounded-lg border border-brand-navy/15 bg-brand-cyan/10 px-4 py-6 text-brand-navy">
                Aucune caravane disponible pour le moment.
            </p>
        @else
            <ul class="grid gap-3 sm:grid-cols-2">
                @foreach ($trajets as $trajet)
                    <li>
                        <a href="{{ route('website.caravanes.show', $trajet) }}" class="caravane-row group">
                            <div class="min-w-0 flex-1">
                                <span class="block truncate font-semibold text-brand-navy">
                                    {{ $trajet->public_name ?? $trajet->name }}
                                </span>

                                @if ($trajet->departure_city && $trajet->arrival_city)
                                    <span class="mt-1 flex items-center gap-1.5 text-sm text-brand-navy/70">
                                        <x-website.icon.map-pin class="h-4 w-4 flex-shrink-0 text-brand-navy" />
                                        <span class="truncate">{{ $trajet->departure_city_label }}</span>

                                        <x-website.icon.arrow-right class="h-3.5 w-3.5 flex-shrink-0 text-brand-navy/50" />

                                        <x-website.icon.flag class="h-4 w-4 flex-shrink-0 text-brand-navy" />
                                        <span class="truncate">{{ $trajet->arrival_city_label }}</span>
                                    </span>
                                @endif
                            </div>

                            <x-website.icon.chevron-right class="h-5 w-5 flex-shrink-0 text-brand-navy/40 transition-transform group-hover:translate-x-0.5 group-hover:text-brand-navy" />
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-layouts.website>
