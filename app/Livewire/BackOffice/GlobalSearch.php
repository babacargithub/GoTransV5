<?php

namespace App\Livewire\BackOffice;

use App\Livewire\BackOffice\Concerns\ManagesBookingActions;
use App\Models\Booking;
use App\Models\Customer;
use App\Rules\PhoneNumber;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Header quick-search.
 *
 * A collapsed magnifying-glass button in the back-office header that expands into
 * an input. The eventual goal is to look up a customer, a payment or a booking;
 * this first pass searches a customer by phone number. When the typed value is a
 * valid Senegalese mobile number the lookup fires automatically. A hit opens a
 * result dialog with the customer's bookings split into current / past; a miss
 * shows a red "Aucun résultat" toast and no dialog.
 *
 * The booking list uses the same filter as the legacy
 * CustomerController@findByPhoneNumber (every booking of the customer whose
 * départ is not cancelled). Every booking row action (annuler / transférer /
 * modifier / détails) is the shared {@see ManagesBookingActions} behaviour,
 * identical to the bus passengers page.
 */
class GlobalSearch extends Component
{
    use ManagesBookingActions;

    public bool $showSearchInput = false;

    public string $searchQuery = '';

    public ?string $searchValidationMessage = null;

    public bool $showResultDialog = false;

    public ?int $foundCustomerId = null;

    /**
     * Active tab in the result dialog: 'reservations' | 'past' | 'payments'.
     */
    public string $activeResultTab = 'reservations';

    public function toggleSearchInput(): void
    {
        $this->showSearchInput = ! $this->showSearchInput;
        $this->searchValidationMessage = null;

        if (! $this->showSearchInput) {
            $this->searchQuery = '';
        }
    }

    /**
     * Fire the search as soon as the typed value looks like a phone number.
     */
    public function updatedSearchQuery(): void
    {
        $this->searchValidationMessage = null;

        if (preg_match('/^(77|78|76|70|75|71)[0-9]{7}$/', trim($this->searchQuery))) {
            $this->search();
        }
    }

    /**
     * Look the customer up by phone number.
     */
    public function search(): void
    {
        $phoneNumber = trim($this->searchQuery);

        $validator = validator(
            ['phoneNumber' => $phoneNumber],
            ['phoneNumber' => [new PhoneNumber]],
        );

        if ($validator->fails()) {
            $this->searchValidationMessage = $validator->errors()->first('phoneNumber');

            return;
        }

        $customer = Customer::query()->where('phone_number', $phoneNumber)->first();

        if ($customer === null) {
            $this->foundCustomerId = null;
            $this->showResultDialog = false;
            $this->dispatch('global-search-no-result', message: 'Aucun résultat pour le '.$phoneNumber);

            return;
        }

        $this->foundCustomerId = $customer->id;
        $this->activeResultTab = 'reservations';
        $this->resetFlashMessages();
        $this->showResultDialog = true;
    }

    public function closeResultDialog(): void
    {
        $this->showResultDialog = false;
        $this->foundCustomerId = null;
        $this->resetFlashMessages();
    }

    #[Computed]
    public function foundCustomer(): ?Customer
    {
        return $this->foundCustomerId !== null
            ? Customer::query()->find($this->foundCustomerId)
            : null;
    }

    /**
     * @return array{fullName: string, phoneNumber: string, createdAtLabel: ?string, totalBookings: int}|null
     */
    #[Computed]
    public function customerHeader(): ?array
    {
        $customer = $this->foundCustomer;

        if ($customer === null) {
            return null;
        }

        return [
            'fullName' => normalize_passenger_display_name($customer->full_name),
            'phoneNumber' => (string) $customer->phone_number,
            'createdAtLabel' => $this->frenchDateTime($customer->created_at),
            'totalBookings' => $this->customerBookingsQuery()->count(),
        ];
    }

    /**
     * Current bookings of the found customer: an upcoming départ, not cancelled.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function currentBookingRows(): array
    {
        return $this->bookingRowsForScope(past: false);
    }

    /**
     * Past bookings of the found customer: a passed départ OR a cancelled
     * booking (deleted_at not null), whichever the départ date.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function pastBookingRows(): array
    {
        return $this->bookingRowsForScope(past: true);
    }

    /**
     * Every booking of the found customer, cancelled ones (soft-deleted)
     * included, on a départ that is not itself cancelled — the same départ
     * filter as CustomerController@findByPhoneNumber.
     */
    private function customerBookingsQuery(): Builder
    {
        return Booking::query()
            ->withTrashed()
            ->where('customer_id', $this->foundCustomerId)
            ->whereDoesntHave('depart', function ($query): void {
                $query->withoutGlobalScope('notCanceled')->where('canceled', true);
            });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function bookingRowsForScope(bool $past): array
    {
        if ($this->foundCustomerId === null) {
            return [];
        }

        return $this->customerBookingsQuery()
            ->with(['seat.seat', 'ticket', 'depart.trajet', 'bus', 'point_dep', 'destination', 'customer'])
            ->get()
            ->filter(function (Booking $booking) use ($past): bool {
                $isPastBooking = $booking->trashed() || ($booking->depart?->isPassed() ?? true);

                return $isPastBooking === $past;
            })
            ->sortByDesc('id')
            ->map(fn (Booking $booking): array => $this->mapBookingRow($booking))
            ->values()
            ->all();
    }

    /**
     * The row shape the shared booking partials expect (a subset of
     * BookingResource, without the throw-prone formatted_schedule accessor).
     *
     * @return array<string, mixed>
     */
    private function mapBookingRow(Booking $booking): array
    {
        return [
            'id' => $booking->id,
            'seatNumber' => $booking->seat?->seat?->number,
            'hasTicket' => $booking->has_ticket,
            'paymentMethod' => $booking->ticket?->payment_method,
            'pointDep' => $booking->point_dep?->name,
            'destination' => $booking->destination?->name,
            'departLabel' => $booking->depart?->identifier(with_trajet_prefix: true),
            'createdAtLabel' => $this->frenchDateTime($booking->created_at),
            'busName' => $booking->bus?->name,
            'isCancelled' => $booking->trashed(),
            'cancelledBy' => $booking->deleted_by,
            'belongsToGroup' => $booking->group_id !== null,
            'isRoundTrip' => $booking->isRoundTrip(),
            'isForGp' => $booking->is_for_gp,
            'extra_info' => [
                'transactionId' => $booking->ticket?->comment,
                'group_id' => $booking->group_id,
            ],
            'client' => [
                'fullName' => normalize_passenger_display_name($booking->customer->full_name),
                'phoneNumber' => $booking->customer->phone_number,
            ],
        ];
    }

    /**
     * "le 19 janvier 2026 à 19h30", or null.
     */
    private function frenchDateTime(?Carbon $dateTime): ?string
    {
        return $dateTime?->locale('fr')->translatedFormat('\l\e j F Y \à H\hi');
    }

    protected function resolveBookingForAction(int $bookingId): Booking
    {
        return Customer::findOrFail($this->foundCustomerId)->bookings()->findOrFail($bookingId);
    }

    protected function refreshBookingRows(): void
    {
        unset(
            $this->foundCustomer,
            $this->customerHeader,
            $this->currentBookingRows,
            $this->pastBookingRows,
        );
    }

    public function render(): View
    {
        return view('livewire.back-office.global-search');
    }
}
