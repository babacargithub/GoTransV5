@php
    $trajetLabel = $trajet->public_name ?? $trajet->name;
    $routeDescription = $trajet->departure_city && $trajet->arrival_city
        ? "{$trajet->departure_city} vers {$trajet->arrival_city}"
        : $trajetLabel;
@endphp

<x-layouts.website
    :title="$trajetLabel.' — Départs en bus | Globe One Transport'"
    :description="'Consultez les prochains départs en bus pour la caravane '.$routeDescription.' et réservez votre billet avec Globe One Transport.'"
>
    <section class="max-w-5xl mx-auto px-4 py-12 text-brand-navy">
        <nav aria-label="Fil d'Ariane" class="mb-6 text-sm">
            <a href="{{ route('website.home') }}" class="underline">Caravanes</a>
            <span aria-hidden="true"> / </span>
            <span>{{ $trajetLabel }}</span>
        </nav>

        <h1 class="text-4xl font-bold mb-2">Départs — {{ $trajetLabel }}</h1>
        @if ($trajet->departure_city && $trajet->arrival_city)
            <p class="text-lg mb-8">{{ $trajet->departure_city }} → {{ $trajet->arrival_city }}</p>
        @endif

        <p class="rounded-lg border border-brand-navy/15 bg-brand-cyan/10 px-4 py-6">
            La liste des départs pour cette caravane sera bientôt disponible.
        </p>
    </section>
</x-layouts.website>
