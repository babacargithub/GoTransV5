<?php

namespace App\Http\Requests;

use App\Models\Booking;
use App\Models\Bus;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Rules\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

class GpBookingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'depart_id' => 'required|integer|exists:departs,id',
            'bus_id' => 'required|integer|exists:buses,id',
            'passenger_count' => 'required|integer|min:1|max:10',
            'payment_method' => 'required|string|in:Wave,OM,Orange Money',
            'selected_seats' => 'nullable|array|max:10',
            'selected_seats.*' => 'nullable|integer|max:200',
            'passengers' => 'required|array|min:1|max:100',
            'passengers.*.full_name' => 'required|string|max:255',
            'passengers.*.first_name' => 'required|string|max:100',
            'passengers.*.last_name' => 'required|string|max:100',
            'passengers.*.phone_number' => [
                'required',
                new PhoneNumber()// Senegalese phone format
            ],
        ];
    }

    /**
     * Get custom validation messages.
     */
    public function messages(): array
    {
        return [
            'depart_id.required' => 'Le départ est requis.',
            'depart_id.exists' => 'Le départ sélectionné n\'existe pas.',
            'passenger_count.required' => 'Le nombre de passagers est requis.',
            'passenger_count.min' => 'Il faut au moins 1 passager.',
            'passenger_count.max' => 'Maximum 10 passagers autorisés.',
            'payment_method.required' => 'La méthode de paiement est requise.',
            'payment_method.in' => 'La méthode de paiement doit être Wave, OM ou Orange Money.',
            'selected_seats.required' => 'Vous devez sélectionner au moins un siège.',
            'selected_seats.min' => 'Vous devez sélectionner au moins un siège.',
            'selected_seats.max' => 'Maximum 10 sièges autorisés.',
            'selected_seats.*.integer' => 'Les numéros de siège semblent avoir des problèmes au niveau interne.',
            'passengers.required' => 'Les informations des passagers sont requises.',
            'passengers.min' => 'Au moins un passager est requis.',
            'passengers.max' => 'Maximum 10 passagers autorisés.',
            'passengers.*.full_name.required' => 'Le nom complet est requis pour chaque passager.',
            'passengers.*.full_name.max' => 'Le nom complet ne peut pas dépasser 255 caractères.',
            'passengers.*.first_name.required' => 'Le prénom est requis pour chaque passager.',
            'passengers.*.first_name.max' => 'Le prénom ne peut pas dépasser 100 caractères.',
            'passengers.*.last_name.required' => 'Le nom de famille est requis pour chaque passager.',
            'passengers.*.last_name.max' => 'Le nom de famille ne peut pas dépasser 100 caractères.',
            'passengers.*.phone_number.required' => 'Le numéro de téléphone est requis pour chaque passager.',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Check if passenger_count matches the number of passengers
            // Check if passenger_count matches the number of selected seats
            if (count($this->selected_seats ?? []) > 0) {
                if ($this->passenger_count != count($this->selected_seats ?? [])) {
                    $validator->errors()->add('selected_seats', 'Le nombre de sièges sélectionnés doit être le même que le nombre de passagers.');
                }
            }

            // Check for duplicate seat numbers
            $selectedSeats = $this->selected_seats ?? [];
            if (count($selectedSeats) !== count(array_unique($selectedSeats))) {
                $validator->errors()->add('selected_seats', 'Vous ne pouvez pas sélectionner le même siège plusieurs fois.');
            }
            $bus = Bus::findOrFail($this->bus_id);
            if ($bus->seatsLeft() < $this->validated()["passenger_count"]){
                $validator->errors()->add("Il n'y pas de assez de places disponible dans le bus ! Nombre de places disponibles : ".$bus->seatsLeft());

            }
            if ($bus->isFull() || $bus->isClosed()){
                $validator->errors()->add("Nous avons clôturé les réservations pour ce bus ! ");

            }
            $alreadyBookedSeats = [];
            foreach ($selectedSeats as $selectedSeat) {
                $seat = $bus->seats()
                    ->join('seats',"seats.id","bus_seats.seat_id")
                    ->select('bus_seats.*')
                    ->where('seats.number', $selectedSeat)->first();
                if ($seat == null) {
                    return $validator->errors()->add("Le siège sélectionné n'existe pas dans le bus");
                }
                if ($seat->booked || Booking::where('seat_id', $seat->id)->exists()) {
                    $alreadyBookedSeats[] = $selectedSeat;
                }
                // assign the seat to the booking

//            $booking->save();
            }
            if (count($alreadyBookedSeats) > 0) {
                $validator->errors()->add('selected_seats', 'Les sièges  ' . implode(', ', $alreadyBookedSeats) . ' sont déjà pris par d\'autres clients.');
            }


        });
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'depart_id' => 'départ',
            'bus_id' => 'bus',
            'passenger_count' => 'nombre de passagers',
            'payment_method' => 'méthode de paiement',
            'selected_seats' => 'sièges sélectionnés',
            'passengers' => 'passagers',
        ];
    }
    protected function passedValidation(): void
    {


        // Replace the passengers array with the transformed models
        $this->merge([
            'passengers' => $this->transformPassengers()
        ]);
    }

    public function transformPassengers()
    {
        $passengers = collect($this->validated()['passengers'])->map(function (array $passenger) {
            // Create or update the customer

            $customer = Customer::where('phone_number', $passenger['phone_number'])->first();
            if ($customer == null) {
                $customer = Customer::create([
                    'prenom' => $passenger['first_name'],
                    'nom' => $passenger['last_name'],
                    'phone_number' => $passenger['phone_number'],
                    "customer_category_id" => CustomerCategory::where('abrv',"GP")
                        ->first()?->id,
                ]);
            }else{
                $customer->prenom = $passenger['first_name'];
                $customer->nom = $passenger['last_name'];

            }
            return $customer;
        });
       return $passengers;
    }
    /**
     * Get validated data with transformed passengers
     */
    public function getValidatedWithTransforms(): array
    {
        $validated = $this->validated();

            $validated['passengers'] = $this->transformPassengers();


        return $validated;
    }

}