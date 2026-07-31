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
use App\Models\Trajet;
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
                pointDepId: $validated['point_dep_id'] ?? null,
            );

            if ($outboundLegResult instanceof JsonResponse) {
                return $outboundLegResult;
            }

            $allBookings = $outboundLegResult;

            if ($isRoundTrip) {
                $returnDepart = $this->trajetService->resolveReturnDepart($outboundDepart, $validated['return_depart_id'] ?? null, $validated['return_date']);
                if ($returnDepart == null) {
                    return response()->json(["message" => "Aucun départ retour trouvé pour cette date"], 422);
                }

                try {
                    $returnBus = $this->trajetService->assertReturnDepartBookable($returnDepart, (int)$validated['passenger_count']);
                } catch (\RuntimeException $e) {
                    return response()->json(["message" => $e->getMessage()], 422);
                }

                $returnLegResult = $this->buildBookingsForLeg(
                    depart: $returnDepart,
                    bus: $returnBus,
                    passengers: $passengers,
                    groupId: $sharedGroupId,
                    validated: $validated,
                    roundTripId: $roundTripId,
                    tripLeg: Booking::TRIP_LEG_RETURN,
                    selectedSeatNumbers: null, // the return leg never has customer-chosen seats; assignment is deferred to payment confirmation, same as any unseated leg
                    pointDepId: null, // the return leg always boards at the trajet's default GP pickup point
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
     * Calculates the exact per-leg and total ticket prices for a (potential) GP round-trip booking,
     * before any Booking rows exist — used by the payment/summary step so the frontend can display
     * the same numbers the actual booking will charge instead of estimating locally.
     *
     * Deliberately reuses the same building blocks as handleGpMultiPassengerBooking /
     * TrajetService::searchByCities, rather than re-deriving price/boarding-point logic:
     * TicketManager::calculateTicketPrice (single source of pricing truth),
     * determinePointDepartAndDestinations (boarding-point resolution) and
     * TrajetService::resolveReturnDepart (return-depart resolution, already scoped to the outbound
     * trajet's reverse trajet).
     *
     * @throws \RuntimeException When a depart has no bookable bus, or return_depart_id doesn't
     *                            belong to the outbound trajet's reverse trajet.
     */
    public function calculatePriceForGpBooking(int $departId, int $passengerCount, bool $isRoundTrip, ?int $returnDepartId): array
    {
        $outboundDepart = Depart::findOrFail($departId);
        $outboundBus = $this->resolveBusForPricing($outboundDepart, 'départ');
        $outboundPointDep = $this->determinePointDepartAndDestinations($outboundDepart)['point_dep'];
        $outboundTicketPrice = $this->ticketManager->calculateTicketPrice($outboundBus, $outboundPointDep, forGp: true) * $passengerCount;

        $returnTicketPrice = 0;
        if ($isRoundTrip && $returnDepartId !== null) {
            // returnDate is only consulted by resolveReturnDepart's date-based fallback, which never
            // runs when a return_depart_id is given — so it's irrelevant here.
            $returnDepart = $this->trajetService->resolveReturnDepart($outboundDepart, $returnDepartId, returnDate: '');
            if ($returnDepart === null) {
                throw new \RuntimeException("Le départ retour sélectionné n'est pas valide pour ce trajet.");
            }

            $returnBus = $this->resolveBusForPricing($returnDepart, 'retour');
            $returnPointDep = $this->determinePointDepartAndDestinations($returnDepart)['point_dep'];
            $returnTicketPrice = $this->ticketManager->calculateTicketPrice($returnBus, $returnPointDep, forGp: true) * $passengerCount;
        }

        return [
            'outbound_ticket_price' => $outboundTicketPrice,
            'return_ticket_price' => $returnTicketPrice,
            'total_ticket_price' => $outboundTicketPrice + $returnTicketPrice,
        ];
    }

    /**
     * Fetches the bookable bus for a depart, throwing a RuntimeException with a leg-specific message
     * when none is available. Mirrors the outbound-bus resolution in handleGpMultiPassengerBooking.
     */
    private function resolveBusForPricing(Depart $depart, string $legLabel): Bus
    {
        try {
            $bus = $depart->getBusForBooking(climatise: true);
        } catch (ModelNotFoundException) {
            $bus = null;
        }

        if ($bus === null) {
            throw new \RuntimeException("Aucun bus disponible pour le $legLabel");
        }

        return $bus;
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
     * @param int|null $pointDepId Traveller-chosen boarding point (possibly a mid-route city), resolved by the
     *                             search endpoint. Null falls back to the trajet's default GP pickup point.
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
        ?int $pointDepId = null,
    ): array|JsonResponse {
        $bookings = [];

        foreach ($passengers as $passenger) {
            $booking = new Booking([
                'referer_id' => $validated['referer'] ?? 0,
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
            $pointDepartAndDestination = $this->determinePointDepartAndDestinations($depart, $pointDepId);
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
            // No seat chosen (seat picking wasn't available/used for this bus): don't reserve seats
            // now — just confirm the bus has enough capacity. Actual seat assignment happens later,
            // when the payment is confirmed (see BookingManager::assignBusAndSeatsForUnseatedBookings).

            $seats = collect();
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
     * Resolves the point de départ / destination used for a GP customer booking.
     *
     * When the traveller chose a specific boarding point (possibly a mid-route city like Thies on the
     * Dakar-Saint-Louis trajet), $pointDepId is the id the search endpoint resolved for that city
     * (see TrajetService::resolveTrajetForSearch) and is used directly. Otherwise this falls back to
     * the trajet's designated default GP pickup point.
     */
    public function determinePointDepartAndDestinations(Depart $depart, ?int $pointDepId = null): array
    {
        $defaultPointDep = $pointDepId !== null
            ? $this->resolveChosenPointDepForTrajet($pointDepId, $depart->trajet_id)
            : $this->resolveDefaultGpPointDep($depart->trajet_id);

        //TODO change this later
        $defaultDestination = Destination::where("id", ($depart->trajet_id == Trajet::UGB_DAKAR ? 2 : 3))
            ->firstOrFail();

        return ["point_dep" => $defaultPointDep, "destination" => $defaultDestination];
    }

    /**
     * Resolves a traveller-chosen boarding point, making sure it actually belongs to the depart's own
     * trajet — a point_dep_id valid on another trajet (e.g. the reverse trajet used by a round trip's
     * return leg) must never be silently accepted.
     */
    private function resolveChosenPointDepForTrajet(int $pointDepId, int $trajetId): PointDep
    {
        $pointDep = PointDep::where("id", $pointDepId)
            ->where("trajet_id", $trajetId)
            ->first();

        if ($pointDep === null) {
            throw new \RuntimeException("Le point de départ sélectionné ne correspond pas à ce trajet");
        }

        return $pointDep;
    }

    /**
     * Falls back to the trajet's designated GP pickup point when the traveller didn't choose a
     * specific (possibly mid-route) boarding city.
     */
    private function resolveDefaultGpPointDep(int $trajetId): PointDep
    {
        //TODO change this later
        return match ($trajetId) {
            Trajet::UGB_DAKAR => PointDep::findOrFail(40),
            Trajet::DAKAR_UGB => PointDep::findOrFail(2),
            default => PointDep::where("trajet_id", $trajetId)->firstOrFail(),
        };
    }
}
