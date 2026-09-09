<?php

namespace App\Livewire\Website;

use App\Http\Controllers\MobileAppController;
use App\Http\Resources\PaymentResponseResource;
use App\Models\AppParams;
use App\Models\Booking;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Public booking page for a group of bookings created from the student booking form.
 *
 * Addressed by the group's shared UUID (see the add_uuid_to_bookings migration) so the numeric
 * group_id is never exposed. Mirrors the mobile app's ShowMultipleBooking screen: unpaid groups
 * get "Payer" buttons (reusing MobileAppController@generatePaymentUrlForMultipleBooking), paid
 * groups get a "Télécharger mon ticket" link to the existing public ticket page.
 */
class BookingGroupShow extends Component
{
    public string $uuid;

    /** "wave" | "om" */
    public ?string $paymentMethod = null;

    public ?string $orangeMoneyNumber = null;

    public ?string $paymentError = null;

    public function mount(string $uuid): void
    {
        $this->uuid = $uuid;

        abort_if($this->bookings->isEmpty(), 404);
    }

    /**
     * @return Collection<int, Booking>
     */
    #[Computed]
    public function bookings(): Collection
    {
        return Booking::query()
            ->where('uuid', $this->uuid)
            ->with([
                'customer',
                'depart.heuresDeparts',
                'bus.heuresDeparts',
                'point_dep',
                'destination',
                'seat.seat',
                'ticket',
            ])
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function groupIsPaid(): bool
    {
        return $this->bookings->isNotEmpty()
            && $this->bookings->every(fn (Booking $booking): bool => $booking->hasTicket());
    }

    #[Computed]
    public function departHasPassed(): bool
    {
        return (bool) $this->bookings->first()?->depart?->isPassed();
    }

    #[Computed]
    public function groupId(): ?int
    {
        return $this->bookings->first()?->group_id;
    }

    #[Computed]
    public function nonRefundableReminder(): string
    {
        return data_get(AppParams::first()?->data, 'warning_message_before_booking')
            ?? "N.B. Le ticket n'est pas remboursable !";
    }

    /**
     * The pickup time for a booking (its bus schedule, else the départ schedule). Guarded because
     * legacy data can be missing a HeureDepart for the chosen point de départ.
     */
    public function pickupTimeFor(Booking $booking): ?string
    {
        try {
            $schedule = $booking->bus?->heuresDeparts->firstWhere('point_dep_id', $booking->point_dep_id)
                ?? $booking->depart?->heuresDeparts->firstWhere('point_dep_id', $booking->point_dep_id)
                ?? $booking->depart?->heuresDeparts->sortBy('heureDepart')->first();

            return $schedule?->heureDepart?->format('H\hi');
        } catch (\Throwable) {
            return null;
        }
    }

    public function agentNumberFor(Booking $booking): ?string
    {
        return $booking->bus?->resolveAgentContactNumber(AppParams::first()?->getBusAgentDefaultNumber());
    }

    public function payWithWave(): void
    {
        $this->initiateGroupPayment('wave');
    }

    public function payWithOrangeMoney(): void
    {
        $this->initiateGroupPayment('om');
    }

    private function initiateGroupPayment(string $paymentMethod): void
    {
        $this->paymentError = null;
        $this->paymentMethod = $paymentMethod;

        if ($this->groupIsPaid) {
            $this->paymentError = 'Cette réservation est déjà payée.';

            return;
        }

        if ($this->departHasPassed) {
            $this->paymentError = 'Le départ de cette réservation est déjà passé.';

            return;
        }

        if ($paymentMethod === 'om' && ! preg_match('/^(77|78|76|70|75|71)[0-9]{7}$/', trim((string) $this->orangeMoneyNumber))) {
            $this->addError('orangeMoneyNumber', 'Veuillez entrer le numéro Orange Money qui va payer.');

            return;
        }

        request()->merge([
            'group_id' => $this->groupId,
            'payment_method' => $paymentMethod,
            'om_number' => $paymentMethod === 'om' ? trim((string) $this->orangeMoneyNumber) : null,
        ]);

        try {
            $paymentResponse = app(MobileAppController::class)->generatePaymentUrlForMultipleBooking(request());
        } catch (ValidationException $exception) {
            $this->paymentError = $exception->validator->errors()->first()
                ?: "Le paiement n'a pas pu être initié.";

            return;
        } catch (\Throwable $exception) {
            report($exception);
            $this->paymentError = "Le paiement n'a pas pu être initié. Veuillez réessayer dans un instant.";

            return;
        }

        $responseData = $paymentResponse instanceof PaymentResponseResource
            ? $paymentResponse->data
            : (array) (method_exists($paymentResponse, 'getData') ? $paymentResponse->getData(true) : []);

        if ($paymentResponse instanceof PaymentResponseResource && ! $paymentResponse->isOK()) {
            $this->paymentError = data_get($responseData, 'message')
                ?? "Le paiement n'a pas pu être initié. Veuillez réessayer dans un instant.";

            return;
        }

        if ($paymentMethod === 'wave') {
            $waveLaunchUrl = data_get($responseData, 'paymentResponse.wave_launch_url');

            if (filled($waveLaunchUrl)) {
                $this->redirect($waveLaunchUrl);

                return;
            }

            $this->paymentError = "Le paiement Wave n'a pas pu être initié. Veuillez réessayer dans un instant.";

            return;
        }

        session()->flash('status', "Le paiement Orange Money a été initié. Validez l'opération sur votre téléphone en tapant #144# puis votre code secret.");
        unset($this->bookings);
    }

    public function render(): View
    {
        return view('livewire.website.booking-group-show')
            ->layout('components.layouts.website', [
                'title' => 'Ma réservation | Globe One Transport',
                'description' => 'Suivez votre réservation de bus Globe One Transport.',
                'robots' => 'noindex, nofollow',
            ]);
    }
}
