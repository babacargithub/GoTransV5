@php
    use App\Models\Bus;
    use App\Models\Depart;
    use Illuminate\Support\Carbon;

    $trajetLabel = $trajet->public_name ?? $trajet->name;
    $routeDescription = $trajet->departure_city && $trajet->arrival_city
        ? "{$trajet->departure_city} vers {$trajet->arrival_city}"
        : $trajetLabel;

    // Flatten the mobile resource (départ -> buses) into one bookable "trip" card per bus,
    // matching the mobile app's departure list.
    $tripCards = collect($trajetDeparts['departs'] ?? [])->flatMap(function (array $depart) {
        $departDate = Carbon::parse($depart['date'])->locale('fr');
        $departUnavailable = ($depart['is_closed'] ?? false) || ($depart['is_passed'] ?? false);
        $buses = collect($depart['buses'] ?? []);

        $baseCard = [
            'depart_id' => $depart['id'],
            'date_label' => ucfirst($departDate->translatedFormat('l j F Y')),
            'time' => $departDate->format('H\hi'),
            'promotional_message' => ($depart['show_promotional_message'] ?? false) ? ($depart['promotional_message'] ?? null) : null,
            'is_passed' => (bool) ($depart['is_passed'] ?? false),
        ];

        if ($buses->isEmpty()) {
            return [array_merge($baseCard, [
                'key' => 'depart-'.$depart['id'],
                'bus_id' => null,
                'bus_type' => 'Bus',
                'climatise' => false,
                'price' => $depart['ticket_price'] ?? null,
                'unavailable' => $departUnavailable,
            ])];
        }

        return $buses->map(fn (array $bus) => array_merge($baseCard, [
            'key' => 'depart-'.$depart['id'].'-bus-'.$bus['id'],
            'bus_id' => $bus['id'],
            'bus_type' => $bus['name'] ?? 'Bus',
            'climatise' => (bool) ($bus['climatise'] ?? false),
            'price' => $bus['ticket_price'] ?? $depart['ticket_price'] ?? null,
            'unavailable' => $departUnavailable || ($bus['full'] ?? false) || ($bus['closed'] ?? false),
        ]))->all();
    })->values();

    // "Heures de départ" = the pickup schedule of the bus shown on the card (its own heure_departs
    // when set, otherwise the départ's — same resolution as the mobile app's per-bus times).
    $mapHeureDepart = fn ($heureDepart) => [
        'name' => $heureDepart->pointDep?->name,
        'arret_bus' => $heureDepart->pointDep?->arret_bus,
        'schedule' => $heureDepart->heureDepart?->format('H\hi'),
    ];
    $onlyRealStops = fn ($stop) => filled($stop['name']) && filled($stop['schedule']);

    $busSchedules = Bus::query()
        ->whereIn('id', $tripCards->pluck('bus_id')->filter()->unique())
        ->with(['heuresDeparts' => fn ($query) => $query->orderBy('heureDepart'), 'heuresDeparts.pointDep'])
        ->get()
        ->mapWithKeys(fn ($bus) => [
            $bus->id => $bus->heuresDeparts->map($mapHeureDepart)->filter($onlyRealStops)->values(),
        ]);

    $departFallbackSchedules = Depart::query()
        ->whereIn('id', $tripCards->pluck('depart_id')->filter()->unique())
        ->with(['heuresDeparts' => fn ($query) => $query->orderBy('heureDepart'), 'heuresDeparts.pointDep'])
        ->get()
        ->mapWithKeys(fn ($depart) => [
            $depart->id => $depart->heuresDeparts->map($mapHeureDepart)->filter($onlyRealStops)->values(),
        ]);

    $tripCards = $tripCards->map(function (array $trip) use ($busSchedules, $departFallbackSchedules) {
        $schedules = collect($busSchedules->get($trip['bus_id']) ?? []);
        if ($schedules->isEmpty()) {
            $schedules = collect($departFallbackSchedules->get($trip['depart_id']) ?? []);
        }
        $trip['schedules'] = $schedules;

        return $trip;
    });
@endphp

<x-layouts.website
    :title="$trajetLabel.' — Départs en bus | Globe One Transport'"
    :description="'Consultez les prochains départs en bus pour la caravane '.$routeDescription.' et réservez votre billet avec Globe One Transport.'"
>
    <section class="max-w-3xl mx-auto px-4 py-10 text-brand-navy">
        <nav aria-label="Fil d'Ariane" class="mb-6 text-sm">
            <a href="{{ route('website.home') }}" class="underline">Caravanes</a>
            <span aria-hidden="true"> / </span>
            <span>{{ $trajetLabel }}</span>
        </nav>

        <header class="mb-8">
            <h1 class="text-3xl font-bold">Départs — {{ $trajetLabel }}</h1>
            @if ($trajet->departure_city && $trajet->arrival_city)
                <p class="mt-1 text-lg">{{ $trajet->departure_city }} → {{ $trajet->arrival_city }}</p>
            @endif
        </header>

        {{-- Timeline of departures --}}
        <div class="relative pl-5">
            <div class="absolute left-[5px] top-3 bottom-3 w-px bg-line"></div>

            @forelse ($tripCards as $trip)
                <div class="relative {{ ! $loop->last ? 'mb-4' : '' }}">
                    <span class="absolute -left-5 top-5 w-3 h-3 rounded-full bg-brand-navy"></span>

                    <div @class([
                        'rounded-2xl border-[0.4px] border-brand-cyan bg-white p-4 shadow-[0_4px_14px_rgba(29,200,254,0.25)]',
                        'opacity-60' => $trip['unavailable'],
                    ])>
                        <div class="flex items-start gap-3">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-brand-navy flex-shrink-0 mt-0.5" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M4 16c0 1.1.9 2 2 2v1a1 1 0 0 0 2 0v-1h8v1a1 1 0 0 0 2 0v-1c1.1 0 2-.9 2-2V6c0-3.5-3.58-4-8-4s-8 .5-8 4v10zm3.5 1c-.83 0-1.5-.67-1.5-1.5S6.67 14 7.5 14s1.5.67 1.5 1.5S8.33 17 7.5 17zm9 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM18 11H6V6h12v5z"/>
                            </svg>
                            <div class="flex-1 min-w-0">
                                <p class="font-semibold text-base leading-snug break-words text-brand-navy">
                                    {{ $trip['date_label'] }}
                                </p>
                                <p class="text-sm text-muted-foreground mt-0.5">
                                    {{ $trip['bus_type'] }}
                                    @if ($trip['climatise'])
                                        · Climatisé
                                    @endif
                                    @if ($trip['unavailable'])
                                        · {{ $trip['is_passed'] ? 'Terminé' : 'Complet' }}
                                    @endif
                                </p>

                                <details class="mt-1.5 group">
                                    <summary class="flex items-center gap-1.5 text-brand-navy text-xs font-semibold cursor-pointer list-none active:text-brand-cyan">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 2"/>
                                        </svg>
                                        <span>Heures de départ</span>
                                    </summary>
                                    <ul class="mt-2 divide-y divide-line text-xs">
                                        @forelse ($trip['schedules'] as $stop)
                                            <li class="flex items-start justify-between gap-3 py-1.5">
                                                <span class="min-w-0">
                                                    <span class="text-brand-navy">{{ $stop['name'] }}</span>
                                                    @if (! empty($stop['arret_bus']))
                                                        <span class="block text-muted-foreground">{{ $stop['arret_bus'] }}</span>
                                                    @endif
                                                </span>
                                                <span class="shrink-0 font-semibold text-brand-navy">{{ $stop['schedule'] }}</span>
                                            </li>
                                        @empty
                                            <li class="py-1.5 text-muted-foreground">Départ à {{ $trip['time'] }}</li>
                                        @endforelse
                                    </ul>
                                </details>

                                @if (! empty($trip['promotional_message']))
                                    <p class="mt-1.5 text-xs text-brand-cyan">{!! $trip['promotional_message'] !!}</p>
                                @endif
                            </div>
                            <div class="text-right flex-shrink-0">
                                @if (! is_null($trip['price']))
                                    <p class="font-semibold text-lg text-brand-navy">
                                        {{ number_format($trip['price'], 0, ',', ' ') }}
                                    </p>
                                    <p class="text-muted-foreground text-xs">CFA</p>
                                @endif
                            </div>
                        </div>

                        <div class="flex items-center justify-between mt-3 pt-3 border-t border-line">
                            <a
                                href="https://wa.me/?text={{ urlencode($trajetLabel.' — '.$trip['date_label'].' : '.route('website.caravanes.show', $trajet)) }}"
                                target="_blank"
                                rel="noopener"
                                class="flex items-center gap-1.5 text-muted-foreground text-sm font-medium active:text-brand-navy"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-[#25D366]" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M12.001 2.003c-5.512 0-9.997 4.484-9.997 9.997 0 1.762.464 3.442 1.34 4.916L2 22l5.263-1.27a9.925 9.925 0 0 0 4.738 1.207h.001c5.512 0 9.997-4.485 9.997-9.997 0-5.513-4.485-9.997-9.998-9.997zm.001 18.191h-.001a8.19 8.19 0 0 1-4.174-1.148l-.3-.178-3.116.753.834-3.043-.196-.313a8.18 8.18 0 0 1-1.256-4.363c0-4.537 3.695-8.232 8.235-8.232 4.537 0 8.229 3.695 8.229 8.232 0 4.538-3.692 8.292-8.255 8.292z"/>
                                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.149-.15.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.05-.52-.099-.148-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487 3.582 1.522 3.582.97 4.38.86.792-.11 2.562-1.02 2.86-2.184.297-1.165.297-2.163.198-2.31-.099-.148-.297-.249-.594-.397z"/>
                                </svg>
                                Partager
                            </a>
                            @if ($trip['unavailable'])
                                <button
                                    type="button"
                                    disabled
                                    class="bg-brand-cyan text-brand-navy font-semibold px-6 py-2.5 rounded-full opacity-60 cursor-not-allowed"
                                >
                                    Réserver
                                </button>
                            @else
                                <a
                                    href="{{ route('website.bookings.create', array_filter(['depart' => $trip['depart_id'], 'bus_id' => $trip['bus_id']])) }}"
                                    class="bg-brand-cyan text-brand-navy font-semibold px-6 py-2.5 rounded-full active:brightness-95"
                                >
                                    Réserver
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <p class="text-center text-muted-foreground text-sm py-10">
                    Aucun départ n'est programmé pour cette caravane pour l'instant.
                </p>
            @endforelse
        </div>
    </section>
</x-layouts.website>
