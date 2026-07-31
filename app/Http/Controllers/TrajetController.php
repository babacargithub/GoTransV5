<?php

namespace App\Http\Controllers;

use App\Models\Trajet;
use App\Services\TrajetService;
use Illuminate\Http\Request;

class TrajetController extends Controller
{
    public function __construct(private readonly TrajetService $trajetService)
    {
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return $this->trajetService->listAll();
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
        $data = $request->validate([
            'name' => 'required|string|unique:trajets',
            'public_name' => 'nullable|string',
            'departure_city' => 'nullable|string',
            'arrival_city' => 'nullable|string',
            'code' => 'nullable|string|unique:trajets,code',
            'length' => 'nullable|numeric',
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
            "horaires.*.periode" => 'required|string',
        ], [
            'name.unique' => 'Ce trajet existe déjà'
        ]);

        return $this->trajetService->createTrajet($data);
    }

    /**
     * Display the specified resource.
     */
    public function show(Trajet $trajet)
    {
        return $this->trajetService->loadDetails($trajet);
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
        $data = $request->validate([
            'name' => 'string',
            'public_name' => 'nullable|string',
            'departure_city' => 'nullable|string',
            'arrival_city' => 'nullable|string',
            'code' => 'nullable|string|unique:trajets,code,' . $trajet->id,
            'length' => 'nullable|numeric',
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

        $this->trajetService->updateTrajet($trajet, $data);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Trajet $trajet)
    {
        $this->trajetService->deleteTrajet($trajet);
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
            "return_date" => 'nullable|date|after_or_equal:travel_date',
        ], [
            'return_date.date' => 'La date de retour est invalide.',
            'return_date.after_or_equal' => 'La date de retour doit être après ou égale à la date de départ.',
        ]);

        return $this->trajetService->searchByCities($request);
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
        return $this->trajetService->getCities();
    }
}
