<?php

namespace App\Http\Controllers;

use App\Http\Resources\CustomerBookingsResource;
use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
        $customers = Customer::orderByDesc('created_at')->limit(50)->get();
        return response()->json($customers->map(function ($customer) {
            return [
                'id' => $customer->id,
                'name' => $customer->nom,
                "full_name" => $customer->full_name,
                'phone' => $customer->phone_number,
                'email' => $customer->email,
                'created_at' => $customer->created_at->format('d-m-Y H:i:s'),
            ];
        }));
    }


    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {

        $validated = $request->validate([
            'nom' => 'required|string',
            'prenom' => 'required|string',
            'phoneNumber' => 'required|numeric',
            'sexe' => 'string',
        ]);
        $data = [
            'nom' => $validated['nom'],
            'prenom' => $validated['prenom'],
            'phone_number' => $validated['phoneNumber'],
            'sexe' => $validated['sexe'] ?? null,
        ];
        $customer = new Customer($data);
        $customer->save();
        return response()->json($customer);
    }

    /**
     * Display the specified resource.
     */
    public function show(Customer $customer)
    {
        //
        return response()->json($customer);
    }
    public function findByPhoneNumber(int $phone_number)
    {
        $customer = Customer::where('phone_number', $phone_number)->first();
        if (!$customer) {
            return response()->json(['message' => 'Customer not found'], 404);
        }
        $customer->load('bookings');
        $customer->bookings = CustomerBookingsResource::collection($customer->bookings);
        return response()->json($customer);
    }


    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Customer $customer)
    {

        $validated = $request->validate([
            'nom' => 'string',
            'prenom' => 'string',
            'phone_number' => 'numeric',
        ]);
        $data = [
            'nom' => $validated['nom'] ?? $customer->nom,
            'prenom' => $validated['prenom'] ?? $customer->prenom,
            'phone_number' => $validated['phone_number'] ?? $customer->phone_number,
        ];
        $customer->update($data);
        return response()->json($customer);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Customer $customer)
    {
        //
        $customer->delete();
        return response()->noContent();
    }

    public function getLatestContacts(Request $request)
    {
        $after_id = $request->query('after_id') ?? null;
        $after_date = $request->query('after_date') ?? "2024-01-01";
        $query = Customer::selectRaw('CONCAT( prenom," ",nom, " ","#",id) as name, phone_number');
        if ($after_id != null){
           $query->where('id',">", $after_id);
       }
        elseif ($after_date){
          $query->whereDate("created_at",
               ">",$after_date);
       }
        return response()->json(
            $query
                ->get()
       );

    }
}
