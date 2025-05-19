<?php

namespace App\Http\Controllers;

use App\Manager\BookingManager;
use App\Manager\TicketManager;
use App\Models\Booking;
use App\Models\Bus;
use App\Models\Customer;
use App\Models\Depart;
use App\Models\User;
use App\Utils\NotificationSender\SMSSender\SMSSender;
use DB;
use Exception;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    private TicketManager $ticketManager;

    /**
     * @param TicketManager $ticketManager
     */
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
     * Store a newly created resource in storage.
     * @throws Exception
     */
    public function store(Depart $depart, Request $request)
    {
        //
        $validated = $request->validate([
            'seat_id' => 'exists:seats,id',
            'customer_id' => 'required|exists:customers,id',
            "point_dep_id" => 'required|exists:point_deps,id',
            "destination_id" => 'required|exists:destinations,id',
            "bus_id" => 'integer|exists:buses,id',
            'ticket_paid' => 'boolean',
        ]);
        if ($depart->isFull()) {
            return response()->json(['message' => "Il n'y a pas de place disponible pour ce depart !"], 422);
        }
        $busForBooking = $validated['bus_id']??0 ? $depart->buses()->find($validated['bus_id']) :
            $depart->getBusForBooking();
        if ($busForBooking == null) {
            return response()->json(['message' => "Il n'y a pas de place disponible pour ce bus !"], 422);
        }
        // check if customer has already booked for this depart
        if ($depart->bookings()->where('customer_id', $validated['customer_id'])->exists()) {
            $customer = Customer::find($validated['customer_id']);
            return response()->json(['message' => $customer->full_name. " a déjà réservé sur ce depart !"], 422);
        }
        $booking = new Booking($validated);
        $booking->paye = false;
        $booking->online = true;

        $booking->depart()->associate($depart);
        $booking->bus()->associate($busForBooking);
        $busForBooking->bookings()->save($booking);
        $booking->withoutRelations();
        if (isset($validated["ticket_paid"] ) && $validated["ticket_paid"]) {
            $ticket = $this->ticketManager->provideOne($booking->bus->ticket_price);

            $ticket->soldBy = User::requireMobileAppUser()->username;
            $ticket->save();
            $booking->ticket()->associate($ticket);
            $seat = null;
            if (isset($validated["seat_id"])){
                $seat = $booking->bus->seats()->find($validated["seat_id"]);
            }
            if ($seat == null){
                $seat = $booking->bus->getAvailableSeats()->first();
            }
            if ($seat == null){
                return response()->json(['message' => "Il n'y a pas de place disponible pour ce bus !"], 422);
            }
            $seat->book();
            $seat->save();
            $booking->seat()->associate($seat);

        }
        $booking->save();

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
     * Update the specified resource in storage.
     */
    public function update(Request $request, Booking $booking)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Booking $booking)
    {
        //
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
    //url to trigger wave paiement:  mobile/payment/wave/trigger_payment/booking/186505
    // url trigger om payment mobile/payment/om/init/booking/186505
    // url to save ticket payment bookings/186505/save_ticket_payment

    public function triggerPaymentRequestForPaymentMethod(Booking $booking, $paymentMethod)
    {
        if ($paymentMethod == "wave") {
            return $this->ticketManager->triggerWavePayment($booking);
        } else if ($paymentMethod == "om") {
            return $this->ticketManager->triggerOmPayment($booking);
        }
        return response()->json(['message' => "Méthode de paiement non supportée"], 422);

    }

    /**
     * @throws Exception
     */
    public function saveTicketPayment(Booking $booking)
    {
        try {
            $ticket = $this->ticketManager->provideOne($booking->bus->ticket_price);
             DB::transaction(function ()use ($booking, $ticket){
                $ticket->soldBy = \request()->user()?->username ?? "system";
                $ticket->save();
                $booking->ticket()->associate($ticket);
                $seat = $booking->bus->getAvailableSeats()->first();
                if ($seat == null) {
                    return response()->json(['message' => "Il n'y a pas de place disponible pour ce bus !"], 422);
                }
                $seat->book();
                $seat->save();
                $booking->seat()->associate($seat);
                $booking->paye = true;
                $booking->save();
            return true;
            });
             $bookingManager = app(BookingManager::class);
             $bookingManager->sendNotificationOfTicketPaymentToCustomer($booking, true);
             $bookingManager->checkIfBusIsFullAndNotifyManagerIfYes($booking);

            return response()->json("Paiement effectué avec succès !");
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

    }

    public function transferBooking(Booking $booking, Bus $targetBus)
    {
        if ($booking->bus->id == $targetBus->id) {
            return response()->json(['message' => "Vous ne pouvez pas transférer une réservation sur le même bus"], 422);
        }
        if ($targetBus->isFull()) {
            return response()->json(['message' => "Il n'y a pas de place disponible pour ce bus !"], 422);
        }
        $targetSeat = $targetBus->getAvailableSeats()->first();
        if ($targetSeat == null) {
            return response()->json(['message' => "Impossible de trouver un siège disponible pour ce bus !"], 422);
        }
        DB::transaction(function () use ($booking, $targetBus, $targetSeat) {


            $booking->bus()->associate($targetBus);
            $booking->depart()->associate($targetBus->depart);
            $booking->save();
            if ($booking->has_seat){
                $previousSeat = $booking->seat;
                $previousSeat?->freeSeat();
                $previousSeat?->save();
                $booking->freeSeat();
                $booking->save();

                $targetSeat->book();
                $targetSeat->save();
                $booking->seat()->associate($targetSeat);
            }

        });
        $booking->refresh();
        $smsSender = app(SMSSender::class);
        $smsSender->sendSms(substr($booking->customer->phone_number, -9, 9),
            "Votre réservation a été transférée sur le départ " . $targetBus->depart->name. " sur le bus ".
            $targetBus->name. " Nouveau Nº de siège ". $targetSeat->number." Contact 771273535/771163003");
        $bookingManager = app(BookingManager::class);
        $bookingManager->checkIfBusIsFullAndNotifyManagerIfYes($booking);
        return response()->json('Réservation transférée avec succès');



    }
    public function sendScheduleNotification(Booking $booking, Request $request)
    {
        $data = $request->validate([
            'message' => 'required|string',
        ]);
        $smsSender = app(SmsSender::class);
        $response = $smsSender->sendSms(substr($booking->customer->phone_number, -9, 9), $data['message']);
        return response()->json(["sent"=>$response, "message"=>$data['message']]);


    }
}
