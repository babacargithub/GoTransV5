@php
    use Illuminate\Support\Carbon;

    $bookings = $this->bookings;
    $firstBooking = $bookings->first();
    $groupIsPaid = $bookings->every(fn ($booking) => $booking->hasTicket());
    $departLabel = $firstBooking?->depart?->name;
@endphp

<div class="max-w-2xl mx-auto px-4 py-10 text-brand-navy">
    <h1 class="text-3xl font-bold">Ma réservation</h1>
    @if ($departLabel)
        <p class="mt-1 text-lg">{{ $departLabel }}</p>
    @endif

    @if (session('status'))
        <div class="mt-6 rounded-xl border border-brand-cyan bg-brand-cyan/10 px-4 py-3 text-sm" role="status">
            {{ session('status') }}
        </div>
    @endif

    <p @class([
        'mt-6 inline-flex items-center gap-2 rounded-full px-4 py-1.5 text-sm font-semibold',
        'bg-green-100 text-green-800' => $groupIsPaid,
        'bg-amber-100 text-amber-800' => ! $groupIsPaid,
    ])>
        {{ $groupIsPaid ? 'Réservation payée' : 'En attente de paiement' }}
    </p>

    <ul class="mt-6 space-y-4">
        @foreach ($bookings as $index => $booking)
            <li class="rounded-2xl border border-brand-cyan bg-white p-4 shadow-[0_4px_14px_rgba(29,200,254,0.18)]">
                <p class="font-semibold">{{ $index + 1 }}. {{ $booking->customer->full_name }}</p>
                <p class="text-sm text-muted-foreground">Tél : {{ $booking->customer->phone_number }}</p>
                <p class="mt-1 text-sm">
                    {{ $booking->point_dep?->name ?? '—' }}
                    <span aria-hidden="true">→</span>
                    {{ $booking->destination?->name ?? '—' }}
                </p>
                @if ($booking->hasTicket())
                    <p class="mt-1 text-sm">
                        Bus : <span class="font-medium">{{ $booking->bus?->full_name ?? 'Non précisé' }}</span>
                        @if ($booking->seat?->number)
                            · Siège <span class="font-medium">{{ $booking->seat->number }}</span>
                        @endif
                    </p>
                @endif
            </li>
        @endforeach
    </ul>
</div>
