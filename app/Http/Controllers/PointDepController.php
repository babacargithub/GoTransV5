<?php

namespace App\Http\Controllers;

use App\Models\PointDep;
use Illuminate\Http\Request;

class PointDepController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return PointDep::all()->map(function ($pointDep) {
            return [
                "id" => $pointDep->id,
                "name" => $pointDep->name,
                "departureSchedule" => $pointDep->heure_point_dep->format('H:i'),
                "departureScheduleEvening" => $pointDep->heure_point_dep_soir->format('H:i'),
                "arretBus" => $pointDep->arret_bus,
                "position" => $pointDep->position,
                "disabled" => $pointDep->disabled,
            ];
        });
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'heurePointDep' => 'required|string',
            'heurePointDepSoir' => 'required|string',
            'arretBus' => 'required|string',
            'disabled' => 'boolean',
        ]);
        $pointDep = new PointDep($validated);
        $pointDep->save();
    }

    /**
     * Display the specified resource.
     */
    public function show(PointDep $pointDep)
    {
        //
        return $pointDep;
    }


    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, PointDep $pointDepart)
    {
        //
        $validated = $request->validate([
            'name' => 'string',
            'heurePointDep' => 'date_format:H:i',
            'heurePointDepSoir' => 'date_format:H:i',
            'arretBus' => 'string',
            'disabled' => 'boolean',
        ]);
        // map $validated
       $data = [
           'name' => $validated['name']?? $pointDepart->name,
           'heure_point_dep' => $validated['heurePointDep'] ?? $pointDepart->heure_point_dep,
           'heure_point_dep_soir' => $validated['heurePointDepSoir'] ?? $pointDepart->heure_point_dep_soir,
           'arret_bus' => $validated['arretBus']?? $pointDepart->arret_bus,
           'disabled' => $validated['disabled']?? $pointDepart->disabled];

        $pointDepart->update($data);
        return response()->json($pointDepart);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(PointDep $pointDep)
    {
        //
        $pointDep->delete();
        return response()->noContent();
    }

    public function disable(PointDep $pointDep)
    {
        $pointDep->disabled = !$pointDep->disabled;
        $pointDep->save();
        return response()->json($pointDep);

    }
}
