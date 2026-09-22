<?php

namespace App\Livewire\BackOffice\Concerns;

use App\Enums\PermissionName;
use App\Http\Controllers\BookingController;
use App\Models\Booking;
use App\Models\Bus;
use App\Models\Depart;
use App\Models\Destination;
use App\Models\PointDep;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Shared booking row-actions for any back-office Livewire page that lists
 * bookings (the bus passengers page and the header customer-search result
 * dialog).
 *
 * It owns the "annuler / rembourser" confirmation modal, the "transférer"
 * modal and the "modifier" modal, and runs each mutation through an untouched
 * BookingController method. The host component provides:
 *  - resolveBookingForAction(): resolve + authorise a booking id for a row action
 *  - refreshBookingRows(): invalidate the host's own booking listing
 * and may override hostConfirmationModalCopy() / handleHostConfirmationAction()
 * to add its own confirmation actions (e.g. encaisser un paiement).
 */
trait ManagesBookingActions
{
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

    /**
     * Resolve the booking for a row action, scoped/authorised to what the host
     * page is allowed to touch (a bus's bookings, a customer's bookings, …).
     * Throws ModelNotFoundException when the id is not in scope.
     */
    abstract protected function resolveBookingForAction(int $bookingId): Booking;

    /**
     * Same as resolveBookingForAction(), but a booking that is not in scope
     * yields a flash error instead of an unhandled 404.
     */
    private function resolveBookingForActionOrFlash(int $bookingId): ?Booking
    {
        try {
            return $this->resolveBookingForAction($bookingId);
        } catch (ModelNotFoundException) {
            $this->flashErrorMessage = 'Cette réservation est introuvable.';

            return null;
        }
    }

    /**
     * Invalidate the host component's own booking listing after a mutation.
     */
    abstract protected function refreshBookingRows(): void;

    protected function resetFlashMessages(): void
    {
        $this->flashStatusMessage = null;
        $this->flashErrorMessage = null;
    }

    /**
     * Guard a permission-gated row action. Returns false (and flashes an error)
     * when the current user lacks the permission; a `full-access` holder always
     * passes.
     */
    private function ensurePermittedOrFlash(PermissionName $requiredPermission): bool
    {
        if ($requiredPermission->allowedForCurrentUser()) {
            return true;
        }

        $this->flashErrorMessage = 'Action non autorisée : la permission « '.$requiredPermission->defaultLabel().' » est requise.';

        return false;
    }

    /* ================= annuler / rembourser ================= */

    public function askToConfirmBookingCancellation(int $bookingId): void
    {
        $this->openConfirmationModal($bookingId, 'cancel-booking');
    }

    public function askToConfirmRefund(int $bookingId): void
    {
        $this->openConfirmationModal($bookingId, 'refund-booking');
    }

    protected function openConfirmationModal(int $bookingId, string $actionName): void
    {
        $this->resetFlashMessages();
        $this->pendingBookingId = $bookingId;
        $this->pendingActionName = $actionName;
        $this->showConfirmationModal = true;
    }

    protected function closeConfirmationModal(): void
    {
        $this->showConfirmationModal = false;
        $this->pendingBookingId = null;
        $this->pendingActionName = null;
    }

    /**
     * @return array{heading: string, body: string, confirmLabel: string, confirmVariant: string}
     */
    #[Computed]
    public function confirmationModalCopy(): array
    {
        return match ($this->pendingActionName) {
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
            default => $this->hostConfirmationModalCopy($this->pendingActionName),
        };
    }

    /**
     * Copy for host-specific confirmation actions. Override in the host and fall
     * back to {@see neutralConfirmationModalCopy()} for actions it does not know.
     *
     * @return array{heading: string, body: string, confirmLabel: string, confirmVariant: string}
     */
    protected function hostConfirmationModalCopy(?string $actionName): array
    {
        return $this->neutralConfirmationModalCopy();
    }

    /**
     * @return array{heading: string, body: string, confirmLabel: string, confirmVariant: string}
     */
    protected function neutralConfirmationModalCopy(): array
    {
        return [
            'heading' => '',
            'body' => '',
            'confirmLabel' => 'Confirmer',
            'confirmVariant' => 'primary',
        ];
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
            'cancel-booking' => $this->cancelBooking($bookingId),
            'refund-booking' => $this->refundBooking($bookingId),
            default => $this->handleHostConfirmationAction($actionName, $bookingId),
        };
    }

    /**
     * Handle host-specific confirmation actions. Override in the host.
     */
    protected function handleHostConfirmationAction(?string $actionName, int $bookingId): void
    {
        //
    }

    protected function cancelBooking(int $bookingId): void
    {
        $this->resetFlashMessages();

        $booking = $this->resolveBookingForActionOrFlash($bookingId);

        if ($booking === null) {
            return;
        }

        $requiredPermission = $booking->hasTicket()
            ? PermissionName::CancelPaidBooking
            : PermissionName::CancelUnpaidBooking;

        if (! $this->ensurePermittedOrFlash($requiredPermission)) {
            return;
        }

        $customerFullName = normalize_passenger_display_name($booking->customer->full_name);

        try {
            app(BookingController::class)->cancelBooking($booking);
            $this->flashStatusMessage = 'Réservation de '.$customerFullName.' annulée.';
        } catch (\Throwable $exception) {
            $this->flashErrorMessage = $exception->getMessage();
        }

        $this->refreshBookingRows();
    }

    protected function refundBooking(int $bookingId): void
    {
        $this->resetFlashMessages();

        $booking = $this->resolveBookingForActionOrFlash($bookingId);

        if ($booking === null) {
            return;
        }

        if (! $this->ensurePermittedOrFlash(PermissionName::RefundTicket)) {
            return;
        }

        $customerFullName = normalize_passenger_display_name($booking->customer->full_name);

        try {
            $legacyResponse = app(BookingController::class)->refundTicket($booking);

            if ($legacyResponse->getStatusCode() === SymfonyResponse::HTTP_OK) {
                $this->flashStatusMessage = 'Remboursement Wave effectué pour '.$customerFullName.'.';
            } else {
                $this->flashErrorMessage = $this->extractLegacyResponseMessage($legacyResponse, 'Le remboursement a échoué.');
            }
        } catch (\Throwable $exception) {
            $this->flashErrorMessage = $exception->getMessage();
        }

        $this->refreshBookingRows();
    }

    /**
     * Pull a human message out of either an Illuminate JSON response
     * ({"message": ...}) or a plain Symfony response (the Wave API error body).
     */
    protected function extractLegacyResponseMessage(SymfonyResponse $response, string $fallback): string
    {
        if ($response instanceof JsonResponse) {
            return data_get($response->getData(true), 'message', $fallback);
        }

        $content = trim((string) $response->getContent());

        return $content !== '' ? $content : $fallback;
    }

    /* ================= transférer ================= */

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
     * Upcoming departs (soonest first) with the buses a booking can be
     * transferred to. The booking's current bus is excluded; full buses are
     * kept but flagged so the UI can disable them.
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
        $currentBusId = $this->transferBookingId !== null
            ? $this->resolveBookingForActionOrFlash($this->transferBookingId)?->bus_id
            : null;

        return Depart::query()
            ->notPassed()
            ->with(['trajet', 'buses'])
            ->orderBy('date')
            ->get()
            ->map(function (Depart $upcomingDepart) use ($currentBusId): array {
                return [
                    'id' => $upcomingDepart->id,
                    'label' => $upcomingDepart->identifier(with_trajet_prefix: true),
                    'date' => $upcomingDepart->date->format('d/m/Y H:i'),
                    'buses' => $upcomingDepart->buses
                        ->reject(fn (Bus $candidateBus): bool => $candidateBus->id === $currentBusId)
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

        $booking = $this->resolveBookingForActionOrFlash($this->transferBookingId);

        if ($booking === null) {
            return;
        }

        if (! $this->ensurePermittedOrFlash(PermissionName::TransferPastBooking)) {
            return;
        }

        $customerFullName = normalize_passenger_display_name($booking->customer->full_name);
        $targetBus = Bus::findOrFail($targetBusId);

        try {
            $legacyResponse = app(BookingController::class)->transferBooking($booking, $targetBus);

            if ($legacyResponse->getStatusCode() === SymfonyResponse::HTTP_OK) {
                $this->closeBookingTransferModal();
                $this->flashStatusMessage = 'Réservation de '.$customerFullName.' transférée vers '.$targetBus->name.'.';
                unset($this->transferDepartOptions);
                $this->refreshBookingRows();

                return;
            }

            $this->transferErrorMessage = data_get($legacyResponse->getData(true), 'message', "Le transfert n'a pas pu être effectué.");
        } catch (\Throwable $exception) {
            $this->transferErrorMessage = $exception->getMessage();
        }
    }

    /* ================= modifier ================= */

    public function openBookingEditModal(int $bookingId): void
    {
        $this->resetFlashMessages();
        $this->resetValidation();

        $booking = $this->resolveBookingForActionOrFlash($bookingId);

        if ($booking === null) {
            return;
        }

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
     * Pickup points and destinations of the booking's own trajet — the only
     * coherent choices.
     *
     * @return array{
     *     pointDeps: array<int, array{id: int, name: string}>,
     *     destinations: array<int, array{id: int, name: string}>
     * }
     */
    #[Computed]
    public function editableTrajetStops(): array
    {
        $booking = $this->editBookingId !== null
            ? $this->resolveBookingForActionOrFlash($this->editBookingId)
            : null;

        if ($booking === null) {
            return ['pointDeps' => [], 'destinations' => []];
        }

        $trajet = $booking->depart->trajet;

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

        $booking = $this->resolveBookingForActionOrFlash($this->editBookingId);

        if ($booking === null) {
            return;
        }

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

        $customerFullName = normalize_passenger_display_name($booking->customer->full_name);

        try {
            request()->merge([
                'point_dep_id' => $this->editPointDepId,
                'destination_id' => $this->editDestinationId,
            ]);

            $legacyResponse = app(BookingController::class)->update(request(), $booking);

            if ($legacyResponse->getStatusCode() === SymfonyResponse::HTTP_OK) {
                $this->closeBookingEditModal();
                $this->flashStatusMessage = 'Réservation de '.$customerFullName.' mise à jour.';
                $this->refreshBookingRows();

                return;
            }

            $this->flashErrorMessage = data_get($legacyResponse->getData(true), 'message', "La réservation n'a pas pu être mise à jour.");
        } catch (\Throwable $exception) {
            $this->flashErrorMessage = $exception->getMessage();
        }
    }
}
