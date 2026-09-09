@php
    use Illuminate\Support\Carbon;

    $bookings = $this->bookings;
    $firstBooking = $bookings->first();
    $groupIsPaid = $this->groupIsPaid;
    $departHasPassed = $this->departHasPassed;
    $depart = $firstBooking?->depart;
    $departDate = $depart?->date ? Carbon::parse($depart->date)->locale('fr') : null;
@endphp

<div class="max-w-2xl mx-auto px-4 py-10 text-brand-navy">
    <h1 class="text-3xl font-bold">Ma réservation</h1>
    @if ($depart)
        <p class="mt-1 text-lg">{{ $depart->name }}</p>
        @if ($departDate)
            <p class="text-sm text-muted-foreground">{{ ucfirst($departDate->translatedFormat('l j F Y')) }} à {{ $departDate->format('H\hi') }}</p>
        @endif
    @endif

    @if (session('status'))
        <div class="mt-6 rounded-xl border border-brand-cyan bg-brand-cyan/10 px-4 py-3 text-sm" role="status">
            {{ session('status') }}
        </div>
    @endif

    <p @class([
        'mt-6 inline-flex items-center gap-2 rounded-full px-4 py-1.5 text-sm font-semibold',
        'bg-green-100 text-green-800' => $groupIsPaid,
        'bg-red-100 text-red-800' => ! $groupIsPaid && $departHasPassed,
        'bg-amber-100 text-amber-800' => ! $groupIsPaid && ! $departHasPassed,
    ])>
        @if ($groupIsPaid)
            Réservation payée
        @elseif ($departHasPassed)
            Départ passé — paiement impossible
        @else
            En attente de paiement
        @endif
    </p>

    {{-- Passenger cards --}}
    <ul class="mt-6 space-y-4">
        @foreach ($bookings as $index => $booking)
            @php($pickupTime = $this->pickupTimeFor($booking))
            <li class="rounded-2xl border border-brand-cyan bg-white p-4 shadow-[0_4px_14px_rgba(29,200,254,0.18)]">
                <p class="font-semibold">{{ $index + 1 }}. {{ $booking->customer->full_name }}</p>
                <p class="text-sm text-muted-foreground">Tél : {{ $booking->customer->phone_number }}</p>

                <div class="mt-2 flex items-stretch gap-2 text-sm">
                    <div class="flex-1 rounded-lg bg-brand-cyan/10 px-3 py-2">
                        <span class="block text-xs text-muted-foreground">Départ</span>
                        <span class="font-medium">{{ $booking->point_dep?->name ?? '—' }}</span>
                        @if ($booking->point_dep?->arret_bus)
                            <span class="block text-xs text-muted-foreground">{{ $booking->point_dep->arret_bus }}</span>
                        @endif
                    </div>
                    <div class="flex items-center" aria-hidden="true">→</div>
                    <div class="flex-1 rounded-lg bg-brand-cyan/10 px-3 py-2">
                        <span class="block text-xs text-muted-foreground">Arrivée</span>
                        <span class="font-medium">{{ $booking->destination?->name ?? '—' }}</span>
                    </div>
                </div>

                @if ($booking->hasTicket())
                    <div class="mt-3 grid grid-cols-3 gap-2 text-center text-sm">
                        <div class="rounded-lg bg-brand-cyan/10 px-2 py-2">
                            <span class="block text-xs text-muted-foreground">Bus</span>
                            <span class="font-medium">{{ $booking->bus?->name ?? 'Non précisé' }}</span>
                        </div>
                        <div class="rounded-lg bg-brand-cyan/10 px-2 py-2">
                            <span class="block text-xs text-muted-foreground">Siège</span>
                            <span class="font-medium">{{ $booking->seat?->number ?? 'Non précisé' }}</span>
                        </div>
                        <div class="rounded-lg bg-brand-cyan/10 px-2 py-2">
                            <span class="block text-xs text-muted-foreground">Heure RV</span>
                            <span class="font-medium">{{ $pickupTime ?? '—' }}</span>
                        </div>
                    </div>
                    @php($agentNumber = $this->agentNumberFor($booking))
                    @if ($agentNumber)
                        <p class="mt-2 text-sm">
                            Agent du bus à contacter :
                            <a href="tel:{{ $agentNumber }}" class="font-medium underline">{{ $agentNumber }}</a>
                        </p>
                    @endif
                @endif
            </li>
        @endforeach
    </ul>

    {{-- Actions --}}
    <div class="mt-8">
        @if ($groupIsPaid)
            <a
                href="{{ route('tickets.group.show', $this->groupId) }}"
                class="inline-flex w-full items-center justify-center rounded-full bg-brand-cyan px-6 py-3 font-semibold text-brand-navy active:brightness-95 sm:w-auto"
            >
                Télécharger mon ticket
            </a>
        @elseif (! $departHasPassed)
            <div class="rounded-2xl border border-brand-navy/15 bg-white p-4">
                <p class="font-semibold">Payer mes billets</p>
                <p class="mt-1 text-sm text-red-700">{{ $this->nonRefundableReminder }}</p>

                @if ($paymentError)
                    <p class="mt-3 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">
                        {{ $paymentError }}
                    </p>
                @endif

                <div class="mt-4 space-y-3">
                    <button
                        type="button"
                        wire:click="payWithWave"
                        wire:loading.attr="disabled"
                        wire:target="payWithWave"
                        class="w-full rounded-full bg-brand-cyan px-6 py-3 font-semibold text-brand-navy active:brightness-95 disabled:opacity-60"
                    >
                        <span wire:loading.remove wire:target="payWithWave">Payer par Wave</span>
                        <span wire:loading wire:target="payWithWave">Initialisation…</span>
                    </button>

                    <div class="rounded-xl border border-brand-navy/15 p-3">
                        <label for="om-number" class="block text-xs font-medium">Numéro Orange Money qui va payer</label>
                        <input
                            id="om-number"
                            type="tel"
                            inputmode="numeric"
                            maxlength="9"
                            wire:model="orangeMoneyNumber"
                            placeholder="77XXXXXXX"
                            class="mt-1 w-full rounded-lg border border-brand-navy/20 bg-white px-3 py-2 focus:border-brand-cyan focus:outline-none focus:ring-1 focus:ring-brand-cyan @error('orangeMoneyNumber') border-red-400 @enderror"
                        >
                        @error('orangeMoneyNumber')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                        <button
                            type="button"
                            wire:click="payWithOrangeMoney"
                            wire:loading.attr="disabled"
                            wire:target="payWithOrangeMoney"
                            class="mt-2 w-full rounded-full border border-brand-navy/20 px-6 py-2.5 font-semibold active:brightness-95 disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="payWithOrangeMoney">Payer par Orange Money</span>
                            <span wire:loading wire:target="payWithOrangeMoney">Initialisation…</span>
                        </button>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
