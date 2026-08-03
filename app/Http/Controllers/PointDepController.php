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
            'heurePointDep' => 'required|date_format:H:i',
            'heurePointDepSoir' => 'required|date_format:H:i',
            'arretBus' => 'required|string',
            'disabled' => 'boolean',
            'trajet_id' => 'required|exists:trajets,id',
            'city' => 'nullable|string',
            'ticket_price' => 'nullable|numeric',
        ]);

        $position = (PointDep::withoutGlobalScope('order')
            ->where('trajet_id', $validated['trajet_id'])
            ->max('position') ?? 0) + 1;

        $pointDep = PointDep::create([
            'name' => $validated['name'],
            'heure_point_dep' => $validated['heurePointDep'],
            'heure_point_dep_soir' => $validated['heurePointDepSoir'],
            'arret_bus' => $validated['arretBus'],
            'disabled' => $validated['disabled'] ?? false,
            'trajet_id' => $validated['trajet_id'],
            'city' => $validated['city'] ?? null,
            'ticket_price' => $validated['ticket_price'] ?? null,
            'position' => $position,
        ]);

        return response()->json($pointDep, 201);
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
            'city' => 'nullable|string',
            'ticket_price' => 'nullable|numeric',
            'visibilite' => 'nullable|numeric',
        ]);
        // map $validated
       $data = [
           'name' => $validated['name']?? $pointDepart->name,
           'heure_point_dep' => $validated['heurePointDep'] ?? $pointDepart->heure_point_dep,
           'heure_point_dep_soir' => $validated['heurePointDepSoir'] ?? $pointDepart->heure_point_dep_soir,
           'arret_bus' => $validated['arretBus']?? $pointDepart->arret_bus,
           'disabled' => $validated['disabled']?? $pointDepart->disabled,
           'city' => $validated['city'] ?? $pointDepart->city,
           'visibilite'=> $validated['visibilite']?? $pointDepart->visibilite,
           'ticket_price' => $validated['ticket_price'] ?? $pointDepart->ticket_price];

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

    /**
     * Persist a new display order for the point deps of a trajet (drag-and-drop reordering).
     */
    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'trajet_id' => 'required|exists:trajets,id',
            'point_dep_ids' => 'required|array|min:1',
            'point_dep_ids.*' => 'integer|exists:point_deps,id',
        ]);

        $pointDeps = PointDep::withoutGlobalScope('order')
            ->whereIn('id', $validated['point_dep_ids'])
            ->where('trajet_id', $validated['trajet_id'])
            ->get()
            ->keyBy('id');

        foreach ($validated['point_dep_ids'] as $index => $id) {
            $pointDeps->get($id)?->update(['position' => $index + 1]);
        }

        return response()->json(['message' => "Ordre mis à jour avec succès"]);
    }
}
