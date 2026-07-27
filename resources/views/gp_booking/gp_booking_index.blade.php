<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Globe One Transport | Réservations</title>
    <meta name="description" content="Réservez votre transport direct avec Globe One Transport. Trajets fiables vers Saint-Louis et Dakar.">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-background text-foreground">

<!-- Header -->
<header class="bg-primary text-primary-foreground px-4 py-12">
    <div class="max-w-3xl mx-auto">
        <h1 class="text-5xl mb-2 font-bold">Globe One Transport</h1>
        <p class="text-lg opacity-95 mb-2">Trajets directs et fiables</p>
        <p class="text-base opacity-80">Réservez votre déplacement en toute confiance</p>
    </div>
</header>

<main>

    <!-- Booking Section -->
    <section class="px-4 py-12 min-h-screen flex flex-col" x-data="bookingForm()">
        <div class="max-w-4xl mx-auto w-full flex-1 flex flex-col">

            <!-- Section Header -->
            <div class="mb-8">
                <h2 class="text-3xl mb-2" x-text="selectedTrajet ? 'Choisissez votre départ' : 'Choisissez votre trajet'"></h2>
                <p class="text-muted-foreground" x-text="selectedTrajet ? 'Sélectionnez une date et heure de départ' : 'Sélectionnez votre destination pour commencer'"></p>
            </div>

            <!-- Back Button (shown when departs are displayed) -->
            <template x-if="selectedTrajet">
                <button @click="selectedTrajet = null" class="mb-6 text-primary hover:text-accent transition-colors flex items-center gap-2">
                    <span>←</span>
                    <span>Retour aux trajets</span>
                </button>
            </template>

            <!-- Routes List -->
            <template x-if="!selectedTrajet">
                <div class="flex flex-col gap-2 mb-8 flex-1">
                @forelse($trajets as $trajet /** @var Trajet $trajet */)
                    <button
                        @click="selectTrajet({{ $trajet->id }}, '{{ $trajet->public_name ?? $trajet->name }}')"
                        class="card-interactive p-4 text-left hover:bg-secondary transition-colors"
                    >
                        <div class="flex gap-4 items-center justify-between">
                            <!-- Journey Details -->
                            <div class="flex-1 min-w-0">
                                <h3 class="text-primary font-semibold mb-1 text-base">
                                    {{ $trajet->public_name ?? $trajet->name }}
                                </h3>

                                <div class="flex items-center gap-3 text-sm flex-wrap">
                                    <div>
                                        <div class="label-uppercase text-xs mb-0.5">Départ</div>
                                        <div class="font-medium">{{ $trajet->departure_city }}</div>
                                    </div>

                                    <div class="text-muted-foreground">→</div>

                                    <div>
                                        <div class="label-uppercase text-xs mb-0.5">Arrivée</div>
                                        <div class="font-medium">{{ $trajet->arrival_city }}</div>
                                    </div>

                                    @if($trajet->length)
                                        <div class="text-muted-foreground ml-auto text-right">
                                            <div class="label-uppercase text-xs mb-0.5">Distance</div>
                                            <div class="font-medium">{{ number_format($trajet->length) }} km</div>
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <!-- Arrow Indicator -->
                            <div class="flex-shrink-0 text-accent text-xl font-bold">→</div>
                        </div>
                    </button>
                @empty
                    <div class="p-6 bg-[oklch(97%_0.02_40)] border border-accent rounded-lg text-center">
                        <p class="font-medium">Aucun trajet disponible pour le moment</p>
                    </div>
                @endforelse
                </div>
            </template>

            <!-- Departs List -->
            <template x-if="selectedTrajet">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 flex-1">
                    <template x-for="depart in selectedTrajetDeparts" :key="depart.id">
                        <button @click="selectDepart(depart.id, depart.name)"
                                class="card-interactive p-5 text-left hover:shadow-md"
                                :class="depart.closed ? 'opacity-60 cursor-not-allowed' : ''"
                                :disabled="depart.closed">
                            <div class="space-y-3">
                                <div>
                                    <div class="label-uppercase text-xs mb-1">Date et Heure</div>
                                    <h4 class="text-lg font-semibold text-primary" x-text="formatDate(depart.date)"></h4>
                                </div>

                                <template x-if="depart.closed">
                                    <div class="pt-2 border-t border-border">
                                        <span class="text-xs font-semibold text-error">Complet</span>
                                    </div>
                                </template>
                            </div>
                        </button>
                    </template>

                    <template x-if="selectedTrajetDeparts.length === 0">
                        <div class="col-span-full p-8 bg-[oklch(97%_0.02_40)] border border-accent rounded-lg text-center">
                            <p class="font-medium">Aucun départ disponible pour ce trajet</p>
                        </div>
                    </template>
                </div>
            </template>

        </div>
    </section>
</main>

<!-- Footer -->
<footer class="bg-primary text-primary-foreground px-4 py-8 text-center text-sm">
    <div class="max-w-3xl mx-auto">
        <p class="mb-2 opacity-90">&copy; 2026 Globe One Transport. Tous droits réservés.</p>
        <p class="opacity-75">Service de transport fiable et transparent</p>
    </div>
</footer>

<script>
function bookingForm() {
    return {
        selectedTrajet: null,
        selectedTrajetDeparts: [],
        trajetsData: @json($trajets),

        selectTrajet(id, name) {
            this.selectedTrajet = id;
            // Find departs for this trajet
            const trajet = this.trajetsData.find(t => t.id === id);
            this.selectedTrajetDeparts = trajet?.departs || [];
            console.log('Trajet sélectionné:', id, name);
            console.log('Départs:', this.selectedTrajetDeparts);
        },

        selectDepart(departId, departName) {
            console.log('Départ sélectionné:', departId, departName);
            // TODO: Redirect to booking form with trajet and depart IDs
            window.location.href = `/booking?trajet=${this.selectedTrajet}&depart=${departId}`;
        },

        formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('fr-FR', {
                weekday: 'long',
                day: 'numeric',
                month: 'long',
                hour: '2-digit',
                minute: '2-digit'
            });
        }
    };
}
</script>

</body>
</html>
