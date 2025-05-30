<?php

namespace App\Http\Controllers;

use App\Http\Resources\BookingForExportResource;
use App\Http\Resources\BookingResource;
use App\Manager\BusManager;
use App\Models\Booking;
use App\Models\Bus;
use App\Models\BusSeat;
use App\Models\Depart;
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
            'gp_ticket_price' => 'numeric',
            'visibilite' => 'integer',
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
    public function getBusSeats(Request $request, $departId): JsonResponse
    {
        try {
            $busId = Depart::find($departId)->getBusForBooking(climatise: is_request_for_gp_customers());
            // Generate seats data (in real app, fetch from database)
            $seatsData = $this->generateBusSeatsData($busId);

            return response()->json([
                'success' => true,
                'data' => $seatsData,
                'message' => 'Bus seats data retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            dump($e);
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Error retrieving bus seats data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate bus seats data structure
     *
     * @param string $busId
     * @return array
     */
    private function generateBusSeatsData(Bus $bus): array
    {
        $seats = [];
        $layout = json_decode($bus->vehicule->template);
        foreach ($layout as $index => $slotRow) {
            foreach ($slotRow as $slotIndex => $slot) {
                if (isset($slot->seat)) {
                    // Get seat status from database
                    $seatStatus = $bus->seats()
                        ->join("seats", "bus_seats.seat_id", "=", "seats.id")
                        ->select("bus_seats.*")
                        ->where("number", $slot->seat->number)
                        ->first();

                    // Update seat status directly in the layout object
                    $slotRow[$slotIndex]->seat->status = $seatStatus?->booked ? "booked" : "available";

                    // Add seat to seats array for easy access
                    $seats[$slot->seat->number] = [
                        'number' => $slot->seat->number,
                        'status' => $slotRow[$slotIndex]->seat->status,
                        'row_index' => $index,
                        'slot_index' => $slotIndex
                    ];
                }
            }
        }

        foreach ($bus->seats as $i => $seat) {
            $seats[$i+1] = [
                'id' => "seat-{$seat->number}",
                'number' => $seat->number,
                'status' => $seat->booked ? 'booked' : 'available', // 70% available, 30% booked
                'isDriver' => false,
                'price' => $seat->price, // Random price between 20-50 (in cents)
                'position' => $this->getSeatPosition($i+1)
            ];
        }

        // Add driver seat
        $seats['driver'] = [
            'id' => 'driver',
            'number' => 'D',
            'status' => 'booked',
            'isDriver' => true,
            'price' => 0,
            'position' => 'front-left'
        ];

        return [
            'busId' => $bus->id,
            'name' => $bus->name,
            'totalSeats' => $bus->nombre_place, // 34 passengers + driver
            'availableSeats' => count(array_filter($seats, fn($seat) => $seat['status'] === 'available')),
            'layout' => $layout,
        ];
    }

    /**
     * Get seat position description
     *
     * @param int $seatNumber
     * @return string
     */
    private function getSeatPosition($seatNumber): string
    {
        $positions = [
            34 => 'front-right',
            1 => 'front-left-1', 2 => 'front-left-2',
            3 => 'front-right-1', 4 => 'front-right-2',
            5 => 'row2-left-1', 6 => 'row2-left-2',
            7 => 'row2-right-1', 8 => 'row2-right-2',
            9 => 'row3-left-1', 10 => 'row3-left-2',
            11 => 'row3-right-1', 12 => 'row3-right-2',
            13 => 'row4-left-1', 14 => 'row4-left-2',
            15 => 'row4-right-1', 16 => 'row4-right-2',
            17 => 'row5-left-1', 18 => 'row5-left-2',
            19 => 'row5-right-1', 20 => 'row5-right-2',
            21 => 'row6-left-1', 22 => 'row6-left-2',
            23 => 'row6-right-1', 24 => 'row6-right-2',
            25 => 'row7-left-1', 26 => 'row7-left-2',
            27 => 'row7-right-1', 28 => 'row7-right-2',
            29 => 'row8-left-1', 30 => 'row8-left-2',
            31 => 'back-left', 32 => 'back-center-left',
            33 => 'back-center-right'
        ];

        return $positions[$seatNumber] ?? "seat-{$seatNumber}";
    }

    /**
     * Get bus layout configuration
     *
     * @return array
     */
    private function getBusLayout(): array
    {
        return [
            'type' => 'luxury-express',
            'rows' => 9,
            'seatsPerRow' => 4,
            'hasAisle' => true,
            'specialFeatures' => [
                'driver_seat' => true,
                'luggage_compartment' => true,
                'doors' => 2
            ]
        ];
    }

    /**
     * Book selected seats
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function bookSeats(Request $request): JsonResponse
    {
        $request->validate([
            'bus_id' => 'required|string',
            'seats' => 'required|array|min:1|max:8',
            'seats.*.id' => 'required|string',
            'seats.*.number' => 'required',
            'passenger_info' => 'required|array',
            'passenger_info.name' => 'required|string|max:255',
            'passenger_info.phone' => 'required|string|max:20',
            'passenger_info.email' => 'required|email|max:255'
        ]);

        try {
            // In a real application, you would:
            // 1. Check seat availability in database
            // 2. Create booking record
            // 3. Update seat status
            // 4. Send confirmation email/SMS

            $bookingData = [
                'booking_id' => 'BK-' . strtoupper(uniqid()),
                'bus_id' => $request->input('bus_id'),
                'seats' => $request->input('seats'),
                'passenger_info' => $request->input('passenger_info'),
                'total_amount' => count($request->input('seats')) * 3500, // 35 MAD per seat
                'booking_date' => now()->toISOString(),
                'status' => 'confirmed'
            ];

            return response()->json([
                'success' => true,
                'data' => $bookingData,
                'message' => 'Seats booked successfully'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Error booking seats',
                'error' => $e->getMessage()
            ], 500);
        }
    }


}
