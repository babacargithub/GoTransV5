<?php

namespace App\Http\Requests;

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
            'passenger_count' => 'required|integer|min:1|max:10',
            'payment_method' => 'required|string|in:Wave,OM,Orange Money',
            'selected_seats' => 'required|array|min:1|max:10',
            'selected_seats.*' => 'required|integer|min:1|max:50',
            'passengers' => 'required|array|min:1|max:10',
            'passengers.*.full_name' => 'required|string|max:255',
            'passengers.*.first_name' => 'required|string|max:100',
            'passengers.*.last_name' => 'required|string|max:100',
            'passengers.*.phone_number' => [
                'required',
                'string',
                'regex:/^(77|78|76|75|70)\d{7}$/', // Senegalese phone format
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
            'passengers.*.phone_number.regex' => new PhoneNumber()
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Check if passenger_count matches the number of passengers
            if ($this->passenger_count != count($this->passengers ?? [])) {
                $validator->errors()->add('passenger_count', 'Le nombre de passagers ne correspond pas au nombre d\'informations fournies.');
            }

            // Check if passenger_count matches the number of selected seats
            if ($this->passenger_count != count($this->selected_seats ?? [])) {
                $validator->errors()->add('selected_seats', 'Le nombre de sièges sélectionnés doit être le même que le nombre de passagers.');
            }

            // Check for duplicate seat numbers
            $selectedSeats = $this->selected_seats ?? [];
            if (count($selectedSeats) !== count(array_unique($selectedSeats))) {
                $validator->errors()->add('selected_seats', 'Vous ne pouvez pas sélectionner le même siège plusieurs fois.');
            }

            // Check for duplicate phone numbers
            $phoneNumbers = collect($this->passengers ?? [])->pluck('phone_number')->toArray();
            if (count($phoneNumbers) !== count(array_unique($phoneNumbers))) {
                $validator->errors()->add('passengers', 'Certains  des passagers ont les numéros de téléphone en double.');
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
            'passenger_count' => 'nombre de passagers',
            'payment_method' => 'méthode de paiement',
            'selected_seats' => 'sièges sélectionnés',
            'passengers' => 'passagers',
        ];
    }
}