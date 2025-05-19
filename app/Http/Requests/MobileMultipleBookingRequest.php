<?php

namespace App\Http\Requests;

use App\Models\Booking;
use App\Models\Bus;
use App\Models\Customer;
use App\Models\Depart;
use App\Rules\PhoneNumber;
use http\Exception\InvalidArgumentException;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Fluent;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MobileMultipleBookingRequest extends FormRequest
{

    protected $error_code = "GENERIC_ERROR";
    const ERROR_ALREADY_PASSED = "DEPART_ALREADY_PASSED";
    const ERROR_BUS_FULL = "BUS_FULL";
    const ERROR_BUS_CLOSED = "BUS_CLOSED";
    const ERROR_NO_BUS_AVAILABLE = "NO_BUS_AVAILABLE";
    const ERROR_BUS_NOT_FOUND = "BUS_NOT_FOUND";
    const ERROR_DEPART_CLOSED = "DEPART_CLOSED";
    const ERROR_ALREADY_BOOKED = "ALREADY_BOOKED";
    protected $error_payload = [];
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            "bookings" => "required|array",
            "bookings.*.point_dep_id" => "required|integer|exists:point_deps,id",
            "bookings.*.destination_id" => "required|integer|exists:destinations,id",
            "bookings.*.phone_number" => ["required", "numeric", new PhoneNumber()],

            "bookings.*.referer" => "nullable|integer",
            "booked_with_platform" => "nullable|string",
            "bus_id" => "integer|exists:buses,id",
            "payment_method" => "required|string",
            "om_number" => "nullable|integer",
            "group_owner_phone_number" => "nullable|integer"
        ];
    }

    public function after(): array
    {
        return [

            function (Validator $validator) {
            $this->validateCustomers($validator);
            },
            function (Validator $validator) {
                if ($this->depart->isPassed()) {
                    $validator->errors()->add(
                        'depart',
                        'Ce départ est déjà passé.'
                    );
                }

            },
            function (Validator $validator) {
                $depart = $this->depart;
                $validated = $this->validated();
                $bookings = $this->normalizeBookings();
                $numberOfBookings = count($bookings);
                if (isset($validated["bus_id"])) {
                    // check if bus belongs to depart
                    if (!Bus::where("depart_id", $depart->id)->where("id", $validated["bus_id"])->exists()) {
                        $this->error_code = self::ERROR_BUS_NOT_FOUND;
                        $validator->errors()->add('bus_id', "Le bus sélectionné  n'est pas reconnu par notre système! C'est peut être une erreur interne");
                    }
                    $bus = Bus::findOrFail($validated["bus_id"]);

                    if ($bus->seatsLeft() < $numberOfBookings || $bus->isClosed()) {
                        $this->error_code = self::ERROR_BUS_FULL;
                        $closestNextDepart = $depart->getClosestNextDepart();
                        $this->error_payload = [
                            "bus_id" => $bus->id,
                            "closest_next_depart_id" => $closestNextDepart?->id,
                            "closest_next_depart" => $closestNextDepart != null ? $closestNextDepart->identifier() :
                                null];
                        $validator->errors()->add('bus_id', "Désolé, le bus est plein, il n'y a plus de place !");
                    }

                }else{
                    $bus =  $depart->getBusForBooking();
                    if ($depart->isClosed()){
                        $this->error_code = self::ERROR_DEPART_CLOSED;
                        $validator->errors()->add('depart', "Désolé, nous avons cloturé pour ce départ est  !");

                    }
                    if ($bus->seatsLeft() < $numberOfBookings || $bus->isClosed() || $bus->isFull() ) {

                        $busesHavingEnoughSeats = $depart->buses->filter(function (Bus $bus) use ($numberOfBookings) {
                            return $bus->seatsLeft() >= $numberOfBookings && !$bus->isClosed();
                        });

                        if ($busesHavingEnoughSeats->isEmpty()) {
                            $validator->errors()->add(
                                'bus_id',
                                "Désolé, tous nos bus sont pleins !"
                            );
                        }else{
                            $validated["bus_id"] = $busesHavingEnoughSeats->first()->id;
                        }

                    }else{
                        $this->merge(["bus_id" => $bus->id]);
                    }
                }
                foreach ($bookings as /** @var Booking $booking */$booking){
                    $customer = Customer::findOrFail($booking->customer_id);
                    $currentBooking = $customer->bookings()
                        ->join('departs', 'bookings.depart_id', '=', 'departs.id')
                        ->where('departs.date', '>=', now())
                        ->where("departs.trajet_id", $depart->trajet_id)->first();
                    if ($currentBooking != null) {

                        $this->error_code = self::ERROR_ALREADY_BOOKED;
                        $this->error_payload = [
                            "customer_full_name" => $currentBooking->customer->full_name,
                            "current_booking_id" => $currentBooking->id,
                            "current_booking_depart" => $currentBooking->depart->name];
                        $validator->errors()->add('phone_number', "".$currentBooking->customer->full_name.' a déjà fait une réservation pour le départ ' .
                            $currentBooking->depart->name);
                    }
                }





            },
            function (Validator $validator) {
                //if payment_method is om, om_number is required
                $validated = $this->validated();
                if ($validated["payment_method"] == "om" && !isset($validated["om_number"])) {
                    $validator->errors()->add('om_number', 'Le numéro orange money est requis pour le paiement orange money');

                }
            }

        ];


    }
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        $errors = $validator->errors();

        // Adding custom fields to the error response
        $response = [
            'status' => 'validation_failed',
            'error_code' => $this->error_code,
            'message' => $validator->getMessageBag()->first(),
            'errors' => $errors,
            'error_payload' => $this->error_payload,
        ];

//        parent::failedValidation($validator);
        throw new HttpResponseException(response()->json($response, 422));

    }
    protected function passedValidation(): void
    {
        $this->merge(["bookings" => $this->normalizeBookings()]);
    }
    public function normalizeBookings(): array
    {
        /** @var Depart $depart */
        $depart = $this->depart;
        // transforming  customers
        $bookings = [];
        $bookingInputs = $this->validated()["bookings"];
        $group_id = Booking::latest()->first()?->id.now()->timestamp;

        foreach ($bookingInputs as $bookingInput) {
            $customer = Customer::where('phone_number', substr($bookingInput["phone_number"],-9,9))->firstOrFail();
            $bookingInput["customer_id"] = $customer->id;
            $booking = new Booking($bookingInput);
            $booking->booked_with_platform = $this->validated()["booked_with_platform"]?? null;
            $booking->depart_id = $depart->id;
            $booking->bus_id = $this->validated()["bus_id"];
            $booking->paye = false;
            $booking->group_id = $group_id;
            $bookings[] = $booking;



        }
       return $bookings;
    }
    public function attributes(): array
    {
        return [

                "bookings.0.first_name" => "prénom de la première personne",
                "bookings.1.first_name" => "prénom de la deuxièmes personne",
                "bookings.0.last_name" => "nom de la première personne",
                "bookings.1.last_name" => "nom de la deuxièmes personne"
        ];

    }
    public function validateCustomers(Validator $validator) : void
    {
        $validated = $this->validated();
        // take only 3 columns from bookings (first_name, last_name, phone_number)
        $customerInputsList = array_map(function ($booking) {
            return [
                "first_name" => $booking["first_name"]?? null,
                "last_name" => $booking["last_name"] ?? null,
                "phone_number" => $booking["phone_number"] ?? null
            ];
        }, $validated["bookings"]);
        foreach ($customerInputsList as $index=> $customerInput) {
            $customer = Customer::where('phone_number', substr($customerInput["phone_number"],-9,9))->first();
            if ($customer == null) {
                $customersInputData = $this->validate([
                    "bookings.".$index.".first_name" => "required|string:min:2",
                    "bookings.".$index.".last_name" => "required|string|min:2|alpha_num"],
                [
                    // $index+1 when showing error message to user
                    "bookings.".$index.".first_name.required" => "Le prénom de la personne ".($index+1)." avec le numéro téléphone ".$customerInput["phone_number"]." est requis",
                    "bookings.".$index.".last_name.required" => "Le nom de la personne ".($index+1)." avec le numéro téléphone ".$customerInput["phone_number"]." est requis",
                    "bookings.".$index.".first_name.string" => "Le prénom de la personne ".($index+1)." avec le numéro téléphone ".$customerInput["phone_number"]." doit être une chaine de caractères",
                    "bookings.".$index.".last_name.string" => "Le nom de la personne ".($index+1)." avec le numéro téléphone ".$customerInput["phone_number"]." doit être une chaine de caractères",
                    "bookings.".$index.".first_name.min" => "Le prénom de la personne ".($index+1)." avec le numéro téléphone ".$customerInput["phone_number"]." doit avoir au moins 2 caractères",
                    "bookings.".$index.".last_name.min" => "Le nom de la personne ".($index+1)." avec le numéro téléphone ".$customerInput["phone_number"]." doit avoir au moins 2 caractères",

                ]);

                $customer = Customer::create([
                    "prenom" => $customersInputData["bookings"][$index]["first_name"],
                    "nom" => $customersInputData["bookings"][$index]["last_name"],
                    "phone_number" => $customerInput["phone_number"],
                    "last_active" => now()
                ]);
            }

        }
    }
}
