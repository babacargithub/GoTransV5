<?php

namespace App\Http\Controllers;

use App\Http\Resources\BookingForExportResource;
use App\Http\Resources\BookingResource;
use App\Manager\BusManager;
use App\Models\Booking;
use App\Models\Bus;
use App\Models\BusSeat;
use App\Models\Vehicule;
use App\Utils\NotificationSender\SMSSender\SMSSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BusController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        /**
         * response looks like this

         */

        // return buses whose depart date is not passed ordered by depart date  and bus name

        return Bus::join('departs', 'buses.depart_id', '=', 'departs.id')

            ->where('departs.date', '>', now())
            ->select('buses.*') // Select only bus columns to avoid duplicate depart records
            ->orderBy('departs.date')
            ->orderBy('buses.name')
//            ->distinct()        // Ensure unique buses in the result
            ->get()->map(function ($bus) {
                return [
                    "id" => $bus->id,
                    "fullName" => $bus->full_name,
                ];
            });


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
    public function show(Bus $bus)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Bus $bus)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Bus $bus)
    {
        //
        $validated = $request->validate([
            'name' => 'string',
            'nombre_place' => 'integer',
            'ticket_price' => 'numeric',
        ]);
        $bus->update($validated);
        return response()->json($bus);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Bus $bus)
    {
        if ($bus->bookings()->count() > 0) {
            return response()->json(['message' => 'Le bus que vous voulez supprimer contient des réservations, il faut les transférer d\'abord
            '], 422);
        }
        DB::transaction(function () use ($bus) {
            $bus->depart->heuresDeparts()->where('bus_id', $bus->id)->delete();
            $bus->seats()->delete();
            $bus->delete();
        });
        return response()->noContent();

    }

    public function bookings(Bus $bus)
    {
        return response()->json(BookingResource::collection($bus->bookings));
    }

    public function busTicketSales(Bus $bus)
    {
        $ticketSales = Booking::where('bus_id', $bus->id)
            ->whereNotNull('ticket_id')
            ->join('tickets', 'bookings.ticket_id', '=', 'tickets.id')
            ->selectRaw('tickets.soldBy as soldBy, SUM(tickets.price) as total')
            ->groupBy('tickets.soldBy')
            ->get();;

        return response()->json($ticketSales);
    }

    public function toggleClose(Bus $bus, Request $request)
    {

        $validate = $request->validate([
            'closed' => 'required|boolean',
        ]);
        $bus->closed =  $validate['closed'];
        $bus->save();
        $bus->refresh();
        try {
            if ($bus->closed){
                $smsSender = new SmsSender();
                $response = $smsSender->sendSms(773300853, "Bus ".${$bus->full_name} ." a été cloturé par currentUser");

            }
        }catch (\Exception $e){
        }
        return response()->json(['closed' => $bus->closed]);

    }
    public function toggleBusSeatVisibility(Bus $bus)
    {
        $bus->should_show_seat_numbers = !$bus->should_show_seat_numbers;
        $bus->save();
        return response()->json(['show_seat_numbers' => $bus->should_show_seat_numbers]);

    }
    public function seats(Bus $bus)
    {
        return response()->json($bus->seats->map(function (BusSeat $seat) {
            return [
                'id' => $seat->id,
                'number' => $seat->seat->number,
                'booked' => $seat->booked,
                'bookedAt' => $seat->booked_at,
                'available' => !$seat->booked,
                'price' => $seat->seat->price,
                "position_in_bus" => $seat->seat->positionInBus,
            ];
        }));


    }

    public function transferBookings(Bus $sourceBus, Request $request)
    {
        $validated = $request->validate([
            'targetBusId' => 'required|integer',
            'numberOfBookingsToTransfer' => 'required|integer',
            'transferType' => 'required|integer',
        ]);
        $targetBus = Bus::findOrFail($validated['targetBusId']);
        $transferData = [
            'numberOfBookingsToTransfer' => $validated['numberOfBookingsToTransfer'],
            'transferType' => $validated['transferType'],
        ];
        $busManager = new BusManager();
        $response = $busManager->transferBookings($sourceBus, $targetBus, $transferData);
        if ($response instanceof JsonResponse) {
            return $response;
        }

        return response()->json(['message' => 'Les réservations ont été transférées avec succès']);
    }

    public function bookingsForExport(Bus $bus)
    {
        //  order bookings by seat number or by pointDep.position according to trajet id
        // if trajet id is 1, order by seat number, if trajet id is 2, order by pointDep.position
        $query = $bus->bookings()->getQuery();
       $query = Booking::bookingsOrdererByTrajet($bus->depart->trajet, $query);
        $bookings = $query->get();


        $response = BookingForExportResource::collection($bookings);
        return response()->json($response);
    }

    public function vehicules()
    {

        return response()->json(Vehicule::all()->map(function (Vehicule $vehicule) {
            return [
                'id' => $vehicule->id,
                'name' => $vehicule->name. " - ".$vehicule->nombre_place." places",
                'features' => $vehicule->features,
                "type_vehicle" => $vehicule->vehicule_type,
                "nombre_place" => $vehicule->nombre_place,
                "ticket_price" => $vehicule->climatise ? 4000 : 3550,

            ];
        }));

    }


}
