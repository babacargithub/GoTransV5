<div class="min-h-screen bg-gradient-to-br from-slate-50 to-slate-100 py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-6xl mx-auto">
        <!-- Header -->
        <div class="text-center mb-12">
            <h1 class="text-4xl font-bold text-blue-900 mb-2">Choisissez Votre Trajet</h1>
            <p class="text-lg text-gray-600">Sélectionnez votre destination et commencez votre réservation</p>
        </div>

        <!-- Trajets Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
            @forelse($trajets as $trajet)
                <div
                    wire:click="selectTrajet({{ $trajet->id }})"
                    class="bg-white rounded-lg shadow-md hover:shadow-xl transition-all duration-300 cursor-pointer overflow-hidden border-2 {{ $selectedTrajet == $trajet->id ? 'border-orange-500 bg-orange-50' : 'border-transparent hover:border-blue-900/20' }}"
                >
                    <!-- Card Header with Gradient -->
                    <div class="bg-gradient-to-r from-blue-900 to-blue-700 text-white p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="text-2xl font-bold">{{ $trajet->name }}</h3>
                                <p class="text-blue-100 text-sm mt-1">{{ $trajet->departure_city }} ↔ {{ $trajet->arrival_city }}</p>
                            </div>
                            <div class="text-4xl">🚌</div>
                        </div>
                    </div>

                    <!-- Card Body -->
                    <div class="p-6 space-y-4">
                        <!-- Departure -->
                        <div class="flex items-start gap-3 pb-4 border-b border-gray-200">
                            <span class="text-2xl">📍</span>
                            <div>
                                <p class="text-xs text-gray-500 uppercase tracking-wide font-semibold">Départ</p>
                                <p class="text-lg font-bold text-gray-900">{{ $trajet->departure_city }}</p>
                            </div>
                        </div>

                        <!-- Distance -->
                        @if($trajet->length)
                            <div class="flex items-center justify-center py-2">
                                <div class="flex-1 h-px bg-gradient-to-r from-transparent to-gray-300"></div>
                                <span class="px-3 text-sm text-gray-600 font-medium">{{ number_format($trajet->length) }} km</span>
                                <div class="flex-1 h-px bg-gradient-to-l from-transparent to-gray-300"></div>
                            </div>
                        @endif

                        <!-- Arrival -->
                        <div class="flex items-start gap-3 pt-4 border-t border-gray-200">
                            <span class="text-2xl">🎯</span>
                            <div>
                                <p class="text-xs text-gray-500 uppercase tracking-wide font-semibold">Arrivée</p>
                                <p class="text-lg font-bold text-gray-900">{{ $trajet->arrival_city }}</p>
                            </div>
                        </div>

                        <!-- Selection Badge -->
                        @if($selectedTrajet == $trajet->id)
                            <div class="mt-4 p-3 bg-green-50 border-l-4 border-green-500 rounded-r">
                                <p class="text-sm font-semibold text-green-700">✓ Trajet Sélectionné</p>
                            </div>
                        @endif
                    </div>

                    <!-- Card Footer -->
                    <div class="bg-gray-50 px-6 py-3 border-t border-gray-100 text-center">
                        <p class="text-xs text-gray-600">{{ $selectedTrajet == $trajet->id ? 'Cliquez pour modifier' : 'Cliquez pour sélectionner' }}</p>
                    </div>
                </div>
            @empty
                <div class="col-span-full">
                    <div class="bg-yellow-50 border-l-4 border-yellow-400 p-6 rounded-r-lg">
                        <p class="text-yellow-800 font-semibold">Aucun trajet disponible pour le moment</p>
                    </div>
                </div>
            @endforelse
        </div>

        <!-- Action Button -->
        @if($trajets->count() > 0)
            <div class="flex justify-center">
                <button
                    wire:click="handleVoyager"
                    class="bg-gradient-to-r from-orange-500 to-orange-600 hover:from-orange-600 hover:to-orange-700 text-white font-bold py-4 px-12 rounded-lg shadow-lg hover:shadow-xl transition-all duration-300 transform hover:scale-105 text-lg flex items-center gap-3 {{ !$selectedTrajet ? 'opacity-50 cursor-not-allowed' : '' }}"
                    {{ !$selectedTrajet ? 'disabled' : '' }}
                >
                    <span>🚀</span>
                    <span>Voyager</span>
                </button>
            </div>
        @endif

        <!-- Flash Messages -->
        @if(session()->has('message'))
            <div class="mt-6 bg-green-50 border-l-4 border-green-500 p-4 rounded-r-lg">
                <p class="text-green-700 font-semibold">{{ session('message') }}</p>
            </div>
        @endif

        @if(session()->has('error'))
            <div class="mt-6 bg-red-50 border-l-4 border-red-500 p-4 rounded-r-lg">
                <p class="text-red-700 font-semibold">{{ session('error') }}</p>
            </div>
        @endif
    </div>
</div>
