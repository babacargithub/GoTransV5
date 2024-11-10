<?php

namespace App\Http\Controllers;

use App\Http\Resources\BookingForExportResource;
use App\Http\Resources\BookingResource;
use App\Http\Resources\DepartResource;
use App\Models\Booking;
use App\Models\Bus;
use App\Models\BusSeat;
use App\Models\Depart;
use App\Models\Event;
use App\Models\HeureDepart;
use App\Models\Horaire;
use App\Models\PointDep;
use App\Models\Seat;
use App\Models\Trajet;
use App\Models\Vehicule;
use App\Models\WaitingCustomer;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class DepartController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
        return DepartResource::collection(Depart::where('date', '>', now())->get());
    }


    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // request payload looks like this

        $validated = $request->validate([
            'departs' => 'required|array',
            'departs.*.name' => 'required|string',
            'departs.*.date' => 'required|date',
            'departs.*.event_id' => 'required|integer|exists:events,id',
            'departs.*.trajet_id' => 'required|integer|exists:trajets,id',
            'departs.*.visibilite' => 'required|integer',
            'departs.*.horaire_id' => 'required|integer|exists:horaires,id',
        ]);
        $departs = [];
        foreach ($validated['departs'] as $depart) {
            $departs[] = new Depart([
                'name' => $depart['name'],
                'date' => $depart['date'],
                'event_id' => $depart['event_id'],
                'trajet_id' => $depart['trajet_id'],
                'visibilite' => $depart['visibilite'],
                'horaire_id' => $depart['horaire_id'],
                "closed" => false,
                "canceled" => false,
                "locked" => false,
            ]);
        }
        DB::transaction(function () use ($departs) {
            foreach ($departs as $depart) {
                $defaultBus = new Bus([
                    'name' => 'Bus 1',
                    'depart_id' => $depart->id,
                    'ticket_price' => 3550,
                    'nombre_place' => 57,
                    "closed" => false,
                    "vehicule_id" => Vehicule::first()?->id,
                    'allows_seat_selection' => false,
                    'should_show_seat_numbers' => false,

                ]);
                // create seats for bus
                $depart->save();
                $depart->buses()->save($defaultBus);
                $busSeats = $this->generateBusSeats($defaultBus);
                $defaultBus->seats()->createMany($busSeats->toArray());
                $busStopSchedules = $this->generateDefaultBusStopSchedules($depart, $defaultBus, Horaire::findOrFail
                ($depart->horaire_id));
                $depart->heuresDeparts()->createMany($busStopSchedules->toArray());
            }
        });

        return response()->json($departs, Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(Depart $depart)
    {
        //

        return $depart;
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Depart $depart)
    {
        //
        $validated = $request->validate([
            'name' => 'string',
            'date' => 'date',
            'horaire_id' => 'integer|exists:horaires,id',
            'event_id' => 'integer|exists:events,id',
            'visibilite' => 'integer',
        ]);

        $depart->update($validated);
        return response()->json($depart);

    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Depart $depart)
    {
        //
        return $this->cancelDepart($depart);
    }

    public function addBusToDepart(Depart $depart, Request $request)
    {
        $validated = $request->validate([
            "name" => "required|string",
            "ticket_price" => "required|numeric",
            "nombre_place" => "required|integer",
            "vehicule_id" => "nullable|integer",
        ]);
        // validate name bus is unique for depart
        if ($depart->buses()->where('name', $validated['name'])->exists()) {
            return response()->json(['message' => 'Un bus avec le même nom est déja crée !'],
                Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $bus = new Bus($validated);
        if (!isset($validated['vehicule_id'])) {
            $bus->vehicule_id = Vehicule::where("default",true)->firstOrFail()->id;
        }
       DB::transaction(function () use ($depart, $validated, $bus)
        {
            $bus->closed = false;
            $depart->buses()->save($bus);
            $busSeats = $this->generateBusSeats($bus);
            $bus->seats()->createMany($busSeats->toArray());

        });
       return response()->json($bus,Response::HTTP_CREATED);


    }

    public function bookingGroupingsCount(Depart $depart)
    {

        // count bookings of each point depart
        $groupingsCount = Booking::where('depart_id', $depart->id)
            ->join('point_deps', 'bookings.point_dep_id', '=', 'point_deps.id')
            ->whereNotNull('ticket_id')
            ->selectRaw('point_deps.name as name, COUNT(bookings.id) as bookingsCount')
            ->groupBy('point_deps.id','point_deps.name')
            ->orderBy('point_deps.id')
            ->get();

        return response()->json($groupingsCount);


    }
    public function busStopSchedules(Depart $depart)
    {
        // get all heures departs and point departs
        $busStopSchedules = $depart->heuresDeparts()->orderBy("point_dep_id")->get();

        return response()->json($busStopSchedules->map(function(HeureDepart $busStopSchedule){
            return [
                "id" => $busStopSchedule->id,
                'pointDep' => $busStopSchedule->pointDep->name,
                'rendezVousPoint' => $busStopSchedule->pointDep->arret_bus,
                'rendezVousSchedule' => $busStopSchedule->heureDepart->format('H:i'),
            ];
        }));


    }
    public function updateBusStopSchedules(Depart $depart, Request $request)
    {

        $validated = $request->validate([
            'busStopSchedules' => 'required|array',
            'busStopSchedules.*.id' => 'required|integer|exists:heure_departs,id',
            'busStopSchedules.*.pointDep' => 'required|string',
            'busStopSchedules.*.rendezVousPoint' => 'required|string',
            'busStopSchedules.*.rendezVousSchedule' => 'required|date_format:H:i',
        ]);
        $busStopSchedules = collect($validated['busStopSchedules'])
        ->map(function($busStopSchedule){
            return [
                'id' => $busStopSchedule['id'],
                'heureDepart' => $busStopSchedule['rendezVousSchedule'],
                'arretBus' => $busStopSchedule['rendezVousPoint'],
            ];
        })->toArray();
        DB::transaction(function() use ($busStopSchedules){
            foreach ($busStopSchedules as $busStopSchedule) {
                HeureDepart::where('id', $busStopSchedule['id'])
                    ->update([
                        'heureDepart' => $busStopSchedule['heureDepart'],
                        'arretBus' => $busStopSchedule['arretBus'],
                    ]);
            }
        });
    }
    public function bookingsForNotification(Depart $depart)
    {

        $bookings = $depart->bookings->map(function(Booking $booking){
            return [
                "id" => $booking->id,
                "client" => $booking->customer->nom,
                "clientShort" => $booking->customer->shortName,
                "phoneNumber" => $booking->customer->phone_number,
                "pointDep" => $booking->point_dep->name,
                "destination" => $booking->destination->name,
                "bus" => $booking->bus?->name,
                "schedule" => $booking->formatted_schedule,
                "formattedSchedule" => $booking->formatted_schedule,
                "rendezVousPoint" => $booking->point_dep->arret_bus,
                "seatNumber" => $booking->seat_number,
                "ticketSoldBy" => $booking->ticket?->soldBy,
                "hasSeat" => $booking->seat_id != null,
                "hasTicket" => $booking->ticket_id != null,
            ];
        });

        return response()->json([
            'depart' => $depart->name,
            'bookings' => $bookings,
            "buses"=> $depart->buses->map(fn($bus) => $bus->name),
            "destinations"=> $depart->trajet->destinations->map(fn($destination) => $destination->name),
            "pointDeparts"=> $depart->trajet->pointDeps->map(fn(PointDep $pointDep) => $pointDep->name),


        ]);

    }
    public function ticketSales(Depart $depart)
    {
        //  Select ticket sales for depart and group by soldBy
        $ticketSales = Booking::where('depart_id', $depart->id)
            ->whereNotNull('ticket_id')
            ->join('tickets', 'bookings.ticket_id', '=', 'tickets.id')
            ->selectRaw('tickets.soldBy as soldBy, SUM(tickets.price) as total')
            ->groupBy('tickets.soldBy')
            ->get();
        return response()->json($ticketSales);


    }
    public function cancelDepart(Depart $depart)
    {
        // cancel depart
        if ($depart->bookings()->count() > 0) {
            $depart->cancel();
            $depart->save();
            return response()->noContent();
        }else{
            DB::transaction(function() use ($depart){
                $depart->heuresDeparts()->delete();
                BusSeat::whereIn("bus_id", $depart->buses->pluck('id'))->delete();
                $depart->buses()->delete();
                $depart->delete();
            });

        }

        return response()->noContent();

    }

    /**
     * @param Bus $bus
     * @return Collection
     */
    function generateBusSeats(Bus $bus): Collection
    {
        $seats = Seat::
            limit($bus->vehicule != null ? $bus->vehicule->nombre_place : $bus->nombre_place)
            ->orderBy("number")
            ->get();
        // transform seats to bus seats
        return $seats->map(function (Seat $seat) {
            return new BusSeat([
                'seat_id' => $seat->id,
                'booked' => false,
                'price' => 3550,
            ]);
        });
    }

    public function bookingsCount()
    {
        $data = [];
        $departs = Depart::where('date', '>', now())->get();
        foreach ($departs as $depart) {
            $buses = $depart->buses;
            $busData = [];
            foreach ($buses as $bus) {
                $bookingsCount = $bus->bookings->count();
                $bookedSeatsCount = $bus->seats->where('booked', true)->count();
                $ticketsSoldCount = $bus->bookings->whereNotNull('ticket_id')->count();
                $hasSeatsLeft = $bookedSeatsCount < $bus->nombre_place;
                $busData[] = [
                    'bus' => $bus->name,
                    'name' => $bus->name,
                    'bookingsCount' => $bookingsCount,
                    'bookedSeatsCount' => $bookedSeatsCount,
                    'ticketsSoldCount' => $ticketsSoldCount,
                    'closed' => $bus->closed,
                    'shouldShowSeatNumbers' => $bus->should_show_seat_numbers,
                    'hasSeatsLeft' => $hasSeatsLeft,
                ];
            }
            $data[] = [
                'depart' => $depart->name,
                'buses' => $busData,
            ];
        }
        return response()->json($data);

    }
    public function bookings(Depart $depart)
    {
        return response()->json(BookingResource::collection($depart->bookings));


    }
    public function bookingsForExport(Depart $depart)
    {
        $query = $depart->bookings()->getQuery();
        $query = Booking::bookingsOrdererByTrajet($depart->trajet, $query);

        return response()->json(BookingForExportResource::collection($query->get()));


    }
    public  function  getDataForDepartCreation()
    {
        return response()->json([
            'events' => Event::orderByDesc('date_end')->limit(1)->get(),
            'trajets' => Trajet::all(),
            'horaires' => Horaire::all(),
        ]);

    }
    public function getAutresDeparts()
    {
        $event = Event::orderByDesc('date_end')->firstOrFail();
        $departs = Depart::where('event_id', $event->id)
            ->where('date', '<', now())
            ->orderByDesc('date')
            ->get();
        return response()->json(DepartResource::collection($departs));

    }

    public function waitingCustomers(Depart $depart)
    {
        $customers = $depart->waitingCustomers()->get()
            ->map(function(WaitingCustomer $waitingCustomer){
                return [
                    'id' => $waitingCustomer->customer->id,
                    'full_name' => $waitingCustomer->customer->full_name,
                    'phone_umber' => $waitingCustomer->customer->phone_number,
                    'created_at' => $waitingCustomer->created_at->format('d/m/Y H:i'),
                ];
            });
        return response()->json($customers);

    }
    private function  generateDefaultBusStopSchedules(Depart $depart, Bus $bus, Horaire $horaire)
    {
        $busStopSchedules = [];
        $pointDeps = $depart->trajet->pointDeps;
        foreach ($pointDeps as $pointDep) {
            $heure_point_dep = null;
            if ($horaire->periode == Horaire::PERIODE_MATIN) {
                $heure_point_dep = $pointDep->heure_point_dep;
            } elseif ($horaire->periode == Horaire::PERIODE_APRES_MIDI) {
                $heure_point_dep = $pointDep->heure_point_dep_soir;
            }elseif ($horaire->periode == Horaire::PERIODE_NUIT) {
                $heure_point_dep = $pointDep->heure_point_dep_soir->addHours(11);
            }else{
                throw new HttpResponseException(response()->json(['message' => Horaire::PERIODE_NUIT.' '
                    .$horaire->periode.' Horaire non pris en charge'],
                    Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $busStopSchedules[] = new HeureDepart([
                "depart_id" => $depart->id,
                "bus_id" => $bus->id,
                'point_dep_id' => $pointDep->id,
                'heureDepart' => $heure_point_dep,
                'arretBus' => $pointDep->arret_bus,
            ]);
        }
        return collect($busStopSchedules);
    }

}
