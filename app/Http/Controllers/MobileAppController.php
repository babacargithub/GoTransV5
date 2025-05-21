<?php

namespace App\Http\Controllers;

use App\Http\Requests\MobileMultipleBookingRequest;
use App\Http\Resources\BookingResource;
use App\Http\Resources\MobileTrajetDepartsResource;
use App\Http\Resources\MobileBookingResource;
use App\Http\Resources\MobileMultipleBookingResource;
use App\Manager\TicketManager;
use App\Models\AppParams;
use App\Models\Booking;
use App\Models\Bus;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\Depart;
use App\Models\Destination;
use App\Models\HeureDepart;
use App\Models\PointDep;
use App\Models\TicketPayment;
use App\Models\Trajet;
use App\Models\Vehicule;
use App\Rules\PhoneNumber;
use DB;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class MobileAppController extends Controller
{
    public function params()
    {
        return response()->json(AppParams::first());

    }
    public function updateParams(\Illuminate\Http\Request $request)
    {
        $params = $request->validate([
            "data" => "required|array"
        ]);

        $appParams = AppParams::first();
        if ($appParams == null) {
            $appParams = new AppParams();
            $appParams->data = $params["data"];
            $appParams->save();
            return response()->json($appParams);
        }
        $appParams->data = array_replace_recursive($appParams->data, $params["data"]);
        $appParams->save();
        return response()->json($appParams);

    }

    public function listeDepartsTrajet(Trajet $trajet)
    {

        return response()->json(new MobileTrajetDepartsResource($trajet));


    }
    public function listeDepartsForGp(\Illuminate\Http\Request $request)
    {

        $trajets = Trajet::all()->map(function (Trajet $trajet){
            return [
                'id' => $trajet->id,
                'name' => $trajet->name,
                'departs' => $trajet->departs()
                    ->where("date", ">=", now())
                    ->where(function($query) {
                        $query->where("visibilite", "=", Depart::VISIBILITE_GP_CUSTOMERS_ONLY)
                            ->orWhere("visibilite", "=", Depart::VISIBILITE_ALL_CUSTOMERS);
                    })
                    ->orderBy('date')
                    ->get()
//                    ->filter(function (Depart $depart) {
//                        return !$depart->isPassed() && $depart->buses()
//                                ->join('vehicules', 'vehicule_id', '=', 'vehicules.id')
//                            ->where('vehicules.vehicule_type', Vehicule::VEHICULE_TYPE_CLIMATISE)
//                            ->exists();
//                    })
                    ->map(function (Depart $depart) {
                    return [
                            'id' => $depart->id,
                        // the name should be the date expressed in french like this mercredi 21 mai
                            'name' => $depart->name,
                            'ticket_price' => $depart->getBusForBooking(climatise: true)?->ticket_price,
                            'is_closed' => $depart->closed,
                            ];
                    }),
            ];
        });

        return response()->json($trajets);


    }

    /**
     * @throws ConnectionException
     * @throws GuzzleException
     */
    public function saveBooking(Depart $depart, \Illuminate\Http\Request $request)
    {

        $validated = $request->validate([
            "first_name" => "nullable|string",
            "last_name" => "nullable|string",
            "nom_complet" => "nullable|string",
            "phone_number" => ["required", "string", new PhoneNumber()],
            "payment_method" => "required|string",
            "referer" => "numeric",
            "booked_with_platform" => "string",

        ]);
        // check the headers to find if the source is gp if no we will add additional validation rules
        if (!\is_request_for_gp_customers()) {
            $validated = array_merge_recursive($validated, $request->validate([
                "point_dep_id" => "required|integer|exists:point_deps,id",
                "destination_id" => "required|integer|exists:destinations,id",
                "customer_id" => "nullable|integer|exists:customers,id",
                "bus_id" => "exists:buses,id",
            ]));
        }else{
            //TODO change this later

            if ($depart->trajet->id == 1) {
                $defaultPointDep = PointDep::findOrFail(40);
            } else if ($depart->trajet->id == 2) {
                $defaultPointDep = PointDep::findOrFail(2);
            }else{
                $defaultPointDep = PointDep::where("trajet_id", $depart->trajet_id)->first();
            }
            $validated['point_dep_id'] = $defaultPointDep->id;
            $validated['destination_id'] = Destination::where("id", ($depart->trajet->id == 1 ? 34: 36 ))
                ->firstOrFail()->id;
        }

        // if customer_id is not provided, we will create a new customer
        if (isset($validated["customer_id"])){
                $customer = Customer::find($validated['customer_id']);

        }
        else{
            if(Customer::where('phone_number', $validated['phone_number'])->doesntExist()) {
                $customer = Customer::create([
                    'prenom' => $validated['first_name'],
                    'nom' => $validated['last_name'],
                    'phone_number' => $validated['phone_number'],
                    "customer_category_id" => \is_request_for_gp_customers() ? CustomerCategory::where('abrv',"GP")
                        ->first()?->id : CustomerCategory::first()?->id,
                ]);

            }else{
                $customer = Customer::where('phone_number', $validated['phone_number'])->first();
            }
            $validated['customer_id'] = $customer->id;
        }
        // check if customer has no current booking
        $currentBooking = $customer->getCurrentBooking();
           if ($currentBooking != null) {

            return response()->json([
                'message' => $customer->full_name .'a déjà fait une réservation sur le départ '
                    .$currentBooking->depart->name,
                "error_code"=>"ALREADY_BOOKED",
                "current_booking_id"=>$currentBooking->id,
                "current_booking_depart"=>$currentBooking->depart->name
            ], 422);
        }

           // check if the bus is not full or closed and depart is not closed, if bus is full or closed, we check if there is a bus with available seats
        try {
            $bus = isset($validated["bus_id"]) ? Bus::findOrFail($validated["bus_id"]) :
                $depart->getBusForBooking(climatise: is_request_for_gp_customers());
        } /** @noinspection PhpUnusedLocalVariableInspection */
        catch (ModelNotFoundException $e) {
            if (is_request_for_gp_customers()) {
                $busClimatise = $depart->buses()
                    ->join('vehicules',"buses.vehicule_id",'vehicules.id')
                    ->where("buses.depart_id", $depart->id)
                    ->where('vehicules.vehicule_type', Vehicule::VEHICULE_TYPE_CLIMATISE)
                    ->first();
                if ($busClimatise != null) {
                    $bus = $busClimatise;
                } else {
                    return response()->json([
                        'message' => "Désolé, aucun bus climatisé disponible pour ce départ",
                        "error_code" => "DEPART_NOT_OPENED"
                    ], 422);
                }
            } else {
                $bus = $depart->getBusForBooking();
            }
        }
        if ($depart->isPassed()){
            return response()->json([
                'message' => "Désolé, le départ est déjà passé",
                "error_code"=>"DEPART_PASSED"
            ], 422);
        }

        if ($bus->isFull() || $bus->isClosed()) {
            // we will check if there is a bus of same vehicle type with available seats
            $busOfSameVehicleType = $depart->buses()
                ->where('vehicule_id', $bus->vehicule_id)
                ->where('id', '!=', $bus->id)
                ->where('closed', false)
                ->first();
            if ($busOfSameVehicleType != null && !$busOfSameVehicleType->isFull()) {
                $bus = $busOfSameVehicleType;
            } else {
                $closestDepart = $depart->getClosestNextDepart();
                return response()->json([
                    'message' => "Désolé, le bus choisi est plein, il n'y a plus de place",
                    "error_code"=>"BUS_FULL",
                    "closest_depart_id"=>$closestDepart?->id,
                    "bus_of_same_type_depart"=> $depart->buses->filter(fn(Bus $busItem) => $busItem->id != $bus?->id
                        && !$busItem->isClosed() || ! $busItem->isFull())->first(),
                    "closest_depart_name"=>$closestDepart?->name
                ], 422);
            }
        }
        if (!isset($validated["referer"])){
            $validated['referer'] = $request->input('referer_id')??0;

        }
        $booking = new Booking($validated);
        $booking->referer_id = $validated['referer']  ??0;
        $booking->comment = \is_request_for_gp_customers() ? "for_gp" : null;
        $booking->bus_id = $bus->id;
        $booking->customer()->associate($customer);
        $booking->paye = false;
        $booking->depart()->associate($bus->depart);
        $booking->save();
        $wavePaiementController = app(WavePaiementController::class);
        $ticketManager = app(TicketManager::class);
        $paymentResponse = strtolower($validated["payment_method"])
        =="om"? $ticketManager->triggerOmPayment($booking):
            $wavePaiementController->getWavePaymentUrlForBooking($booking, $ticketManager);
        $paymentResponse->data['booking_id'] = $booking->id;

            return $paymentResponse;


    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     * @throws GuzzleException
     */
    public function saveMultipleBookings(Depart $depart, MobileMultipleBookingRequest $request, )
    {
        $bookings = $request->input('bookings');
        DB::transaction(function () use ($bookings) {
            foreach ($bookings as  $booking) {
                $booking->save();
            }
        });
        $ticketManager = app(TicketManager::class);
        $waveController = app(WavePaiementController::class);
        $omPaymentController = app(OrangeMoneyController::class);
        $payment_method = $request->input("payment_method");
        $platform = $request->headers->get('Platform')?? $request->input("booked_with_platform")?? "web";
        $group_id = $bookings[0]->group_id;
            $totalTicketPrice = $ticketManager->calculatePriceForMultipleBookings($bookings, $payment_method, $platform) ['totalPrice'];
        if ($payment_method == "wave") {
            $metadata = [
                "amount" => '' .$totalTicketPrice,
                "client_reference" => [
                    'type'=>'multiple_booking',
                    'group_id' => $group_id,
                    "depart_id"=>$depart->id],
                "error_url" => WavePaiementController::getEndpointForRedirect().'/#/multiple_bookings/'.$group_id,
                "success_url" => WavePaiementController::getEndpointForRedirect().'/#/multiple_bookings/'.$group_id,
            ];



            $wavePaiementResponse = $waveController->getPaymentUrl($metadata);
            if ($wavePaiementResponse->isOK() ) {
                $ticketPayment = new TicketPayment();
                $ticketPayment->payement_method = "wave";
                $ticketPayment->status = TicketPayment::STATUS_PENDING;
                $ticketPayment->montant = $totalTicketPrice;
                $ticketPayment->meta_data = json_encode($metadata["client_reference"]);
                $ticketPayment->group_id = $group_id;
                $ticketPayment->is_for_multiple_booking = true;
                $ticketPayment->save();

            }
            $wavePaiementResponse->data['group_id'] = $group_id;
            $wavePaiementResponse->data['paymentMethod'] = "om";
            return $wavePaiementResponse;
        } else if ($payment_method == "om") {
            $metadata = [
                "amount"=>$totalTicketPrice,
                "customer" => $request->input("om_number"),
                "metadata" => [
                    "group_id" => $group_id,
                    "type" => "multiple_booking",
                    "depart_id"=>$depart->id,
                    "bookings" => json_encode(array_map(function (Booking $booking) {
                        return $booking->id;
                    }, $bookings))
                ],

            ];


            $paymentResponse = $omPaymentController->initOMPayment($metadata);
            if ($paymentResponse->isOK()){
                $ticketPayment = new TicketPayment();
                $ticketPayment->payement_method = "om";
                $ticketPayment->montant = $totalTicketPrice;
                $ticketPayment->status = TicketPayment::STATUS_PENDING;
                $ticketPayment->phone_number = $request->input("om_number");
                $ticketPayment->meta_data = json_encode($metadata["metadata"]);
                $ticketPayment->group_id = $group_id;
                $ticketPayment->is_for_multiple_booking = true;
                $ticketPayment->save();
            }else{
                Log::log("error", "Erreur lors de l'initialisation du paiement pour le groupe $group_id");
                return response()->json(["message" => "Erreur lors de l'initialisation du paiement "], 422);
            }


            $paymentResponse->data['group_id'] = $group_id;
            $paymentResponse->data['paymentMethod'] = "om";
            return $paymentResponse;

        }else{
            return response()->json(["message" => "Méthode de paiement non supportée"], 422);
        }


    }
    public function calculatePrice(Depart $depart, MobileMultipleBookingRequest $request): JsonResponse
    {
        $bookings = $request->input('bookings');
        $payment_method = $request->input('payment_method');
        $ticketManager = app(TicketManager::class);
        $platform = $request->header("Platform")?? $request->input("booked_with_platform")?? "web";
        $priceCalculationResult = $ticketManager->calculatePriceForMultipleBookings($bookings, $payment_method, $platform);
        $totalPrice = $priceCalculationResult['totalPrice'];
        $discount = $priceCalculationResult['discount'];
        $normalPrice = $priceCalculationResult['normalPrice'];


        return response()->json(["totalPrice" => $totalPrice, "discount" => $discount, "normalPrice" => $normalPrice]);


    }
    public function getMultipleBookingsOfSameGroupe(?int $groupId): JsonResponse
    {
        $bookings = Booking::where('group_id', $groupId)->get();
        return response()->json(MobileMultipleBookingResource::collection($bookings));
    }
    public function showBooking(Booking $booking)
    {
        $booking->load('depart', 'seat', 'point_dep', 'destination', 'ticket');
        return new MobileBookingResource($booking);
    }
    public function currentBooking($phoneNumber)
    {
        $customer = Customer::where('phone_number', $phoneNumber)->first();
        if ($customer == null) {
            return response()->json(["message" => "Aucune réservation trouvée pour ce numéro de téléphone"], 404);
        }
        $booking = $customer->getCurrentBooking();
        if ($booking == null) {
            return response()->json(["message" => "Aucune réservation trouvée pour ce numéro de téléphone"], 404);
        }
        return response()->json(new MobileBookingResource($booking));
    }
    public function schedules()
    {
        // schedules grouped by trajet
        $trajets = Trajet::all();
        return response()->json($trajets->map(function (Trajet $trajet) {
            return [
                'id' => $trajet->id,
                'name' => $trajet->name,
                'pointDeparts' => $trajet->pointDeps->map(function (PointDep $pointDepart) {
                    return [
                        'name' => $pointDepart->name,
                        'id' => $pointDepart->id,
                        "heure_point_dep_matin"=>$pointDepart->heure_point_dep->format('H:i'),
                        "heure_point_dep_soir"=>$pointDepart->heure_point_dep_soir->format('H:i'),
                        "arret_bus"=>$pointDepart->arret_bus
                    ];
                }),
                'destinations' => $trajet->destinations->map(function (Destination $destination) {
                    return [
                        'name' => $destination->name,
                        'id' => $destination->id
                    ];
                })
            ];
        }));


    }
    public function departSchedules(Depart $depart)
    {
        // schedules grouped by trajet
        $schedules = $depart->heuresDeparts()->orderBy('heureDepart')->get();
        return response()->json($schedules->map(function (HeureDepart $schedule) {
            return [
                'position' => $schedule->pointDep->position,
                'heure_depart' => $schedule->heureDepart?->format('H:i'),
                'name' => $schedule->pointDep->name,
                'arret_bus' => $schedule->pointDep->arret_bus,
            ];
        }));


    }

    /**
     * @throws ConnectionException
     */
    public function getWavePaymentUrlForBooking(Booking $booking)
    {
        $ticketManager = app(TicketManager::class);
        $wavePaiementController = app(WavePaiementController::class);
        return $wavePaiementController->getWavePaymentUrlForBooking($booking, $ticketManager);

    }

    /**
     * @throws GuzzleException
     * @throws ConnectionException
     */
    public function initOmPayment(Booking $booking)
    {
        $omController = app(OrangeMoneyController::class);
        $amount = $booking->bus->ticket_price;
        $customerNumber = $booking->customer->phone_number;
        $bookingId = $booking->id;
        $requestData =
            [
                'amount' => $amount,
                'customer' => $customerNumber,
                'metadata' => [
                    'booking_id' => $bookingId
                ],
            ];
        return $omController->initOMPayment($requestData);
    }

    public function clientExists($phoneNumber)
    {
        $customer = Customer::where('phone_number', $phoneNumber)->first();
        return response()->json([
            "client_exists" => $customer != null,
            "phone_number" => $phoneNumber,
            "fullName" => $customer?->full_name
            ]);
    }

    public function cancelBooking(Booking $booking)
    {
        if ($booking->hasTicket()) {
            return response()->json(["message" => "Impossible d'annuler une réservation déjà payée"], 422);
        }
        $booking->delete();
        return response()->noContent();

    }


    public function calculatePriceForGroupe(\Illuminate\Http\Request $request)
    {
        $validated = $this->validateMultipleBookingPaymentRequest($request);
        $result =$this->calculateTicketPriceForMultipleBooking($validated, $request);

        return response()->json($result);

    }

    /**
     * @throws RequestException
     * @throws GuzzleException
     * @throws ConnectionException
     */
    public function generatePaymentUrlForMultipleBooking(\Illuminate\Http\Request $request)
    {
        $validated =$this->validateMultipleBookingPaymentRequest($request);

        $result = $this->calculateTicketPriceForMultipleBooking($validated, $request);
        $payment_method = $validated['payment_method'];
        $group_id = $validated['group_id'];
        if ($payment_method == "wave") {
            $metadata = [
                "amount" => '' . $result['totalPrice'],
                "client_reference" => [
                    'type' => 'multiple_booking',
                    'group_id' => $group_id,
                ],
                "error_url" => WavePaiementController::getEndpointForRedirect().'/#/multiple_bookings/' . $group_id,
                "success_url" => WavePaiementController::getEndpointForRedirect().'/#/multiple_bookings/' . $group_id,
            ];
            $wavePaiementController = app(WavePaiementController::class);
            $wavePaiementResponse = $wavePaiementController->getPaymentUrl($metadata);
            if ($wavePaiementResponse->isOK()) {
                $ticketPayment = new TicketPayment();
                $ticketPayment->payement_method = "wave";
                $ticketPayment->montant = $result['totalPrice'];
                $ticketPayment->meta_data = json_encode($metadata);
                $ticketPayment->group_id = $group_id;
                $ticketPayment->is_for_multiple_booking = true;
                $ticketPayment->save();

            }
            return $wavePaiementResponse;
        } else if ($payment_method == "om") {
            $validated = array_merge_recursive($validated, $request->validate([
                "om_number" => ["required", "string", new PhoneNumber()],
            ]));
            $metadata = [
                "amount" => $result['totalPrice'],
                "customer" => $validated["om_number"],
                "metadata" => [
                    "group_id" => $group_id,
                    "type" => "multiple_booking",
                ],

            ];
            $omPaiementController = app(OrangeMoneyController::class);
            $paymentResponse = $omPaiementController->initOMPayment($metadata);
            if ($paymentResponse->isOK()) {
                $ticketPayment = new TicketPayment();
                $ticketPayment->payement_method = "om";
                $ticketPayment->montant = $result['totalPrice'];
                $ticketPayment->meta_data = json_encode($metadata);
                $ticketPayment->group_id = $group_id;
                $ticketPayment->is_for_multiple_booking = true;
                $ticketPayment->save();

            }
            return $paymentResponse;
            }
            else {
                return response()->json(["message" => "Méthode de paiement non supportée"], 422);
            }

    }

    /**
     * @param \Illuminate\Http\Request $request
     * @return void
     */
    public function validateMultipleBookingPaymentRequest(\Illuminate\Http\Request $request): array
    {
        return $request->validate([
            "payment_method" => "required|string",
            "group_id" => ["required", "numeric",
                function ($attribute, $value, $fail) {
                    //check if group exists
                    if (Booking::
                    join("departs", "depart_id", "=", "departs.id")
                        ->where('group_id', $value)->doesntExist()) {
                        $fail("Aucun groupe non trouvé");
                    } else {
                        // check if depart is not passed
                        $isPassed = Booking::
                        join("departs", "depart_id", "=", "departs.id")
                            ->where("departs.date", ">=", now())
                            ->where('group_id', $value)->doesntExist();
                        if ($isPassed) {
                            $fail("Le départ est déjà passé");
                        }

                    }
                }
            ],
        ]);
    }

    /**
     * @param array|null $validated
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function calculateTicketPriceForMultipleBooking(?array $validated, \Illuminate\Http\Request $request): array
    {
        $bookings = [];
        foreach (Booking::where('group_id', $validated['group_id'])->get() as $item) {
            $bookings[] = $item;
        }
        $ticketManager = app(TicketManager::class);
        $platform = $request->header("Platform") ?? $request->input("booked_with_platform") ?? "web";
        return $ticketManager->calculatePriceForMultipleBookings($bookings, $validated['payment_method'], $platform);
    }


}
