<?php

namespace App\Livewire\BackOffice;

use App\Http\Controllers\BookingController;
use App\Http\Resources\BookingResource;
use App\Livewire\BackOffice\Concerns\ManagesBookingActions;
use App\Models\Booking;
use App\Models\Bus;
use App\Models\TicketPayment;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

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
    use WithFileUploads;

    public const PAYMENT_METHOD_CASH = 'cash';

    public const PAYMENT_METHOD_WAVE = 'wave';

    public const PAYMENT_METHOD_ORANGE_MONEY = 'om';

    public Bus $bus;

    public bool $showPaymentMethodModal = false;

    public ?int $paymentBookingId = null;

    public string $paymentMethodChoice = self::PAYMENT_METHOD_CASH;

    public string $providerTransactionId = '';

    public string $proofNote = '';

    /**
     * @var array<int, TemporaryUploadedFile>
     */
    public array $proofScreenshots = [];

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
        // Loaded on a relation-less copy: relations set on the public $this->bus would be
        // re-hydrated (re-queried) on every Livewire update request.
        $busWithSchedules = $this->bus->withoutRelations()
            ->setRelation('heuresDeparts', $this->bus->heuresDeparts()->get());

        $orderedBookings = $this->bus->bookings()
            ->with([
                'customer',
                'seat.seat',
                'ticket',
                'point_dep',
                'destination',
                'depart.trajet',
                'depart.heuresDeparts',
            ])
            ->get()
            ->each(fn (Booking $booking) => $booking->setRelation('bus', $busWithSchedules))
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

    /**
     * "Payer": opens the dialog asking how the customer actually paid.
     */
    public function askToConfirmTicketPayment(int $bookingId): void
    {
        $this->resetFlashMessages();
        $this->resetValidation();
        $this->paymentBookingId = $bookingId;
        $this->paymentMethodChoice = self::PAYMENT_METHOD_CASH;
        $this->providerTransactionId = '';
        $this->proofNote = '';
        $this->proofScreenshots = [];
        $this->showPaymentMethodModal = true;
    }

    public function closePaymentMethodModal(): void
    {
        $this->showPaymentMethodModal = false;
        $this->paymentBookingId = null;
        $this->proofScreenshots = [];
        $this->resetValidation();
    }

    /**
     * Settle the booking manually. "Wave / OM non traité" means the customer really paid
     * but the provider callback never handled it: the legacy saveTicketPayment credits
     * the matching caisse, and we additionally keep the provider transaction id and the
     * proofs (screenshots / raw text) on a TicketPayment linked to the ticket.
     */
    public function submitTicketPayment(): void
    {
        $this->resetFlashMessages();

        $isProviderPayment = in_array($this->paymentMethodChoice, [self::PAYMENT_METHOD_WAVE, self::PAYMENT_METHOD_ORANGE_MONEY], true);

        $this->validate([
            'paymentMethodChoice' => ['required', 'in:'.implode(',', [self::PAYMENT_METHOD_CASH, self::PAYMENT_METHOD_WAVE, self::PAYMENT_METHOD_ORANGE_MONEY])],
            'providerTransactionId' => [$isProviderPayment ? 'required' : 'nullable', 'string', 'max:100', 'unique:ticket_payments,provider_transaction_id'],
            'proofNote' => ['nullable', 'string', 'max:2000'],
            'proofScreenshots' => ['array', 'max:5'],
            'proofScreenshots.*' => ['image', 'max:5120'],
        ], attributes: [
            'providerTransactionId' => 'ID de transaction',
            'proofNote' => 'texte de preuve',
            'proofScreenshots.*' => 'capture d\'écran',
        ]);

        $booking = $this->resolveBookingForActionOrFail($this->paymentBookingId);
        $customerFullName = normalize_passenger_display_name($booking->customer->full_name);

        $storedProofPaths = $isProviderPayment
            ? array_map(fn ($screenshot): string => $screenshot->store('payment-proofs', 'local'), $this->proofScreenshots)
            : [];

        request()->merge(['payment_method' => $this->paymentMethodChoice]);
        $legacyResponse = app(BookingController::class)->saveTicketPayment($booking, request());

        if ($legacyResponse->getStatusCode() !== 200) {
            Storage::disk('local')->delete($storedProofPaths);
            $this->flashErrorMessage = data_get($legacyResponse->getData(true), 'message', "Le paiement n'a pas pu être encaissé.");
            $this->closePaymentMethodModal();

            return;
        }

        $ticket = $booking->fresh()->ticket;

        DB::transaction(function () use ($ticket, $isProviderPayment, $storedProofPaths): void {
            TicketPayment::create([
                'ticket_id' => $ticket->id,
                'payement_method' => $this->paymentMethodChoice,
                'status' => TicketPayment::STATUS_SUCCESS,
                'montant' => (int) $ticket->price,
                'is_for_multiple_booking' => false,
                'provider_transaction_id' => $isProviderPayment ? trim($this->providerTransactionId) : null,
                'proofs' => $storedProofPaths === [] ? null : $storedProofPaths,
                'proof_note' => $isProviderPayment && trim($this->proofNote) !== '' ? trim($this->proofNote) : null,
                'recorded_by_user_id' => auth()->id(),
            ]);
        });

        $this->flashStatusMessage = 'Paiement encaissé pour '.$customerFullName.'.';
        $this->closePaymentMethodModal();
        unset($this->bookingRows);
    }

    private function resolveBookingForActionOrFail(?int $bookingId): Booking
    {
        return $this->resolveBookingForAction((int) $bookingId);
    }

    public function sendWavePaymentReminder(int $bookingId): void
    {
        $this->sendPaymentReminder($bookingId, 'wave');
    }

    public function sendOrangeMoneyPaymentReminder(int $bookingId): void
    {
        $this->sendPaymentReminder($bookingId, 'om');
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
