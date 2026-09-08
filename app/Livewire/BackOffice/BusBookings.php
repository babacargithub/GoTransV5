<?php

namespace App\Livewire\BackOffice;

use App\Http\Controllers\BookingController;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Bus;
use App\Models\Depart;
use App\Models\Destination;
use App\Models\PointDep;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

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

    public bool $showTransferModal = false;

    public ?int $transferBookingId = null;

    public ?string $transferErrorMessage = null;

    public bool $showEditModal = false;

    public ?int $editBookingId = null;

    public ?int $editPointDepId = null;

    public ?int $editDestinationId = null;

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
                $bookingRow['client']['fullName'] = $this->normalizeDisplayName($bookingRow['client']['fullName']);

                return $bookingRow;
            })
            ->all();
    }

    /**
     * Formats a passenger name as "Firstname Parts LASTNAME": every first-name part is
     * capitalised (multipart first names included) and the last word is fully uppercased.
     */
    private function normalizeDisplayName(string $rawName): string
    {
        $nameParts = preg_split('/\s+/', trim($rawName), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        if ($nameParts === []) {
            return $rawName;
        }

        if (count($nameParts) === 1) {
            return Str::title($nameParts[0]);
        }

        $lastName = Str::upper(array_pop($nameParts));
        $firstName = Str::title(implode(' ', $nameParts));

        return $firstName.' '.$lastName;
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
            'refund-booking' => [
                'heading' => 'Rembourser la réservation',
                'body' => 'Le paiement Wave sera remboursé au client et la réservation sera annulée. Cette action est irréversible.',
                'confirmLabel' => 'Rembourser',
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

    public function askToConfirmRefund(int $bookingId): void
    {
        $this->openConfirmationModal($bookingId, 'refund-booking');
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
            'refund-booking' => $this->refundBooking($bookingId),
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

    public function openBookingTransferModal(int $bookingId): void
    {
        $this->resetFlashMessages();
        $this->transferErrorMessage = null;
        $this->transferBookingId = $bookingId;
        $this->showTransferModal = true;
    }

    public function closeBookingTransferModal(): void
    {
        $this->showTransferModal = false;
        $this->transferBookingId = null;
        $this->transferErrorMessage = null;
    }

    /**
     * Upcoming departs (soonest first) with the buses a booking can be transferred to.
     * The current bus is excluded; full buses are kept but flagged so the UI can disable them.
     *
     * @return array<int, array{
     *     id: int,
     *     label: string,
     *     date: string,
     *     buses: array<int, array{id: int, name: string, numberOfSeatsLeft: int, isFull: bool}>
     * }>
     */
    #[Computed]
    public function transferDepartOptions(): array
    {
        return Depart::query()
            ->notPassed()
            ->with(['trajet', 'buses'])
            ->orderBy('date')
            ->get()
            ->map(function (Depart $upcomingDepart): array {
                return [
                    'id' => $upcomingDepart->id,
                    'label' => $upcomingDepart->identifier(with_trajet_prefix: true),
                    'date' => $upcomingDepart->date->format('d/m/Y H:i'),
                    'buses' => $upcomingDepart->buses
                        ->reject(fn (Bus $candidateBus): bool => $candidateBus->id === $this->bus->id)
                        ->map(function (Bus $candidateBus): array {
                            $numberOfSeatsLeft = $candidateBus->seatsLeft();

                            return [
                                'id' => $candidateBus->id,
                                'name' => $candidateBus->name,
                                'numberOfSeatsLeft' => $numberOfSeatsLeft,
                                'isFull' => $numberOfSeatsLeft <= 0,
                            ];
                        })
                        ->values()
                        ->all(),
                ];
            })
            ->reject(fn (array $departOption): bool => $departOption['buses'] === [])
            ->values()
            ->all();
    }

    public function transferBookingToBus(int $targetBusId): void
    {
        $this->resetFlashMessages();
        $this->transferErrorMessage = null;

        if ($this->transferBookingId === null) {
            return;
        }

        $booking = $this->findBookingOnThisBus($this->transferBookingId);
        $customerFullName = $this->normalizeDisplayName($booking->customer->full_name);
        $targetBus = Bus::findOrFail($targetBusId);

        try {
            $legacyResponse = app(BookingController::class)->transferBooking($booking, $targetBus);

            if ($legacyResponse->getStatusCode() === 200) {
                $this->closeBookingTransferModal();
                $this->flashStatusMessage = 'Réservation de '.$customerFullName.' transférée vers '.$targetBus->name.'.';
                unset($this->bookingRows, $this->transferDepartOptions);

                return;
            }

            $this->transferErrorMessage = data_get($legacyResponse->getData(true), 'message', "Le transfert n'a pas pu être effectué.");
        } catch (\Throwable $exception) {
            $this->transferErrorMessage = $exception->getMessage();
        }
    }

    public function openBookingEditModal(int $bookingId): void
    {
        $this->resetFlashMessages();
        $this->resetValidation();

        $booking = $this->findBookingOnThisBus($bookingId);

        $this->editBookingId = $bookingId;
        $this->editPointDepId = $booking->point_dep_id;
        $this->editDestinationId = $booking->destination_id;
        $this->showEditModal = true;
    }

    public function closeBookingEditModal(): void
    {
        $this->showEditModal = false;
        $this->editBookingId = null;
        $this->editPointDepId = null;
        $this->editDestinationId = null;
        $this->resetValidation();
    }

    /**
     * Pickup points and destinations that belong to the same trajet as the booking's depart.
     * Choosing stops from another trajet would not be coherent, so those are the only options.
     *
     * @return array{
     *     pointDeps: array<int, array{id: int, name: string}>,
     *     destinations: array<int, array{id: int, name: string}>
     * }
     */
    #[Computed]
    public function editableTrajetStops(): array
    {
        if ($this->editBookingId === null) {
            return ['pointDeps' => [], 'destinations' => []];
        }

        $trajet = $this->findBookingOnThisBus($this->editBookingId)->depart->trajet;

        return [
            'pointDeps' => $trajet->pointDeps
                ->map(fn (PointDep $pointDep): array => ['id' => $pointDep->id, 'name' => $pointDep->name])
                ->all(),
            'destinations' => $trajet->destinations
                ->map(fn (Destination $destination): array => ['id' => $destination->id, 'name' => $destination->name])
                ->all(),
        ];
    }

    public function saveBookingEdit(): void
    {
        $this->resetFlashMessages();

        if ($this->editBookingId === null) {
            return;
        }

        $booking = $this->findBookingOnThisBus($this->editBookingId);
        $trajetId = $booking->depart->trajet_id;

        $this->validate([
            'editPointDepId' => ['required', Rule::exists('point_deps', 'id')->where('trajet_id', $trajetId)],
            'editDestinationId' => ['required', Rule::exists('destinations', 'id')->where('trajet_id', $trajetId)],
        ], [
            'editPointDepId.required' => 'Le point de départ est obligatoire.',
            'editPointDepId.exists' => "Ce point de départ n'appartient pas au trajet de la réservation.",
            'editDestinationId.required' => 'La destination est obligatoire.',
            'editDestinationId.exists' => "Cette destination n'appartient pas au trajet de la réservation.",
        ]);

        $customerFullName = $this->normalizeDisplayName($booking->customer->full_name);

        try {
            request()->merge([
                'point_dep_id' => $this->editPointDepId,
                'destination_id' => $this->editDestinationId,
            ]);

            $legacyResponse = app(BookingController::class)->update(request(), $booking);

            if ($legacyResponse->getStatusCode() === 200) {
                $this->closeBookingEditModal();
                $this->flashStatusMessage = 'Réservation de '.$customerFullName.' mise à jour.';
                unset($this->bookingRows);

                return;
            }

            $this->flashErrorMessage = data_get($legacyResponse->getData(true), 'message', "La réservation n'a pas pu être mise à jour.");
        } catch (\Throwable $exception) {
            $this->flashErrorMessage = $exception->getMessage();
        }
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
        $customerFullName = $this->normalizeDisplayName($booking->customer->full_name);

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
        $customerFullName = $this->normalizeDisplayName($booking->customer->full_name);

        try {
            app(BookingController::class)->cancelBooking($booking);
            $this->flashStatusMessage = 'Réservation de '.$customerFullName.' annulée.';
        } catch (\Throwable $exception) {
            $this->flashErrorMessage = $exception->getMessage();
        }

        unset($this->bookingRows);
    }

    private function refundBooking(int $bookingId): void
    {
        $this->resetFlashMessages();

        $booking = $this->findBookingOnThisBus($bookingId);
        $customerFullName = $this->normalizeDisplayName($booking->customer->full_name);

        try {
            $legacyResponse = app(BookingController::class)->refundTicket($booking);

            if ($legacyResponse->getStatusCode() === 200) {
                $this->flashStatusMessage = 'Remboursement Wave effectué pour '.$customerFullName.'.';
            } else {
                $this->flashErrorMessage = $this->extractLegacyResponseMessage($legacyResponse, 'Le remboursement a échoué.');
            }
        } catch (\Throwable $exception) {
            $this->flashErrorMessage = $exception->getMessage();
        }

        unset($this->bookingRows);
    }

    /**
     * Pulls a human message out of either an Illuminate JSON response ({"message": ...}) or a
     * plain Symfony response (the Wave API error body), both of which refundTicket() can return.
     */
    private function extractLegacyResponseMessage(SymfonyResponse $response, string $fallback): string
    {
        if ($response instanceof JsonResponse) {
            return data_get($response->getData(true), 'message', $fallback);
        }

        $content = trim((string) $response->getContent());

        return $content !== '' ? $content : $fallback;
    }

    private function sendPaymentReminder(int $bookingId, string $paymentMethod): void
    {
        $this->resetFlashMessages();

        $booking = $this->findBookingOnThisBus($bookingId);

        try {
            app(BookingController::class)->triggerPaymentRequestForPaymentMethod($booking, $paymentMethod, request());
            $this->flashStatusMessage = 'Relance de paiement '.strtoupper($paymentMethod).' envoyée à '.$this->normalizeDisplayName($booking->customer->full_name).'.';
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
