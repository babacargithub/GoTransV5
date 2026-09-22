@php
    use Illuminate\Support\Carbon;

    $routeLabel = $depart->trajet->public_name ?? $depart->trajet->name;
    $departDate = Carbon::parse($depart->date)->locale('fr');
    $ticketPrice = $this->ticketPrice();
@endphp

<div class="max-w-2xl mx-auto px-4 py-10 text-brand-navy">
    <nav aria-label="Fil d'Ariane" class="mb-6 text-sm">
        <a href="{{ route('website.home') }}" class="underline">Caravanes</a>
        <span aria-hidden="true"> / </span>
        <a href="{{ route('website.caravanes.show', $depart->trajet) }}" class="underline">{{ $routeLabel }}</a>
        <span aria-hidden="true"> / </span>
        <span>Réservation</span>
    </nav>

    <header class="mb-8">
        <h1 class="text-3xl font-bold">Réserver — {{ $routeLabel }}</h1>
        <p class="mt-1 text-lg">{{ ucfirst($departDate->translatedFormat('l j F Y')) }} à {{ $departDate->format('H\hi') }}</p>
        @if ($ticketPrice > 0)
            <p class="mt-1 text-sm text-muted-foreground">
                Prix du billet : <span class="font-semibold text-brand-navy">{{ number_format($ticketPrice, 0, ',', ' ') }} CFA</span> par personne
            </p>
        @endif
    </header>

    @unless ($this->bookingIsOpen())
        <p class="rounded-2xl border border-brand-cyan bg-brand-cyan/10 px-4 py-6">
            Les réservations pour ce départ sont closes.
            <a href="{{ route('website.caravanes.show', $depart->trajet) }}" class="underline">Voir les autres départs</a>.
        </p>
    @else
        @if ($formError)
            <div class="mb-6 rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
                {{ $formError }}
            </div>
        @endif

        <form wire:submit="reviewBooking" class="space-y-8">
            {{-- Passengers count --}}
            <div>
                <label for="passengers-count" class="block text-sm font-semibold">Nombre de passagers</label>
                <select
                    id="passengers-count"
                    wire:model.live="passengersCount"
                    class="mt-1 w-full rounded-xl border border-brand-navy/20 bg-white px-3 py-2.5 focus:border-brand-cyan focus:outline-none focus:ring-1 focus:ring-brand-cyan"
                >
                    <option value="">Choisir…</option>
                    @for ($count = 1; $count <= \App\Livewire\Website\StudentBooking::MAX_PASSENGERS; $count++)
                        <option value="{{ $count }}">{{ $count }} {{ $count > 1 ? 'personnes' : 'personne' }}</option>
                    @endfor
                </select>
            </div>

            {{-- Passenger rows --}}
            @if (count($passengers) > 0)
                <div class="space-y-4">
                    @foreach ($passengers as $index => $passenger)
                        <fieldset class="rounded-2xl border border-brand-cyan bg-white p-4 shadow-[0_4px_14px_rgba(29,200,254,0.18)]">
                            <legend class="px-2 text-sm font-semibold">Passager {{ $index + 1 }}</legend>

                            <div class="mt-2 space-y-3">
                                <div>
                                    <label for="passenger-{{ $index }}-name" class="block text-xs font-medium">Nom complet</label>
                                    <input
                                        id="passenger-{{ $index }}-name"
                                        type="text"
                                        autocomplete="name"
                                        wire:model.live.debounce.500ms="passengers.{{ $index }}.full_name"
                                        placeholder="Prénom Nom"
                                        class="mt-1 w-full rounded-xl border border-brand-navy/20 bg-white px-3 py-2.5 focus:border-brand-cyan focus:outline-none focus:ring-1 focus:ring-brand-cyan @error('passengers.'.$index.'.full_name') border-red-400 @enderror"
                                    >
                                    @error('passengers.'.$index.'.full_name')
                                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div>
                                    <label for="passenger-{{ $index }}-phone" class="block text-xs font-medium">Numéro de téléphone</label>
                                    <input
                                        id="passenger-{{ $index }}-phone"
                                        type="tel"
                                        inputmode="numeric"
                                        maxlength="9"
                                        autocomplete="tel-national"
                                        wire:model.live.debounce.500ms="passengers.{{ $index }}.phone_number"
                                        placeholder="77XXXXXXX"
                                        class="mt-1 w-full rounded-xl border border-brand-navy/20 bg-white px-3 py-2.5 focus:border-brand-cyan focus:outline-none focus:ring-1 focus:ring-brand-cyan @error('passengers.'.$index.'.phone_number') border-red-400 @enderror"
                                    >
                                    @error('passengers.'.$index.'.phone_number')
                                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div>
                                    <label for="passenger-{{ $index }}-point-dep" class="block text-xs font-medium">Point de départ</label>
                                    <select
                                        id="passenger-{{ $index }}-point-dep"
                                        wire:model.live="passengers.{{ $index }}.point_dep_id"
                                        class="mt-1 w-full rounded-xl border border-brand-navy/20 bg-white px-3 py-2.5 focus:border-brand-cyan focus:outline-none focus:ring-1 focus:ring-brand-cyan @error('passengers.'.$index.'.point_dep_id') border-red-400 @enderror"
                                    >
                                        <option value="">Choisir…</option>
                                        @foreach ($this->pointDepartOptions as $pointDepart)
                                            <option value="{{ $pointDepart['id'] }}">{{ $pointDepart['name'] }}</option>
                                        @endforeach
                                    </select>
                                    @error('passengers.'.$index.'.point_dep_id')
                                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>
                        </fieldset>
                    @endforeach
                </div>

                {{-- Payment method --}}
                <div>
                    <p class="text-sm font-semibold">Moyen de paiement</p>
                    <div class="mt-2 grid grid-cols-2 gap-3">
                        <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-brand-navy/20 bg-white px-3 py-2.5 has-[:checked]:border-brand-cyan has-[:checked]:ring-1 has-[:checked]:ring-brand-cyan">
                            <input type="radio" value="wave" wire:model.live="paymentMethod" class="accent-brand-cyan">
                            <img src="{{ asset('images/payments/wave.png') }}" alt="" class="h-6 w-6 rounded-full object-contain">
                            <span class="font-medium">Wave</span>
                        </label>
                        <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-brand-navy/20 bg-white px-3 py-2.5 has-[:checked]:border-brand-cyan has-[:checked]:ring-1 has-[:checked]:ring-brand-cyan">
                            <input type="radio" value="om" wire:model.live="paymentMethod" class="accent-brand-cyan">
                            <img src="{{ asset('images/payments/orange-money.png') }}" alt="" class="h-6 w-6 rounded-full object-contain">
                            <span class="font-medium">Orange Money</span>
                        </label>
                    </div>
                    @error('paymentMethod')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror

                    @if ($paymentMethod === 'om')
                        <div class="mt-3">
                            <label for="om-number" class="block text-xs font-medium">Numéro Orange Money qui va payer</label>
                            <input
                                id="om-number"
                                type="tel"
                                inputmode="numeric"
                                maxlength="9"
                                wire:model.live.debounce.500ms="orangeMoneyNumber"
                                placeholder="77XXXXXXX"
                                class="mt-1 w-full rounded-xl border border-brand-navy/20 bg-white px-3 py-2.5 focus:border-brand-cyan focus:outline-none focus:ring-1 focus:ring-brand-cyan @error('orangeMoneyNumber') border-red-400 @enderror"
                            >
                            @error('orangeMoneyNumber')
                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    @endif
                </div>

                @if ($formError)
                    <div class="rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
                        {{ $formError }}
                    </div>
                @endif

                <button
                    type="submit"
                    class="w-full rounded-full bg-brand-cyan px-6 py-3 font-semibold text-brand-navy active:brightness-95 disabled:opacity-60"
                    wire:loading.attr="disabled"
                >
                    Vérifier ma réservation
                </button>
            @endif
        </form>
    @endif

    {{-- Summary / confirmation modal --}}
    @if ($showSummary)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-brand-navy/50 p-4 sm:items-center" wire:key="booking-summary">
            <div class="w-full max-w-lg rounded-3xl bg-white p-6 shadow-xl">
                @unless ($showNonRefundableWarning)
                    <h2 class="text-lg font-bold">Résumé de votre réservation</h2>
                    <ul class="mt-4 divide-y divide-line">
                        @foreach ($passengers as $index => $passenger)
                            <li class="py-3">
                                <p class="font-semibold">{{ $index + 1 }}. {{ $passenger['full_name'] }}</p>
                                <p class="text-sm text-muted-foreground">Tél : {{ $passenger['phone_number'] }}</p>
                                <p class="text-sm">
                                    {{ collect($this->pointDepartOptions)->firstWhere('id', (int) $passenger['point_dep_id'])['name'] ?? '—' }}
                                    <span aria-hidden="true">→</span>
                                    {{ $this->guessedDestinationName() ?? '—' }}
                                </p>
                            </li>
                        @endforeach
                    </ul>

                    @if ($ticketPrice > 0)
                        <p class="mt-4 flex items-center justify-between border-t border-line pt-3 font-semibold">
                            <span>Total ({{ count($passengers) }} billet{{ count($passengers) > 1 ? 's' : '' }})</span>
                            <span>{{ number_format($ticketPrice * count($passengers), 0, ',', ' ') }} CFA</span>
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">Le montant exact (remises éventuelles) est confirmé au moment du paiement.</p>
                    @endif

                    <div class="mt-6 flex gap-3">
                        <button type="button" wire:click="closeSummary" class="flex-1 rounded-full border border-brand-navy/20 px-4 py-2.5 font-medium">
                            Retour
                        </button>
                        <button type="button" wire:click="acknowledgeSummary" class="flex-1 rounded-full bg-brand-cyan px-4 py-2.5 font-semibold text-brand-navy">
                            Valider
                        </button>
                    </div>
                @else
                    <h2 class="text-lg font-bold text-red-700">Avant de confirmer</h2>
                    <p class="mt-4 text-sm">{{ $this->nonRefundableWarning() }}</p>

                    <div class="mt-6 flex gap-3">
                        <button type="button" wire:click="$set('showNonRefundableWarning', false)" class="flex-1 rounded-full border border-brand-navy/20 px-4 py-2.5 font-medium">
                            Retour
                        </button>
                        <button
                            type="button"
                            wire:click="confirmBooking"
                            wire:loading.attr="disabled"
                            wire:target="confirmBooking"
                            class="flex-1 rounded-full bg-brand-cyan px-4 py-2.5 font-semibold text-brand-navy disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="confirmBooking">Je confirme et je paie</span>
                            <span wire:loading wire:target="confirmBooking">Traitement…</span>
                        </button>
                    </div>
                @endunless
            </div>
        </div>
    @endif
</div>
