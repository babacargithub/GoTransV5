<?php

namespace App\Livewire\BackOffice;

use App\Http\Controllers\BookingController;
use App\Http\Resources\BookingResource;
use App\Livewire\BackOffice\Concerns\ManagesBookingActions;
use App\Models\Booking;
use App\Models\Bus;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Back office passengers page for a single bus.
 *
 * Replaces the plain Blade page + POST-form mutations: every row action
 * (encaisser un paiement, relancer via Wave/OM, annuler) now runs through
 * Livewire so the table refreshes in place without a full reload. The legacy
 * JSON API keeps hitting BusController@bookings untouched.
 *
 * Annuler / transférer / modifier / rembourser live in the shared
 * {@see ManagesBookingActions} trait (also used by the header customer search);
 * only the bus-scoped bits (payment collection + reminders) stay here.
 */
#[Layout('components.layouts.back-office')]
class BusBookings extends Component
{
    use ManagesBookingActions;

    public Bus $bus;

    public function mount(Bus $bus): void
    {
        $this->bus = $bus;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function bookingRows(): array
    {
        $this->bus->load([
            'bookings.customer',
            'bookings.seat.seat',
            'bookings.ticket',
            'bookings.point_dep',
            'bookings.destination',
            'bookings.depart',
        ]);

        $orderedBookings = $this->bus->bookings
            ->sortBy(fn (Booking $booking): array => $this->bookingSortKey($booking))
            ->values();

        return collect(BookingResource::collection($orderedBookings)->resolve(request()))
            ->map(function (array $bookingRow): array {
                $bookingRow['client']['fullName'] = normalize_passenger_display_name($bookingRow['client']['fullName']);

                return $bookingRow;
            })
            ->all();
    }

    /**
     * Sort key placing every unpaid booking first (newest on top), then the paid
     * bookings ordered by ascending seat number.
     *
     * @return array{0: int, 1: int}
     */
    private function bookingSortKey(Booking $booking): array
    {
        if (! $booking->has_ticket) {
            return [0, -$booking->id];
        }

        return [1, $booking->seat_number ?? PHP_INT_MAX];
    }

    public function departLabel(): string
    {
        return $this->bus->depart->identifier(with_trajet_prefix: true);
    }

    /* ---- payment collection + reminders (bus-scoped, not shared) ---- */

    public function askToConfirmTicketPayment(int $bookingId): void
    {
        $this->openConfirmationModal($bookingId, 'collect-ticket-payment');
    }

    public function sendWavePaymentReminder(int $bookingId): void
    {
        $this->sendPaymentReminder($bookingId, 'wave');
    }

    public function sendOrangeMoneyPaymentReminder(int $bookingId): void
    {
        $this->sendPaymentReminder($bookingId, 'om');
    }

    /**
     * @return array{heading: string, body: string, confirmLabel: string, confirmVariant: string}
     */
    protected function hostConfirmationModalCopy(?string $actionName): array
    {
        if ($actionName === 'collect-ticket-payment') {
            return [
                'heading' => 'Encaisser le paiement',
                'body' => 'Confirmez l\'encaissement du billet pour cette réservation. Un siège disponible sera attribué automatiquement et le client sera notifié.',
                'confirmLabel' => 'Encaisser le paiement',
                'confirmVariant' => 'primary',
            ];
        }

        return $this->neutralConfirmationModalCopy();
    }

    protected function handleHostConfirmationAction(?string $actionName, int $bookingId): void
    {
        if ($actionName === 'collect-ticket-payment') {
            $this->collectTicketPayment($bookingId);
        }
    }

    protected function resolveBookingForAction(int $bookingId): Booking
    {
        return $this->bus->bookings()->findOrFail($bookingId);
    }

    protected function refreshBookingRows(): void
    {
        unset($this->bookingRows);
    }

    public function render(): View
    {
        return view('livewire.back-office.bus-bookings')
            ->title($this->bus->name.' — Réservations');
    }

    private function collectTicketPayment(int $bookingId): void
    {
        $this->resetFlashMessages();

        $booking = $this->resolveBookingForAction($bookingId);
        $customerFullName = normalize_passenger_display_name($booking->customer->full_name);

        $legacyResponse = app(BookingController::class)->saveTicketPayment($booking, request());

        if ($legacyResponse->getStatusCode() === 200) {
            $this->flashStatusMessage = 'Paiement encaissé pour '.$customerFullName.'.';
        } else {
            $this->flashErrorMessage = data_get($legacyResponse->getData(true), 'message', "Le paiement n'a pas pu être encaissé.");
        }

        unset($this->bookingRows);
    }

    private function sendPaymentReminder(int $bookingId, string $paymentMethod): void
    {
        $this->resetFlashMessages();

        $booking = $this->resolveBookingForAction($bookingId);

        try {
            app(BookingController::class)->triggerPaymentRequestForPaymentMethod($booking, $paymentMethod, request());
            $this->flashStatusMessage = 'Relance de paiement '.strtoupper($paymentMethod).' envoyée à '.normalize_passenger_display_name($booking->customer->full_name).'.';
        } catch (\Throwable $exception) {
            $this->flashErrorMessage = $exception->getMessage();
        }
    }
}
