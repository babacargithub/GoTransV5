<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Bus;
use App\Models\Depart;
use App\Models\Discount;
use App\Models\PromotionalMessage;
use App\Models\Ticket;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // find total tickets sold by payment method of current departs
        $balancesByPaymentMethods = Booking::join('tickets', 'bookings.ticket_id', '=', 'tickets.id')
            ->join('departs', 'bookings.depart_id', '=', 'departs.id')
            ->where('departs.date', '>', now())
            ->selectRaw('sum(tickets.price) as total, tickets.payment_method as paymentMethod')
            ->groupBy('tickets.payment_method')
            ->get();
        $waveController = app(WavePaiementController::class);
        $orangeMoneyController = app(OrangeMoneyController::class);

        $waveBalance = $waveController->getBalance();
        $orangeMoneyBalance = $orangeMoneyController->balance();

        return response()->json([
            "balances_per_payment_methods" => $balancesByPaymentMethods,
            "wave_balance" => $waveBalance,
            "orange_money_balance" => intval(json_decode($orangeMoneyBalance->content(),true)["balance"]),

        ]);

    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Ticket $ticket)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Ticket $ticket)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Ticket $ticket)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Ticket $ticket)
    {
        //
    }
    public function discounts()
    {
        return response()->json([
            "discounts" => Discount::all(),
            "promotionalMessages" => PromotionalMessage::all(),
            "departs" => Depart::where('date', '>', now())->get()->map(function ($depart) {
                return [
                    "id" => $depart->id,
                    "name" => $depart->identifier(),
                ];
            }),
            "buses"=> Bus::join('departs', 'buses.depart_id', '=', 'departs.id')
                ->where('departs.date', '>', now())
                ->get()
                ->map(function ($bus) {
                    return [
                        "id" => $bus->id,
                        "name" => $bus->full_name,
                    ];
                })

        ]);

    }
}
