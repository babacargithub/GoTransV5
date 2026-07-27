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
        <div class="max-w-3xl mx-auto w-full flex-1 flex flex-col">

            <!-- Section Header -->
            <div class="mb-8">
                <h2 class="text-3xl mb-2">Choisissez votre trajet</h2>
                <p class="text-muted-foreground">Sélectionnez votre destination pour commencer</p>
            </div>

            <!-- Routes List -->
            <div class="flex flex-col gap-4 mb-8 flex-1">
                @forelse($trajets as $trajet /** @var Trajet $trajet */)
                    <button
                        @click="selectTrajet({{ $trajet->id }}, '{{ $trajet->public_name ?? $trajet->name }}')"
                        class="card-interactive p-6 text-left"
                        :class="selectedTrajet === {{ $trajet->id }} ? 'card-selected' : ''"
                    >
                        <div class="flex gap-6 items-center">
                            <!-- Journey Details -->
                            <div class="flex-1 min-w-0">
                                <h3 class="text-primary mb-3">
                                    {{ $trajet->public_name ?? $trajet->name }}
                                </h3>

                                <div class="flex items-center gap-4 flex-wrap">
                                    <div>
                                        <div class="label-uppercase mb-1">Départ</div>
                                        <div class="text-lg font-medium">{{ $trajet->departure_city }}</div>
                                    </div>

                                    <div class="text-muted-foreground">→</div>

                                    <div>
                                        <div class="label-uppercase mb-1">Arrivée</div>
                                        <div class="text-lg font-medium">{{ $trajet->arrival_city }}</div>
                                    </div>

                                    @if($trajet->length)
                                        <div class="ml-auto text-right flex flex-col items-end">
                                            <div class="label-uppercase mb-1">Distance</div>
                                            <div class="text-lg font-medium">{{ number_format($trajet->length) }} km</div>
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <!-- Selection Indicator -->
                            <div class="flex items-center justify-center w-8 h-8 border-2 border-accent rounded-full flex-shrink-0"
                                 :class="selectedTrajet === {{ $trajet->id }} ? 'bg-accent' : ''">
                                <span x-show="selectedTrajet === {{ $trajet->id }}" class="text-accent-foreground font-bold text-xl">✓</span>
                            </div>
                        </div>
                    </button>
                @empty
                    <div class="p-8 bg-[oklch(97%_0.02_40)] border border-accent rounded-lg text-center">
                        <p class="font-medium">Aucun trajet disponible pour le moment</p>
                    </div>
                @endforelse
            </div>

            <!-- Action Section -->
            @if($trajets->count() > 0)
                <div class="flex justify-center">
                    <button
                        @click="handleVoyager()"
                        class="btn-primary"
                        :disabled="!selectedTrajet"
                    >
                        Continuer
                    </button>
                </div>
            @endif

            <!-- Messages -->
            <template x-if="message">
                <div class="mt-6 p-4 bg-success/10 border border-success rounded-lg text-center">
                    <p class="font-medium text-success" x-text="message"></p>
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
        selectedTrajetName: '',
        message: '',

        selectTrajet(id, name) {
            this.selectedTrajet = id;
            this.selectedTrajetName = name;
            this.message = '';
        },

        handleVoyager() {
            if (!this.selectedTrajet) {
                this.message = 'Sélectionnez un trajet pour continuer';
                setTimeout(() => { this.message = ''; }, 3000);
                return;
            }

            this.message = `Trajet sélectionné: ${this.selectedTrajetName}`;
            console.log('Trajet sélectionné:', this.selectedTrajet, this.selectedTrajetName);
            // TODO: Redirect to booking form
        }
    };
}
</script>

</body>
</html>
