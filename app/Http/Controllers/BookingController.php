<?php

namespace App\Http\Controllers;

use App\Enums\BookingTransferType;
use App\Enums\PermissionName;
use App\Jobs\RecordTicketPaymentInCaisse;
use App\Manager\BookingManager;
use App\Manager\TicketManager;
use App\Models\Booking;
use App\Models\BookingTransfer;
use App\Models\Bus;
use App\Models\Customer;
use App\Models\Depart;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AccountService;
use App\Services\NotificationService;
use App\Services\WaitingCustomerService;
use DB;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class BookingController extends Controller
{
    private TicketManager $ticketManager;

    public function __construct(TicketManager $ticketManager)
    {
        $this->ticketManager = $ticketManager;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Lists every booking that shares the given group_id (e.g. a multi-passenger or round-trip
     * booking group), in the same shape as BusController::bookings.
     */
    public function bookingsOfGroup(string $groupId)
    {
        return $this->bookingsResponse(Booking::where('group_id', $groupId)->get());
    }

    /**
     * Store a newly created resource in storage.
     *
     * @throws Exception
     */
    public function store(Depart $depart, Request $request)
    {
        //
        $validated = $request->validate([
            'seat_id' => 'exists:seats,id',
            'customer_id' => 'required|exists:customers,id',
            'point_dep_id' => 'required|exists:point_deps,id',
            'destination_id' => 'required|exists:destinations,id',
            'bus_id' => 'integer|exists:buses,id',
            'ticket_paid' => 'boolean',
        ]);
        if ($depart->isFull()) {
            $customer = Customer::find($validated['customer_id']);
            if ($customer != null) {
                app(WaitingCustomerService::class)->recordFailedBooking(
                    $customer, $depart, null, WaitingCustomerService::REASON_BUS_FULL, $validated
                );
            }

            return response()->json(['message' => "Il n'y a pas de place disponible pour ce depart !"], 422);
        }
        $busForBooking = $validated['bus_id'] ?? 0 ? $depart->buses()->find($validated['bus_id']) :
            $depart->getBusForBooking();
        if ($busForBooking == null) {
            $customer = Customer::find($validated['customer_id']);
            if ($customer != null) {
                app(WaitingCustomerService::class)->recordFailedBooking(
                    $customer, $depart, null, WaitingCustomerService::REASON_NO_BUS_AVAILABLE, $validated
                );
            }

            return response()->json(['message' => "Il n'y a pas de place disponible pour ce bus !"], 422);
        }
        // check if customer has already booked for this depart
        if ($depart->bookings()->where('customer_id', $validated['customer_id'])->exists()) {
            $customer = Customer::find($validated['customer_id']);

            return response()->json(['message' => $customer->full_name.' a déjà réservé sur ce depart !'], 422);
        }
        $booking = new Booking($validated);
        $booking->paye = false;
        $booking->online = true;

        $booking->depart()->associate($depart);
        $booking->bus()->associate($busForBooking);
        $busForBooking->bookings()->save($booking);
        $booking->withoutRelations();
        if (isset($validated['ticket_paid']) && $validated['ticket_paid']) {
            $ticketPrice = $this->ticketManager->calculateTicketPriceForBooking($booking);
            $ticket = $this->ticketManager->provideOne($ticketPrice);

            try {
                DB::transaction(function () use ($booking, $ticket, $validated) {
                    $ticket->soldBy = User::requireMobileAppUser()->username;
                    $ticket->save();
                    $booking->ticket()->associate($ticket);
                    $seat = null;
                    if (isset($validated['seat_id'])) {
                        $seat = $booking->bus->seats()->where('seat_id', $validated['seat_id'])->first();
                    }
                    if ($seat == null) {
                        $seat = $booking->bus->getAvailableSeats()->first();
                    }
                    if ($seat == null) {
                        throw new \RuntimeException("Il n'y a pas de place disponible pour ce bus !");
                    }
                    $seat->book();
                    $seat->save();
                    $booking->seat()->associate($seat);
                    $booking->save();
                });
            } catch (\RuntimeException $e) {
                $booking->delete();

                return response()->json(['message' => $e->getMessage()], 422);
            }
        } else {
            $booking->save();
        }

        return response()->json($booking);

    }

    /**
     * Display the specified resource.
     */
    public function show(Booking $booking)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Booking $booking)
    {
        //
    }

    /**
     * Update the pickup point (point de départ) and destination of a booking.
     *
     * Only pickup points and destinations that belong to the same trajet as the
     * booking's depart are accepted: offering pickup points from another trajet
     * would not be coherent.
     */
    public function update(Request $request, Booking $booking): JsonResponse
    {
        $trajetId = $booking->depart->trajet_id;

        $validated = $request->validate([
            'point_dep_id' => [
                'required',
                Rule::exists('point_deps', 'id')->where('trajet_id', $trajetId),
            ],
            'destination_id' => [
                'required',
                Rule::exists('destinations', 'id')->where('trajet_id', $trajetId),
            ],
        ], [
            'point_dep_id.exists' => "Ce point de départ n'appartient pas au trajet de la réservation.",
            'destination_id.exists' => "Cette destination n'appartient pas au trajet de la réservation.",
        ]);

        $booking->update($validated);

        return response()->json(['message' => 'Réservation mise à jour avec succès.']);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Booking $booking)
    {
        ($booking->hasTicket() ? PermissionName::CancelPaidBooking : PermissionName::CancelUnpaidBooking)
            ->authorizeForCurrentUser();

        $this->cancelBooking($booking);

        return response()->noContent();
    }
    // url to trigger wave paiement:  mobile/payment/wave/trigger_payment/booking/186505
    // url trigger om payment mobile/payment/om/init/booking/186505
    // url to save ticket payment bookings/186505/save_ticket_payment

    public function triggerPaymentRequestForPaymentMethod(Booking $booking, $paymentMethod, Request $request)
    {
        if ($request->routeIs('back-office.*')) {
            if (! in_array($paymentMethod, ['wave', 'om'], true)) {
                return back()->with('error', 'Méthode de paiement non supportée.');
            }

            try {
                if ($paymentMethod === 'wave') {
                    $this->ticketManager->triggerWavePayment($booking);
                } else {
                    $this->ticketManager->triggerOmPayment($booking);
                }
            } catch (\Throwable $exception) {
                return back()->with('error', $exception->getMessage());
            }

            return back()->with('status', 'Relance de paiement '.strtoupper($paymentMethod).' envoyée à '.$booking->customer->full_name.'.');
        }

        if ($paymentMethod == 'wave') {
            return $this->ticketManager->triggerWavePayment($booking);
        } elseif ($paymentMethod == 'om') {
            return $this->ticketManager->triggerOmPayment($booking);
        }

        return response()->json(['message' => 'Méthode de paiement non supportée'], 422);

    }

    /**
     * @throws Exception
     */
    public function saveTicketPayment(Booking $booking, Request $request)
    {
        try {
            // Only 'wave' and 'om' are meaningful here: this endpoint is only ever reached
            // through a back-office staff action, never the online payment webhooks, so a
            // wave/om value means a support agent is manually recovering a payment whose
            // provider callback failed (customer called in with proof). Anything else falls
            // back to the ordinary in-person "encaisser en espèces" cash collection.
            $paymentMethod = strtolower((string) $request->input('payment_method', 'cash'));
            if (! in_array($paymentMethod, ['wave', 'om'], true)) {
                $paymentMethod = 'cash';
            }

            $ticketPrice = $this->ticketManager->calculateTicketPriceForBooking($booking, $paymentMethod);
            $ticket = $this->ticketManager->provideOne($ticketPrice);
            $soldBy = \request()->user()?->username ?? 'system';

            DB::transaction(function () use ($booking, $ticket, $paymentMethod, $soldBy) {
                $ticket->soldBy = $soldBy;
                $ticket->payment_method = $paymentMethod;
                $ticket->save();
                $booking->ticket()->associate($ticket);
                $seat = $booking->bus->getAvailableSeats()->first();
                if ($seat == null) {
                    throw new \RuntimeException("Il n'y a pas de place disponible pour ce bus !");
                }
                $seat->book();
                $seat->save();
                $booking->seat()->associate($seat);
                $booking->paye = true;
                $booking->save();
            });
            app(NotificationService::class)->notifyCustomerOfTicketPayment($booking, true);
            $bookingManager = app(BookingManager::class);
            $bookingManager->checkIfBusIsFullAndNotifyManagerIfYes($booking);

            if ($paymentMethod === 'wave' || $paymentMethod === 'om') {
                $customerFullName = normalize_passenger_display_name($booking->customer->full_name);
                RecordTicketPaymentInCaisse::dispatch(
                    $ticket->id,
                    'MANUAL_TICKET_PAYMENT',
                    "Paiement manuel (preuve fournie) — billet #{$ticket->number} — client {$customerFullName} — encaissé par {$soldBy}",
                    \request()->user()?->id,
                );
            } else {
                RecordTicketPaymentInCaisse::dispatch($ticket->id);
            }

            if ($request->routeIs('back-office.*')) {
                return back()->with('status', 'Paiement encaissé pour '.$booking->customer->full_name.'.');
            }

            return response()->json('Paiement effectué avec succès !');
        } catch (Exception $e) {
            if ($request->routeIs('back-office.*')) {
                return back()->with('error', $e->getMessage());
            }

            return response()->json(['message' => $e->getMessage()], 422);
        }

    }

    public function transferBooking(Booking $booking, Bus $targetBus)
    {
        PermissionName::authorizeBookingTransferForCurrentUser($booking->depart->isPassed());

        if ($booking->bus->id == $targetBus->id) {
            return response()->json(['message' => 'Vous ne pouvez pas transférer une réservation sur le même bus'], 422);
        }
        if ($targetBus->isFull()) {
            return response()->json(['message' => "Il n'y a pas de place disponible pour ce bus !"], 422);
        }
        $targetSeat = $targetBus->getAvailableSeats()->first();
        if ($targetSeat == null) {
            return response()->json(['message' => 'Impossible de trouver un siège disponible pour ce bus !'], 422);
        }
        if ($booking->depart->isPassed()) {
            if (\request()->user()?->username !== 'pdg_34') {
                return response()->json(['message' => 'Impossible de transférer une réservation depuis un départ déjà passé'], 422);
            }
        }

        $sourceBusId = $booking->bus_id;
        $sourceSeatId = $booking->seat_id;
        $sourceSeatNumber = $booking->seat_number;
        $actingUserId = auth()->id();

        DB::transaction(function () use ($booking, $targetBus, $targetSeat, $sourceBusId, $sourceSeatId, $sourceSeatNumber, $actingUserId) {

            $booking->bus()->associate($targetBus);
            $booking->depart()->associate($targetBus->depart);
            $booking->save();
            if ($booking->has_seat) {
                $previousSeat = $booking->seat;
                $previousSeat?->freeSeat();
                $previousSeat?->save();
                $booking->freeSeat();
                $booking->save();

                $targetSeat->book();
                $targetSeat->save();
                $booking->seat()->associate($targetSeat);
                $booking->save();
            }

            BookingTransfer::create([
                'booking_id' => $booking->id,
                'source_bus_id' => $sourceBusId,
                'target_bus_id' => $targetBus->id,
                'source_seat_id' => $sourceSeatId,
                'target_seat_id' => $booking->seat_id,
                'source_seat_number' => $sourceSeatNumber,
                'target_seat_number' => $booking->seat_id !== null ? $targetSeat->number : null,
                'transfer_type' => BookingTransferType::Individual,
                'user_id' => $actingUserId,
                'transferred_at' => now(),
            ]);
        });
        $booking->refresh();
        app(NotificationService::class)->notifyCustomerOfBookingTransfer($booking, $targetBus, $targetSeat);
        $bookingManager = app(BookingManager::class);
        $bookingManager->checkIfBusIsFullAndNotifyManagerIfYes($booking);

        return response()->json('Réservation transférée avec succès');

    }

    public function sendScheduleNotification(Booking $booking, Request $request)
    {
        PermissionName::SendMessages->authorizeForCurrentUser();

        $data = $request->validate([
            'message' => 'required|string',
        ]);
        $response = app(NotificationService::class)->sendCustomMessageToCustomer($booking, $data['message']);

        return response()->json(['sent' => $response, 'message' => $data['message']]);

    }

    public function refundTicket(Booking $booking)
    {
        PermissionName::RefundTicket->authorizeForCurrentUser();

        if (! $booking->hasTicket()) {
            return response()->json(['message' => "Cette réservation n'a pas de ticket à rembourser"], 422);
        }
        if ($booking->deleted_at != null || $booking->ticket?->deleted_at != null) {
            return response()->json(['message' => 'Cette réservation a déjà été annulée'], 422);
        }
        if ($booking->ticket?->payment_method != 'wave') {
            return response()->json(['message' => "Cette réservation n'a pas été payée par Wave"], 422);
        }
        // TODO un comment later
        //        if ($booking->depart->isPassed()) {
        //            return response()->json(['message' => "Impossible de rembourser une réservation pour un départ déjà passé"], 422);
        //        }
        $ticket = $booking->ticket;

        if ($booking->sharesPaymentWithOtherBookings()) {
            // Refunding the shared Wave transaction would refund every booking of the group.
            $this->cancelBooking($booking);
            $this->reverseTicketSaleLedgerEntry($ticket);
            $managerWasNotified = app(BookingManager::class)->requestManualPartialRefund($booking);

            return response()->json([
                'message' => $managerWasNotified
                    ? "Réservation annulée. Elle a été payée avec d'autres réservations : le remboursement Wave doit être fait manuellement, le responsable a été prévenu par SMS."
                    : "Réservation annulée, mais le SMS au responsable n'a pas pu être envoyé. Prévenez-le : le remboursement Wave de ce paiement groupé doit être fait manuellement.",
                'manualRefundRequested' => true,
            ]);
        }

        $this->cancelBooking($booking);
        $this->reverseTicketSaleLedgerEntry($ticket);

        return WavePaiementController::refundTransaction(
            $ticket?->comment,
        );
    }

    /**
     * Reverse the ticket sale's ledger entry on refund (see
     * AccountService::reverseTicketSaleForRefund). Never blocks the
     * customer-facing refund/cancellation itself — a ledger failure (e.g. the
     * TicketSales account no longer holds enough balance because it was
     * already spent) is logged for manual follow-up instead of surfacing an
     * error to the agent mid-refund.
     */
    private function reverseTicketSaleLedgerEntry(?Ticket $ticket): void
    {
        if ($ticket === null) {
            return;
        }

        try {
            app(AccountService::class)->reverseTicketSaleForRefund($ticket);
        } catch (\Throwable $exception) {
            Log::error("Failed to reverse ledger entry for refunded ticket #{$ticket->id}: {$exception->getMessage()}");
        }
    }

    public function cancelBooking(Booking $booking): void
    {
        DB::transaction(function () use ($booking) {
            $seat = $booking->seat;
            $seat?->freeSeat();
            $seat?->save();
            $booking->freeSeat();
            $booking->save();
            $booking->delete();

            return response()->noContent();

        });
    }
}
