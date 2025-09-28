<?php

namespace App\Http\Controllers;

use App\Models\Trajet;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Unique;

class TrajetController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
        return Trajet::with('pointDeps', 'destinations', 'horaires')->get();
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
        $data = $request->validate([
            'name' => 'required|string|unique:trajets',
            "start_point" => 'required|string',
            "end_point" => 'required|string',
            "point_departs" => 'required|array',
            "point_departs.*.name" => 'required|string',
            "point_departs.*.heure_point_dep" => 'required|date_format:H:i',
            "point_departs.*.heure_point_dep_soir" => 'required|date_format:H:i',
            "destinations" => 'required|array',
            "destinations.*.name" => 'required|string',
            "horaires" => 'required|array',
            "horaires.*.name" => 'required|string',
            "horaires.*.bus_leave_time" => 'required|date_format:H:i',
        ],[
            'name.unique' => 'Ce trajet existe déjà'
        ]);



        $point_departs = $data['point_departs'];
        $destinations = $data['destinations'];
        $horaires = $data['horaires'];
        \DB::transaction(function () use ($data, $point_departs, $destinations, $horaires) {
            $trajet = Trajet::create([
                'name' => $data['name'],
                'start_point' => $data['start_point']??null,
                'end_point' => $data['end_point'] ?? null
            ]);
            $trajet->pointDeps()->createMany($point_departs);
            $trajet->destinations()->createMany($destinations);
            $trajet->horaires()->createMany($horaires);
        });

        return response()->json(["message"=>"Depart crée avec succès !"], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Trajet $trajet)
    {
        //
        $trajet->load('pointDeps', 'destinations', 'horaires');
        return $trajet;
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Trajet $trajet)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Trajet $trajet)
    {
        //
        $data = $request->validate([
            'name' => 'string',
            "point_departs" => 'array',
            "point_departs.*.name" => 'string',
            "point_departs.*.heure_depart" => 'date_format:H:i',
            "point_departs.*.heure_soir" => 'date_format:H:i',
            "destinations" => 'array',
            "destinations.*.name" => 'string',
            "horaires" => 'array',
            "horaires.*.heure_depart" => 'date_format:H:i',
            "horaires.*.name" => 'string',
        ]);
        // update the trajet along with its point de departs, destinations and horaires
        \DB::transaction(function () use ($data, $trajet) {
            $trajet->update([
                'name' => $data['name'] ?? $trajet->name
            ]);
            if (isset($data['point_departs'])) {
                foreach ($data['point_departs'] as $pointDep) {
                    $pointDepModel = $trajet->pointDeps()->find($pointDep['id']);
                    if ($pointDepModel) {
                        $pointDepModel->update($pointDep);
                    }
                }
            }
            if (isset($data['destinations'])) {
                foreach ($data['destinations'] as $destination) {
                    $destinationModel = $trajet->destinations()->find($destination['id']);
                    if ($destinationModel) {
                        $destinationModel->update($destination);
                    }
                }
            }
            if (isset($data['horaires'])) {
                foreach ($data['horaires'] as $horaire) {
                    $horaireModel = $trajet->horaires()->find($horaire['id']);
                    if ($horaireModel) {
                        $horaireModel->update($horaire);
                    }
                }
            }
        });
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Trajet $trajet)
    {
        //
        $trajet->delete();
        return response()->noContent();

    }

    /**
     * Search trajets by departure and arrival cities
     */
    public function searchByCities(Request $request)
    {
        $request->validate([
            'departure_city' => 'required|string',
            'arrival_city' => 'required|string',
            "travel_date" => 'required|date',
        ]);
        $data = [];

        try {
            $trajet = Trajet::where('departure_city', $request->departure_city)
                ->where('arrival_city', $request->arrival_city)
                ->firstOrFail();
            $data = $trajet->departs()->whereDate('date', '>', now()->toDateString())
                ->whereDate('date', '=', $request->travel_date)
                ->get()->map(function ($depart) {
                    return [
                        'id' => $depart->id,
                        // format the departure time to be in the format of H:i
                        'departure_time' => date('H\hi', strtotime($depart->horaire->bus_leave_time)),
                        "departure_date" => $depart->date->format('Y-m-d'),
                        'seats_remaining' => $depart->getBusForBooking()?->seatsLeft() ?? 0,
                        'ticket_price' => $depart->getBusForBooking()?->ticket_price,
                    ];
                });
            $message =  'Trajets found successfully';
        }
        catch (ModelNotFoundException $e) {
            $message = 'Trajets found not found';

        }

        return response()->json([
            'data' => $data,
            'message' => $message
        ]);
    }

    /**
     * Search available departures by cities and date for mobile app
     */
    public function searchDepartures(Request $request)
    {
        return $this->searchByCities($request);
    }

    /**
     * Get list of available cities for departure/arrival selection
     */
    public function getCities()
    {
        $departureCities = Trajet::whereNotNull('departure_city')
            ->distinct()
            ->pluck('departure_city')
            ->map(function($city, $index) {
                return ['id' => $index + 1, 'name' => $city];
            });

        $arrivalCities = Trajet::whereNotNull('arrival_city')
            ->distinct()
            ->pluck('arrival_city')
            ->map(function($city, $index) {
                return ['id' => $index + 100, 'name' => $city];
            });

        $cities = $departureCities->merge($arrivalCities)
            ->unique('name')
            ->sortBy('name')
            ->values();

        return response()->json([
            'data' => $cities,
            'message' => 'Cities retrieved successfully'
        ]);
    }
}
