<?php

namespace App\Http\Controllers;

use App\Models\Itinerary;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ItineraryController extends Controller
{
    //
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
        return Itinerary::all()->map(function ($itinerary) {
            return [
                "id" => $itinerary->id,
                "name" => $itinerary->name,
                "trajet"=>$itinerary->trajet->name,
                "point_departs"=>$itinerary->pointDeparts()->map(function ($point_dep) {
                    return [
                        "id" => $point_dep->id,
                        "name" => $point_dep->name,

                    ];
                }),
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
        // Validate the incoming request
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:itineraries,name',
            'trajet_id' => 'required|integer|exists:trajets,id',
            'point_depart_ids' => 'required|array|min:1',
            'point_depart_ids.*' => 'integer|exists:point_deps,id'
        ], [
            'name.required' => 'Le nom de l\'itinéraire est requis',
            'name.unique' => 'Ce nom d\'itinéraire existe déjà',
            'trajet_id.required' => 'Le trajet est requis',
            'trajet_id.exists' => 'Le trajet sélectionné n\'existe pas',
            'point_depart_ids.required' => 'Au moins un point de départ est requis',
            'point_depart_ids.min' => 'Au moins un point de départ doit être sélectionné',
            'point_depart_ids.*.exists' => 'Un ou plusieurs points de départ sélectionnés n\'existent pas',
            'point_depart_ids.*.distinct' => 'Les points de départ ne peuvent pas être dupliqués'
        ]);

        try {
            // Create the itinerary
            $itinerary = new Itinerary();
            $itinerary->name = $validated['name'];
            $itinerary->trajet_id = $validated['trajet_id'];
            $itinerary->point_deps = $validated['point_depart_ids']; // Store as array in database
            $itinerary->save();

            // Load the itinerary with its relations for the response

            return response()->json([
                'success' => true,
                'message' => 'Itinéraire créé avec succès',
                'data' => $itinerary
            ], 201);

        } catch (\Exception $e) {
            // Log the error for debugging
            \Log::error('Error creating itinerary: ' . $e->getMessage(), [
                'request_data' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de l\'itinéraire',
                'errors' => ['general' => ['Une erreur inattendue s\'est produite']]
            ], 500);
        }

    }

    /**
     * Display the specified resource.
     */
    public function show(Itinerary $itinerary)
    {
        //

    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Itinerary $itinerary)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Itinerary $itineraire)
    {
        // Validate the incoming request
        $itinerary = $itineraire;
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                // Unique validation excluding the current itinerary
                Rule::unique($itinerary->getTable(), 'name')->ignoreModel($itinerary)
            ],
            'trajet_id' => 'required|integer|exists:trajets,id',
            'point_depart_ids' => 'required|array|min:1',
            'point_depart_ids.*' => 'integer|exists:point_deps,id',
        ], [
            'name.required' => 'Le nom de l\'itinéraire est requis',
            'name.unique' => 'Ce nom d\'itinéraire existe déjà ! Donc impossible à modifier',
            'trajet_id.required' => 'Le trajet est requis',
            'trajet_id.exists' => 'Le trajet sélectionné n\'existe pas',
            'point_depart_ids.required' => 'Au moins un point de départ est requis',
            'point_depart_ids.min' => 'Au moins un point de départ doit être sélectionné',
            'point_depart_ids.*.exists' => 'Un ou plusieurs points de départ sélectionnés n\'existent pas',
            'point_depart_ids.*.distinct' => 'Les points de départ ne peuvent pas être dupliqués'
        ]);

        try {
            // Store original data for comparison (optional - for logging changes)
            $originalData = $itinerary->toArray();

            // Update the itinerary
            $itinerary->name = $validated['name'];
            $itinerary->trajet_id = $validated['trajet_id'];
            $itinerary->point_deps = $validated['point_depart_ids'];


            $itinerary->save();

            // Load the itinerary with its relations for the response

            // Optional: Log the changes
            \Log::info('Itinerary updated', [
                'itinerary_id' => $itinerary->id,
                'user_id' => auth()->id() ?? 'system',
                'changes' => $itinerary->getChanges()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Itinéraire modifié avec succès',
                'data' => $itinerary
            ], 200);

        } catch (\Exception $e) {
            // Log the error for debugging
            \Log::error('Error updating itinerary: ' . $e->getMessage(), [
                'itinerary_id' => $itinerary->id,
                'request_data' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la modification de l\'itinéraire',
                'errors' => ['general' => ['Une erreur inattendue s\'est produite']]
            ], 500);
        }
    }

    /**
     * Alternative method for partial updates (if you want to support PATCH requests)
     */
    public function partialUpdate(Request $request, Itinerary $itinerary)
    {
        // Define which fields can be partially updated
        $allowedFields = ['name', 'trajet_id', 'point_depart_ids', 'disabled'];
        $updateData = $request->only($allowedFields);

        // Validation rules for partial update
        $rules = [];
        $messages = [];

        if (isset($updateData['name'])) {
            $rules['name'] = [
                'required',
                'string',
                'max:255',
                Rule::unique('itineraries', 'name')->ignore($itinerary->id)
            ];
            $messages['name.unique'] = 'Ce nom d\'itinéraire existe déjà';
        }

        if (isset($updateData['trajet_id'])) {
            $rules['trajet_id'] = 'required|integer|exists:trajets,id';
            $messages['trajet_id.exists'] = 'Le trajet sélectionné n\'existe pas';
        }

        if (isset($updateData['point_depart_ids'])) {
            $rules['point_depart_ids'] = 'required|array|min:1';
            $rules['point_depart_ids.*'] = 'integer|exists:point_departs,id|distinct';
            $messages['point_depart_ids.min'] = 'Au moins un point de départ doit être sélectionné';
        }

        if (isset($updateData['disabled'])) {
            $rules['disabled'] = 'boolean';
        }

        // Validate only the fields being updated
        $validated = $request->validate($rules, $messages);

        try {
            // Update only the provided fields
            foreach ($validated as $field => $value) {
                if ($field === 'point_depart_ids') {
                    $itinerary->point_deps = $value;
                } else {
                    $itinerary->$field = $value;
                }
            }

            $itinerary->save();
            $itinerary->load(['trajet', 'pointDeparts']);

            return response()->json([
                'success' => true,
                'message' => 'Itinéraire modifié avec succès',
                'data' => $itinerary
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Error partially updating itinerary: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la modification de l\'itinéraire'
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Itinerary $itinerary)
    {
        //
        $itinerary->delete();
        return response()->json([]);
    }
    public function addMultiplePointDeparts(Itinerary $itinerary, Request $request)
    {
        // Validate the request
        $request->validate([
            'point_departs' => 'required|array|min:1',
            'point_departs.*' => 'integer|exists:point_deps,id'
        ]);

        $point_departs_to_add = $request->point_departs;
        $current_point_deps = $itinerary->point_deps ?? []; // Get current array of IDs

        // Find which point departs are not already in the itinerary
        $new_point_departs = array_diff($point_departs_to_add, $current_point_deps);
        $added_count = count($new_point_departs);

        if ($added_count > 0) {
            // Add the new point departs to the existing array
            $updated_point_deps = array_merge($current_point_deps, $new_point_departs);

            // Remove duplicates and reset array keys
            $updated_point_deps = array_values(array_unique($updated_point_deps));

            // Update the database
            $itinerary->point_deps = $updated_point_deps;
            $itinerary->save();
        }

        // Count how many were already present (duplicates)
        $already_present = count($point_departs_to_add) - $added_count;

        // Build response message
        $message = '';
        if ($added_count > 0) {
            $message = "Successfully added {$added_count} point depart(s)";
            if ($already_present > 0) {
                $message .= " ({$already_present} were already present)";
            }
        } else {
            $message = "All selected point departs were already in the itinerary";
        }

        // Return a response
        return response()->json([
            'success' => true,
            'message' => $message,
            'added_count' => $added_count,
            'already_present_count' => $already_present,
            'total_point_departs' => $itinerary->point_deps ?? []
        ], 200);
    }
    public function deleteMultiplePointDeparts(Itinerary $itinerary, Request $request)
    {
        // Validate the request
        $request->validate([
            'point_departs' => 'required|array|min:1',
            'point_departs.*' => 'integer|exists:point_deps,id'
        ]);

        $point_departs_to_remove = $request->point_departs;
        $current_point_deps = $itinerary->point_deps ?? []; // Get current array of IDs

        // Find which point departs are actually in the current array
        $valid_removals = array_intersect($point_departs_to_remove, $current_point_deps);
        $removed_count = count($valid_removals);

        if ($removed_count > 0) {
            // Remove the specified point departs from the array
            $updated_point_deps = array_diff($current_point_deps, $point_departs_to_remove);

            // Reset array keys to avoid gaps in the array
            $updated_point_deps = array_values($updated_point_deps);

            // Update the database
            $itinerary->point_deps = $updated_point_deps;
            $itinerary->save();
        }

        // Return a response
        return response()->json([
            'success' => true,
            'message' => $removed_count > 0
                ? "Successfully removed {$removed_count} point depart(s)"
                : "No point departs were removed",
            'removed_count' => $removed_count,
            'remaining_point_departs' => $itinerary->point_deps ?? []
        ], 200);
    }
}
