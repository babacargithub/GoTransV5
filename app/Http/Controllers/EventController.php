<?php

namespace App\Http\Controllers;

use App\Http\Resources\DepartResource;
use App\Models\Booking;
use App\Models\Bus;
use App\Models\Depart;
use App\Models\Event;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class EventController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
        $events = Event::orderByDesc('date_end')->get()->map(function (Event $event) {
            return [
                /**  */
                'id' => $event->id,
                'name' => $event->name,
                'dateStart' => $event->date_start->format('Y-m-d'),
                'dateEnd' => $event->date_end->format('Y-m-d'),
                "departs"=> [],
                "expenses"=> [],
            ];
        });
        return response()->json($events);
    }


    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
        $validated = $request->validate([
            'name' => 'required|string',
            'dateStart' => 'required|date',
            'dateEnd' => 'required|date',
            'direction' => 'required|numeric',
        ]);
        $mapped = [
            'libelle' => $validated['name'],
            'date_start' => $validated['dateStart'],
            'date_end' => $validated['dateEnd'],
            'trajet' => $validated['direction'],
        ];
        $event = new Event($mapped);
        $event->save();
        return response()->json($event, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Event $event)
    {
        //
    }



    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Event $event)
    {
        //
        $data = $request->validate([
            'name' => 'string',
            'dateStart' => 'date',
            'dateEnd' => 'date',
        ]);
        $mapped = [
            'libelle' => $data['name'],
            'date_start' => $data['dateStart'],
            'date_end' => $data['dateEnd'],
        ];
        $event->update($mapped);
        return response()->json($event);

    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Event $event)
    {
        //
        $event->delete();
        return response()->json(null, 204);
    }

    public function departs(Event $event)
    {

        return response()->json(DepartResource::collection($event->departs()->orderByDesc('date')->get()));

    }
    public function ticketSales(Event $event)
    {
        $ticketSales =
            Booking::
                join('departs', 'bookings.depart_id', '=', 'departs.id')
            ->where("departs.event_id", $event->id)
            ->whereNotNull('ticket_id')
            ->join('tickets', 'bookings.ticket_id', '=', 'tickets.id')
            ->selectRaw('tickets.soldBy as soldBy, SUM(tickets.price) as total')
            ->groupBy('tickets.soldBy')
            ->get();
        return response()->json($ticketSales);

    }

    public function stats($dateStart, $dateEnd)
    {
    // date start will be set to first date of year
        $dateStart = now()->startOfYear();
        $dateEnd = now()->endOfYear();
        /** response body should look like this :
         *bookings: 29527
         * buses :698
         * departs :499
         * tickets :96375131
         */
        $data = [
            'bookings' => Booking::join('departs', 'bookings.depart_id', '=', 'departs.id')
                ->whereBetween('departs.date', [$dateStart, $dateEnd])
                ->count(),
            'buses' => Bus::join('departs', 'buses.depart_id', '=', 'departs.id')
                ->whereBetween('departs.date', [$dateStart, $dateEnd])
                ->count(),
            'departs' => Depart::whereBetween("date", [$dateStart, $dateEnd])->count(),
            'tickets' => Booking::join('departs', 'bookings.depart_id', '=', 'departs.id')
                ->whereBetween('departs.date', [$dateStart, $dateEnd])
                ->whereNotNull('ticket_id')
                ->join('tickets', 'bookings.ticket_id', '=', 'tickets.id')
                ->sum('tickets.price'),
        ];

        return response()->json($data);

    }
}
