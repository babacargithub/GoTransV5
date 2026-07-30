<?php

namespace App\Services;

use App\Http\Controllers\OrangeMoneyController;
use App\Http\Controllers\WavePaiementController;
use App\Http\Requests\GpBookingRequest;
use App\Manager\BookingManager;
use App\Manager\TicketManager;
use App\Models\Booking;
use App\Models\Bus;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\Depart;
use App\Models\Destination;
use App\Models\PointDep;
use App\Models\TicketPayment;
use DB;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\JsonResponse;

class BookingService
{
    public function __construct(
        private readonly TicketManager $ticketManager,
        private readonly TrajetService $trajetService,
    )
    {
    }

    /**
     * Handles a GP multi-passenger booking request, including the optional round-trip leg.
     *
     * @throws RequestException
     * @throws GuzzleException
     * @throws ConnectionException
     */
    public function handleGpMultiPassengerBooking(GpBookingRequest $request)
    {
        try {
            $validated = $request->validated();
            $outboundDepart = Depart::findOrFail($validated['depart_id']);

            try {
                $outboundBus = $outboundDepart->getBusForBooking(climatise: true);
            } catch (ModelNotFoundException $e) {
                return response()->json(["message" => "Aucun bus  disponible pour ce départ"], 422);
            }

            if ($outboundBus == null) {
                return response()->json(["message" => "Aucun bus disponible pour ce départ"], 422);
            }

            $passengers = $this->resolveOrCreatePassengerCustomers($validated['passengers']);
            $isRoundTrip = (bool)($validated['is_round_trip'] ?? false);
            $sharedGroupId = BookingManager::generateBookingGroupId();
            $roundTripId = $isRoundTrip ? (string)Str::uuid() : null;

            $outboundLegResult = $this->buildBookingsForLeg(
                depart: $outboundDepart,
                bus: $outboundBus,
                passengers: $passengers,
                groupId: $sharedGroupId,
                validated: $validated,
                roundTripId: $roundTripId,
                tripLeg: $isRoundTrip ? Booking::TRIP_LEG_OUTBOUND : null,
                selectedSeatNumbers: $validated['selected_seats'] ?? null,
            );

            if ($outboundLegResult instanceof JsonResponse) {
                return $outboundLegResult;
            }

            $allBookings = $outboundLegResult;

            if ($isRoundTrip) {
                $returnDepart = $this->trajetService->findReturnDepart($outboundDepart, $validated['return_date']);
                if ($returnDepart == null) {
                    return response()->json(["message" => "Aucun départ retour trouvé pour cette date"], 422);
                }

                try {
                    $returnBus = $returnDepart->getBusForBooking(climatise: true);
                } catch (\Exception $e) {
                    return response()->json(["message" => "Aucun bus climatisé disponible pour le retour"], 422);
                }

                if ($returnBus == null) {
                    return response()->json(["message" => "Aucun bus disponible pour le retour"], 422);
                }

                $returnLegResult = $this->buildBookingsForLeg(
                    depart: $returnDepart,
                    bus: $returnBus,
                    passengers: $passengers,
                    groupId: $sharedGroupId,
                    validated: $validated,
                    roundTripId: $roundTripId,
                    tripLeg: Booking::TRIP_LEG_RETURN,
                    selectedSeatNumbers: null, // the return leg is always auto-assigned
                );

                if ($returnLegResult instanceof JsonResponse) {
                    return $returnLegResult;
                }

                $allBookings = array_merge($allBookings, $returnLegResult);
            }

            return $this->processGroupBookings($outboundDepart, $request, $allBookings, payment_method: $validated["payment_method"]);
        } catch (\Exception $e) {
            return response()->json(["message" => "Une erreur s'est produite lors du traitement de la réservation: " . $e->getMessage()], 422);
        }
    }


    /**
     * When one leg of a round-trip booking is cancelled, the remaining leg (for the same customer) is no
     * longer part of a round trip: strip its round_trip_id/trip_leg so it becomes a normal booking.
     */
    public function detachRoundTripTwin(Booking $cancelledBooking): void
    {
        if ($cancelledBooking->round_trip_id === null) {
            return;
        }

        Booking::where('round_trip_id', $cancelledBooking->round_trip_id)
            ->where('customer_id', $cancelledBooking->customer_id)
            ->where('id', '!=', $cancelledBooking->id)
            ->update([
                'round_trip_id' => null,
                'trip_leg' => null,
            ]);
    }

    /**
     * Builds the (unsaved) Booking models for one leg (outbound or return) of a GP multi-passenger booking,
     * resolving seats and reusing any already-existing unpaid booking for the same customer/bus.
     *
     * @param Collection $passengers Customer models, or arrays with id/full_name for passengers booked under an existing customer's name.
     * @return Booking[]|JsonResponse
     */
    private function buildBookingsForLeg(
        Depart $depart,
        Bus $bus,
        Collection $passengers,
        string $groupId,
        array $validated,
        ?string $roundTripId,
        ?string $tripLeg,
        ?array $selectedSeatNumbers,
    ): array|JsonResponse {
        $bookings = [];

        foreach ($passengers as $passenger) {
            $booking = new Booking([
                'payment_method' => $validated['payment_method'],
                'referer' => $validated['referer'] ?? 0,
                'booked_with_platform' => $validated['booked_with_platform'] ?? "web",
            ]);
            $booking->depart()->associate($depart);
            $booking->bus()->associate($bus);
            if ($passenger instanceof Customer) {
                $booking->customer()->associate($passenger);
            } else {
                $booking->customer_id = $passenger["id"];
                $booking->booked_for_customer = $passenger["full_name"];
            }
            $pointDepartAndDestination = $this->determinePointDepartAndDestinations($depart);
            $booking->point_dep()->associate($pointDepartAndDestination["point_dep"]);
            $booking->destination()->associate($pointDepartAndDestination["destination"]);
            $booking->paye = false;
            $booking->comment = is_request_for_gp_customers() ? "for_gp" : null;
            $booking->group_id = $groupId;
            $booking->round_trip_id = $roundTripId;
            $booking->trip_leg = $tripLeg;
            $bookings[] = $booking;
        }

        if ($selectedSeatNumbers !== null && count($selectedSeatNumbers) > 0) {
            if (count($selectedSeatNumbers) != count($bookings)) {
                return response()->json(["message" => "Le nombre de sièges sélectionnés ne correspond pas au nombre de passagers"], 422);
            }
            $seats = collect($selectedSeatNumbers)->map(function ($seatNumber) use ($bus) {
                $seat = $bus->seats()
                    ->join('seats', 'seats.id', '=', 'bus_seats.seat_id')
                    ->select('bus_seats.*')
                    ->where('seats.number', $seatNumber)->first();

                if ($seat == null) {
                    throw new \RuntimeException("Le siège numéro $seatNumber n'existe pas ou n'est pas disponible");
                }
                return $seat;
            });
        } else {
            $seats = $bus->getAvailableSeats()->take($validated["passenger_count"]);
            if (count($seats) < count($bookings)) {
                return response()->json(["message" => "Il n'y a pas assez de sièges disponibles pour tous les passagers"], 422);
            }
        }

        foreach ($bookings as $index => &$booking) {
            if (isset($seats[$index])) {
                $booking->seat()->associate($seats[$index]);
            }
            // check if customer has unpaid booking in the bookings table
            $existingBookings = Booking::where('customer_id', $booking->customer_id)
                ->where('bus_id', $booking->bus_id)
                ->whereNull('ticket_id')
                ->whereNull("deleted_at")
                ->whereNull("deletion_timestamp")
                ->get();
            $hasExistingBooking = $existingBookings->isNotEmpty();
            foreach ($existingBookings as $existingBooking) {
                if ($existingBooking) {
                    if ($existingBooking->has_seat) {
                        $seatOfExistingBooking = $existingBooking->seat;
                        $existingBooking->freeSeat();
                        $existingBooking->save();
                        $seatOfExistingBooking->freeSeat();
                        $seatOfExistingBooking->save();
                    }
                    if (isset($seats[$index])) {
                        $existingBooking->seat()->associate($seats[$index]);
                    }
                    $existingBooking->group_id = $booking->group_id;
                    $existingBooking->round_trip_id = $booking->round_trip_id;
                    $existingBooking->trip_leg = $booking->trip_leg;
                }
            }
            if ($hasExistingBooking) {
                $bookings[$index] = $existingBookings->last();
            } else {
                $bookings[$index] = $booking;
            }
        }

        return $bookings;
    }

    /**
     * Resolves the customer record for each raw passenger input, creating one if needed.
     *
     * @param array $passengers Raw passenger input arrays (full_name, first_name, last_name, phone_number).
     * @return Collection Customer models, or arrays with id/full_name when the phone number belongs to an already-registered customer.
     */
    private function resolveOrCreatePassengerCustomers(array $passengers): Collection
    {
        return collect($passengers)->map(function (array $passenger) {
            $customer = Customer::where('phone_number', $passenger['phone_number'])->first();
            if ($customer == null) {
                return Customer::create([
                    'prenom' => $passenger['first_name'],
                    'nom' => $passenger['last_name'],
                    'phone_number' => $passenger['phone_number'],
                    "customer_category_id" => CustomerCategory::where('abrv', "GP")->first()?->id,
                ]);
            }

            return [
                "id" => $customer->id,
                'prenom' => $passenger['first_name'],
                'nom' => $passenger['last_name'],
                "full_name" => $passenger['first_name'] . " " . $passenger['last_name'],
                'phone_number' => $passenger['phone_number'],
            ];
        });
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     * @throws GuzzleException
     */
    public function processGroupBookings(Depart $depart, Request $request, array $bookings = [], $payment_method = null, $platform = "mobile")
    {
        if (count($bookings) == 0) {
            throw new \InvalidArgumentException("Aucune réservation à enregistrer ");
        }
        DB::transaction(function () use ($bookings) {
            foreach ($bookings as $booking) {
                $booking->save();
                $seat = $booking->seat;
                if ($seat != null) {
                    $seat->book();
                    $seat->save();
                }
            }
        });
        $waveController = app(WavePaiementController::class);
        $omPaymentController = app(OrangeMoneyController::class);
        $group_id = $bookings[0]->group_id;
        $main_booking_id = $bookings[0]->id;
        $totalTicketPrice = $this->ticketManager->calculatePriceForMultipleBookings($bookings, $payment_method, $platform)['totalPrice'];
        $payment_method = strtolower($payment_method);
        if ($payment_method == "wave") {
            $metadata = [
                "amount" => '' . $totalTicketPrice,
                "client_reference" => [
                    'type' => 'multiple_booking',
                    'group_id' => $group_id,
                    "depart_id" => $depart->id],
                "error_url" => WavePaiementController::getEndpointForRedirect() . '/#/multiple_bookings/' . $group_id,
                "success_url" => WavePaiementController::getEndpointForRedirect() . '/#/multiple_bookings/' . $group_id,
            ];

            $wavePaiementResponse = $waveController->getPaymentUrl($metadata);
            if ($wavePaiementResponse->isOK()) {
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
            $wavePaiementResponse->data['main_booking_id'] = $main_booking_id;
            $wavePaiementResponse->data['paymentMethod'] = "om";
            return $wavePaiementResponse;
        } else if ($payment_method == "om") {
            $metadata = [
                "amount" => $totalTicketPrice,
                //TODO om number to be defined
                "customer" => $request->input("om_number"),
                "metadata" => [
                    "group_id" => $group_id,
                    "type" => "multiple_booking",
                    "depart_id" => $depart->id,
                    "bookings" => json_encode(array_map(function (Booking $booking) {
                        return $booking->id;
                    }, $bookings))
                ],
            ];

            $paymentResponse = $omPaymentController->initOMPayment($metadata);
            if ($paymentResponse->isOK()) {
                $ticketPayment = new TicketPayment();
                $ticketPayment->payement_method = "om";
                $ticketPayment->montant = $totalTicketPrice;
                $ticketPayment->status = TicketPayment::STATUS_PENDING;
                $ticketPayment->phone_number = $request->input("om_number");
                $ticketPayment->meta_data = json_encode($metadata["metadata"]);
                $ticketPayment->group_id = $group_id;
                $ticketPayment->is_for_multiple_booking = true;
                $ticketPayment->save();
            } else {
                Log::log("error", "Erreur lors de l'initialisation du paiement pour le groupe $group_id");
                return response()->json(["message" => "Erreur lors de l'initialisation du paiement "], 422);
            }

            $paymentResponse->data['group_id'] = $group_id;
            $paymentResponse->data['main_booking_id'] = $main_booking_id;
            $paymentResponse->data['paymentMethod'] = "om";
            return $paymentResponse;
        } else {
            return response()->json(["message" => "Méthode de paiement non supportée"], 422);
        }
    }

    /**
     * Resolves the default point de départ / destination used for GP customer bookings.
     */
    private function determinePointDepartAndDestinations(Depart $depart): array
    {
        //TODO change this later
        if ($depart->trajet->id == 1) {
            $defaultPointDep = PointDep::findOrFail(40);
        } else if ($depart->trajet->id == 2) {
            $defaultPointDep = PointDep::findOrFail(2);
        } else {
            $defaultPointDep = PointDep::where("trajet_id", $depart->trajet_id)->first();
        }
        $defaultDestination = Destination::where("id", ($depart->trajet->id == 1 ? 34 : 36))
            ->firstOrFail();

        return ["point_dep" => $defaultPointDep, "destination" => $defaultDestination];
    }
}
