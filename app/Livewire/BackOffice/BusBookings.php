<?php

namespace App\Livewire\BackOffice;

use App\Http\Controllers\BookingController;
use App\Http\Resources\BookingResource;
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
 */
#[Layout('components.layouts.back-office')]
class BusBookings extends Component
{
    public Bus $bus;

    public ?string $flashStatusMessage = null;

    public ?string $flashErrorMessage = null;

    public bool $showConfirmationModal = false;

    public ?int $pendingBookingId = null;

    public ?string $pendingActionName = null;

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

        return BookingResource::collection($this->bus->bookings)->resolve(request());
    }

    public function departLabel(): string
    {
        return $this->bus->depart->identifier(with_trajet_prefix: true);
    }

    /**
     * @return array{heading: string, body: string, confirmLabel: string, confirmVariant: string}
     */
    #[Computed]
    public function confirmationModalCopy(): array
    {
        return match ($this->pendingActionName) {
            'collect-ticket-payment' => [
                'heading' => 'Encaisser le paiement',
                'body' => 'Confirmez l\'encaissement du billet pour cette réservation. Un siège disponible sera attribué automatiquement et le client sera notifié.',
                'confirmLabel' => 'Encaisser le paiement',
                'confirmVariant' => 'primary',
            ],
            'cancel-booking' => [
                'heading' => 'Annuler la réservation',
                'body' => 'La réservation sera annulée et le siège éventuellement attribué sera libéré. Cette action est irréversible.',
                'confirmLabel' => 'Annuler la réservation',
                'confirmVariant' => 'danger',
            ],
            default => [
                'heading' => '',
                'body' => '',
                'confirmLabel' => 'Confirmer',
                'confirmVariant' => 'primary',
            ],
        };
    }

    public function askToConfirmTicketPayment(int $bookingId): void
    {
        $this->openConfirmationModal($bookingId, 'collect-ticket-payment');
    }

    public function askToConfirmBookingCancellation(int $bookingId): void
    {
        $this->openConfirmationModal($bookingId, 'cancel-booking');
    }

    public function confirmPendingAction(): void
    {
        $bookingId = $this->pendingBookingId;
        $actionName = $this->pendingActionName;

        $this->closeConfirmationModal();

        if ($bookingId === null) {
            return;
        }

        match ($actionName) {
            'collect-ticket-payment' => $this->collectTicketPayment($bookingId),
            'cancel-booking' => $this->cancelBooking($bookingId),
            default => null,
        };
    }

    public function sendWavePaymentReminder(int $bookingId): void
    {
        $this->sendPaymentReminder($bookingId, 'wave');
    }

    public function sendOrangeMoneyPaymentReminder(int $bookingId): void
    {
        $this->sendPaymentReminder($bookingId, 'om');
    }

    public function render(): View
    {
        return view('livewire.back-office.bus-bookings')
            ->title($this->bus->name.' — Réservations');
    }

    private function openConfirmationModal(int $bookingId, string $actionName): void
    {
        $this->resetFlashMessages();
        $this->pendingBookingId = $bookingId;
        $this->pendingActionName = $actionName;
        $this->showConfirmationModal = true;
    }

    private function closeConfirmationModal(): void
    {
        $this->showConfirmationModal = false;
        $this->pendingBookingId = null;
        $this->pendingActionName = null;
    }

    private function collectTicketPayment(int $bookingId): void
    {
        $this->resetFlashMessages();

        $booking = $this->findBookingOnThisBus($bookingId);
        $customerFullName = $booking->customer->full_name;

        $legacyResponse = app(BookingController::class)->saveTicketPayment($booking, request());

        if ($legacyResponse->getStatusCode() === 200) {
            $this->flashStatusMessage = 'Paiement encaissé pour '.$customerFullName.'.';
        } else {
            $this->flashErrorMessage = data_get($legacyResponse->getData(true), 'message', "Le paiement n'a pas pu être encaissé.");
        }

        unset($this->bookingRows);
    }

    private function cancelBooking(int $bookingId): void
    {
        $this->resetFlashMessages();

        $booking = $this->findBookingOnThisBus($bookingId);
        $customerFullName = $booking->customer->full_name;

        try {
            app(BookingController::class)->cancelBooking($booking);
            $this->flashStatusMessage = 'Réservation de '.$customerFullName.' annulée.';
        } catch (\Throwable $exception) {
            $this->flashErrorMessage = $exception->getMessage();
        }

        unset($this->bookingRows);
    }

    private function sendPaymentReminder(int $bookingId, string $paymentMethod): void
    {
        $this->resetFlashMessages();

        $booking = $this->findBookingOnThisBus($bookingId);

        try {
            app(BookingController::class)->triggerPaymentRequestForPaymentMethod($booking, $paymentMethod, request());
            $this->flashStatusMessage = 'Relance de paiement '.strtoupper($paymentMethod).' envoyée à '.$booking->customer->full_name.'.';
        } catch (\Throwable $exception) {
            $this->flashErrorMessage = $exception->getMessage();
        }
    }

    private function findBookingOnThisBus(int $bookingId): Booking
    {
        return $this->bus->bookings()->findOrFail($bookingId);
    }

    private function resetFlashMessages(): void
    {
        $this->flashStatusMessage = null;
        $this->flashErrorMessage = null;
    }
}
