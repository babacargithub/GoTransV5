<?php

namespace App\Services;

use App\Manager\TicketManager;
use App\Models\Bus;
use App\Models\Depart;
use App\Models\PointDep;
use App\Models\Trajet;
use DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class TrajetService
{
    public function __construct(private readonly TicketManager $ticketManager)
    {
    }

    public function listAll(): \Illuminate\Database\Eloquent\Collection
    {
        return Trajet::with('pointDeps', 'destinations', 'horaires')->get();
    }

    /**
     * Lists departs available to GP customers.
     *
     * When the traveller has picked a departure/arrival city pair (the common case, letting them
     * board at a mid-route city like Thiès), this delegates to the same city-based search used
     * everywhere else: it resolves the exact boarding PointDep and returns its id (point_dep_id)
     * alongside the correctly computed ticket price, so the front end just forwards that
     * point_dep_id back when creating the booking — no separate boarding-point step needed.
     * Falls back to the old unfiltered trajet/depart listing when no city pair is given.
     */
    public function listDepartsForGp(Request $request): JsonResponse
    {
        if ($request->filled('departure_city') && $request->filled('arrival_city') && $request->filled('travel_date')) {
            $request->validate([
                'departure_city' => 'required|string',
                'arrival_city' => 'required|string',
                'travel_date' => 'required|date',
                'return_date' => 'nullable|date|after_or_equal:travel_date',
            ]);

            return $this->searchByCities($request);
        }

        $trajets = Trajet::all()->map(function (Trajet $trajet) {
            return [
                'id' => $trajet->id,
                'name' => $trajet->name,
                'departs' => $trajet->departs()
                    ->where('date', '>=', now())
                    ->where(function ($query) {
                        $query->where('visibilite', Depart::VISIBILITE_GP_CUSTOMERS_ONLY)
                            ->orWhere('visibilite', Depart::VISIBILITE_ALL_CUSTOMERS);
                    })
                    ->orderBy('date')
                    ->get()
                    ->map(function (Depart $depart) {
                        return [
                            'id' => $depart->id,
                            'name' => $depart->name,
                            'ticket_price' => $depart->getBusForBooking(climatise: true)?->ticket_price,
                            'is_closed' => $depart->closed,
                        ];
                    }),
            ];
        });

        return response()->json($trajets);
    }

    public function loadDetails(Trajet $trajet): Trajet
    {
        return $trajet->load('pointDeps', 'destinations', 'horaires');
    }

    public function createTrajet(array $data): JsonResponse
    {
        $pointDeparts = $data['point_departs'];
        $destinations = $data['destinations'];
        $horaires = $data['horaires'];

        DB::transaction(function () use ($data, $pointDeparts, $destinations, $horaires) {
            $trajet = Trajet::create([
                'name' => $data['name'],
                'start_point' => $data['start_point'] ?? null,
                'end_point' => $data['end_point'] ?? null,
                'public_name' => $data['public_name'] ?? null,
                'departure_city' => $data['departure_city'] ?? null,
                'arrival_city' => $data['arrival_city'] ?? null,
                'code' => $data['code'] ?? null,
                'length' => $data['length'] ?? null,
            ]);
            $trajet->pointDeps()->createMany($pointDeparts);
            $trajet->destinations()->createMany($destinations);
            $trajet->horaires()->createMany($horaires);
        });

        return response()->json(["message" => "Trajet créé avec succès !"], 201);
    }

    public function updateTrajet(Trajet $trajet, array $data): void
    {
        DB::transaction(function () use ($data, $trajet) {
            $trajet->update([
                'name' => $data['name'] ?? $trajet->name,
                'public_name' => $data['public_name'] ?? $trajet->public_name,
                'departure_city' => $data['departure_city'] ?? $trajet->departure_city,
                'arrival_city' => $data['arrival_city'] ?? $trajet->arrival_city,
                'code' => $data['code'] ?? $trajet->code,
                'length' => $data['length'] ?? $trajet->length,
            ]);
            if (isset($data['point_departs'])) {
                foreach ($data['point_departs'] as $pointDep) {
                    $pointDepModel = $trajet->pointDeps()->find($pointDep['id']);
                    if ($pointDepModel) {
                        $pointDepModel->update($pointDep);
                    }
                }
            }
            if (isset($data['destinations'])) {
                foreach ($data['destinations'] as $destination) {
                    $destinationModel = $trajet->destinations()->find($destination['id']);
                    if ($destinationModel) {
                        $destinationModel->update($destination);
                    }
                }
            }
            if (isset($data['horaires'])) {
                foreach ($data['horaires'] as $horaire) {
                    $horaireModel = $trajet->horaires()->find($horaire['id']);
                    if ($horaireModel) {
                        $horaireModel->update($horaire);
                    }
                }
            }
        });
    }

    public function deleteTrajet(Trajet $trajet): void
    {
        $trajet->delete();
    }

    /**
     * Resolves the trajet running in the opposite direction of the given trajet (arrival/departure cities swapped).
     */
    public function resolveReverseTrajet(Trajet $trajet): ?Trajet
    {
        return Trajet::where('departure_city', $trajet->arrival_city)
            ->where('arrival_city', $trajet->departure_city)
            ->first();
    }

    /**
     * Finds the departs, on the reverse trajet of the given trajet, that run on the given date.
     */
    public function findDepartsForReverseTrajet(Trajet $trajet, string $date): Collection
    {
        $reverseTrajet = $this->resolveReverseTrajet($trajet);
        if ($reverseTrajet == null) {
            return collect();
        }

        return $reverseTrajet->departs()
            ->notPassed()
            ->whereDate('date', $date)
            ->where(function ($query) {
                $query->where('visibilite', Depart::VISIBILITE_GP_CUSTOMERS_ONLY)
                    ->orWhere('visibilite', Depart::VISIBILITE_ALL_CUSTOMERS);
            })
            ->get();
    }

    /**
     * Finds the depart, on the reverse trajet of the given outbound depart, that runs on the given return date.
     */
    public function findReturnDepart(Depart $outboundDepart, string $returnDate): ?Depart
    {
        return $this->findDepartsForReverseTrajet($outboundDepart->trajet, $returnDate)->first();
    }

    /**
     * Resolves the return-leg depart for a round trip: the explicitly chosen return_depart_id when
     * given (validated to actually be on the outbound trajet's reverse trajet), otherwise falls back
     * to auto-picking a depart running on the return date (legacy behavior for older clients).
     */
    public function resolveReturnDepart(Depart $outboundDepart, ?int $returnDepartId, string $returnDate): ?Depart
    {
        if ($returnDepartId !== null) {
            $reverseTrajet = $this->resolveReverseTrajet($outboundDepart->trajet);
            if ($reverseTrajet === null) {
                return null;
            }

            return Depart::where('id', $returnDepartId)
                ->whereNull('canceled_at')
                ->where('trajet_id', $reverseTrajet->id)
                ->first();
        }

        return $this->findReturnDepart($outboundDepart, $returnDate);
    }

    /**
     * Asserts that a return-leg depart is actually bookable: not passed, not closed/full at the
     * depart or bus level, and has enough seats for the given passenger count. Returns the bus to
     * book on success; throws a RuntimeException with a translated message otherwise. Shared by
     * GpBookingRequest (validation) and BookingService (booking creation) so both stay in sync.
     */
    public function assertReturnDepartBookable(Depart $returnDepart, int $passengerCount): Bus
    {
        if ($returnDepart->isPassed()) {
            throw new \RuntimeException('Le voyage retour pour cette date est déjà passé. Merci de choisir une autre date.');
        }

        if ($returnDepart->isClosed() || $returnDepart->isFull()) {
            throw new \RuntimeException('Les réservations pour le voyage retour sont fermées. Merci de choisir une autre date.');
        }

        try {
            $returnBus = $returnDepart->getBusForBooking(climatise: true);
        } catch (ModelNotFoundException) {
            throw new \RuntimeException("Il n'y a pas de bus disponible pour le voyage retour. Merci de choisir une autre date.");
        }

        if ($returnBus === null) {
            throw new \RuntimeException("Il n'y a pas de bus disponible pour le voyage retour choisi. Merci de choisir une autre date.");
        }

        if ($returnBus->isClosed()) {
            throw new \RuntimeException('Les réservations pour le voyage retour sont fermées. Merci de choisir une autre date.');
        }

        if ($returnBus->isFull()) {
            throw new \RuntimeException('Le bus du voyage retour est déjà plein, il ne reste plus de place. Merci de choisir une autre date.');
        }

        if ($returnBus->seatsLeft() < $passengerCount) {
            throw new \RuntimeException('Il ne reste que ' . $returnBus->seatsLeft() . ' place(s) disponible(s) pour le voyage retour, ce qui n\'est pas suffisant pour ' . $passengerCount . ' passager(s).');
        }

        return $returnBus;
    }

    /**
     * Search trajets by departure and arrival cities, with an optional round-trip return_date.
     */
    public function searchByCities(Request $request): JsonResponse
    {
        $data = [];
        $returnData = [];
        $isRoundTrip = $request->filled('return_date');
        $cheapestReturnPrice = null;

        try {
            [$trajet, $boardingPointDep] = $this->resolveTrajetForSearch($request->departure_city, $request->arrival_city);

            if ($trajet === null) {
                throw new ModelNotFoundException();
            }

            if ($isRoundTrip) {
                $returnDeparts = $this->findDepartsForReverseTrajet($trajet, $request->return_date);
                if ($returnDeparts->isEmpty()) {
                    $formattedReturnDate = \Illuminate\Support\Carbon::parse($request->return_date)->format('d/m/Y');
                    return response()->json([
                        'message' => "Nous n'avons pas prévu de voyage retour pour la date $formattedReturnDate de "
                            . $request->arrival_city . " à " . $request->departure_city,
                    ], 404);
                }
                $reverseTrajet = $this->resolveReverseTrajet($trajet);
                $returnData = collect($returnDeparts)->map(function ($depart) use ($reverseTrajet) {
                    return $this->formatDepartForSearch($depart, $reverseTrajet);
                });
                // The return leg never inherits the outbound's (possibly mid-route-discounted) boarding
                // point, so its fare can genuinely differ — use the cheapest actual return fare found,
                // not the outbound price, to avoid under-quoting the round-trip total at checkout.
                $cheapestReturnPrice = $returnData->pluck('ticket_price')->filter(fn($price) => $price !== null)->min();

            }

            $departs = $trajet->departs()->where('date', '>=', now())
                ->whereDate('date', '=', $request->travel_date)
                ->where(function ($query) {
                    $query->where('visibilite', Depart::VISIBILITE_GP_CUSTOMERS_ONLY)
                        ->orWhere('visibilite', Depart::VISIBILITE_ALL_CUSTOMERS);
                })
                ->get();

            $data = collect($departs)->map(function ($depart) use ($trajet, $boardingPointDep, $isRoundTrip, $cheapestReturnPrice) {
                $item = $this->formatDepartForSearch($depart, $trajet, $boardingPointDep);
                if ($isRoundTrip) {
                    $item['one_way_price'] = $item['ticket_price'];
                    $item['return_price'] = $cheapestReturnPrice;
                }

                return $item;
            });
            $message = 'Trajets found successfully';
        } catch (ModelNotFoundException $e) {
            $message = 'Trajets found not found';
        }

        $response = [
            'data' => $data,
            'message' => $message,
        ];

        if ($isRoundTrip) {
            $response['is_round_trip'] = true;
            $response['return_data'] = $returnData;
            $response['round_trip_available'] = $cheapestReturnPrice !== null;
            if ($cheapestReturnPrice === null && $message === 'Trajets found successfully') {
                $response['message'] = "Aucun départ retour n'est disponible pour cette date, les prix affichés sont pour un aller simple.";
            }
        }

        return response()->json($response);
    }

    /**
     * Resolves the trajet (and, for a mid-route boarding search, the specific PointDep) matching a
     * departure/arrival city pair.
     *
     * Tries an exact trajet endpoint match first (unchanged, most common case). If none exists, falls
     * back to treating departure_city as a mid-route boarding point: a PointDep marked with that city,
     * on a trajet whose own arrival_city is exactly the requested arrival_city.
     *
     * If that still finds nothing, tries a second fallback for the swapped case: arrival_city as a
     * mid-route point on the trajet running the opposite direction. E.g. THIES is a mid-route PointDep
     * on DAKAR->SAINT-LOUIS; searching SAINT-LOUIS -> THIES won't match either check above (SAINT-LOUIS
     * isn't a mid-route city and THIES isn't a trajet endpoint), but the SAINT-LOUIS->DAKAR trajet's bus
     * physically passes through THIES too, so it's the trajet the traveller actually wants.
     *
     * @return array{0: ?Trajet, 1: ?PointDep}
     */
    private function resolveTrajetForSearch(string $departureCity, string $arrivalCity): array
    {
        $trajet = Trajet::where('departure_city', $departureCity)
            ->where('arrival_city', $arrivalCity)
            ->first();

        if ($trajet !== null) {
            return [$trajet, null];
        }

        $boardingPointDep = PointDep::where('city', $departureCity)
            ->where('disabled', false)
            ->where(function (Builder $query) {
                $query->where('visibilite', Depart::VISIBILITE_GP_CUSTOMERS_ONLY)
                    ->orWhere('visibilite', Depart::VISIBILITE_ALL_CUSTOMERS);
            })
            ->whereHas('trajet', fn(Builder $query) => $query->where('arrival_city', $arrivalCity))
            ->with('trajet')
            ->first();

        if ($boardingPointDep !== null) {
            return [$boardingPointDep->trajet, $boardingPointDep];
        }

        $trajetWithMatchingMidRouteDestination = Trajet::where('arrival_city', $departureCity)
            ->whereHas('pointDeps', function (Builder $query) use ($arrivalCity) {
                $query->where('city', $arrivalCity)
                    ->where('disabled', false)
                    ->where(function (Builder $query) {
                        $query->where('visibilite', Depart::VISIBILITE_GP_CUSTOMERS_ONLY)
                            ->orWhere('visibilite', Depart::VISIBILITE_ALL_CUSTOMERS);
                    });
            })
            ->first();

        if ($trajetWithMatchingMidRouteDestination !== null) {
            $reverseTrajet = $this->resolveReverseTrajet($trajetWithMatchingMidRouteDestination);
            if ($reverseTrajet !== null) {
                return [$reverseTrajet, null];
            }
        }

        return [null, null];
    }

    /**
     * Formats a depart for the departs/search response (id, times, seat availability, one-leg ticket price).
     *
     * @param Trajet|null $trajet The trajet the depart belongs to; used to resolve a default boarding point
     *                            when $boardingPointDep isn't given. Falls back to $depart->trajet if omitted.
     * @param PointDep|null $boardingPointDep When set (mid-route search), the actual boarding stop and
     *                                        schedule used, instead of the trajet's default point_dep.
     */
    private function formatDepartForSearch(Depart $depart, ?Trajet $trajet = null, ?PointDep $boardingPointDep = null): array
    {
        $bus = $depart->getBusForBooking(climatise: false);
        $trajet ??= $depart->trajet;

        // Origin-city search (no mid-route boarding point resolved): fetch every GP-visible boarding
        // point on the trajet up front, so the same, correctly filtered/ordered list can both price the
        // depart (using its first entry as the default) and be returned to let the customer pick one.
        $originPointDeps = $boardingPointDep === null
            ? ($trajet?->pointDeps()
                ->where('disabled', false)
                ->where(function ($query) {
                    $query->where('visibilite', Depart::VISIBILITE_GP_CUSTOMERS_ONLY)
                        ->orWhere('visibilite', Depart::VISIBILITE_ALL_CUSTOMERS);
                })
                ->orderBy('position')
                ->get(['id', 'name', 'arret_bus']) ?? collect())
            : null;

        $pointDep = $boardingPointDep ?? $originPointDeps->first();

        $result = [
            'id' => $depart->id,
            // $depart->date is a nominal/reference timestamp, not the actual pickup time at any given
            // stop — that lives per point_dep in heure_departs (see resolveDepartureTimeForPointDep).
            // Always resolve it from the (mid-route or default) point_dep when one is available; only
            // fall back to the raw depart date when the trajet has no usable point_dep at all.
            'departure_time' => $pointDep !== null
                ? $this->resolveDepartureTimeForPointDep($depart, $bus, $pointDep)
                : $depart->date->format('H\hi'),
            "departure_date" => $depart->date->format('Y-m-d'),
            "seats_left" => $bus?->seatsLeft() . " " . $bus?->full_name,
            'seats_remaining' => $bus?->seatsLeft() > 0 && !$bus?->isClosed() ? $bus?->seatsLeft() : 0,
            'ticket_price' => $bus !== null
                ? $this->ticketManager->calculateTicketPrice($bus, $pointDep, \is_request_for_gp_customers())
                : null,
            'boarding_point' => $pointDep?->name,
        ];

        if ($boardingPointDep !== null) {
            // Mid-route boarding city: a single, exact PointDep was already resolved, so tell the
            // customer precisely where in that city the bus will pick them up.
            $result['point_dep_id'] = $pointDep?->id;
            $result['arret_bus'] = $pointDep?->arret_bus;
        } else {
            // Origin-city search: several boarding points may exist within the trajet's own departure
            // city. 'boarding_point' above is just the default (first) one, for a quick preview label
            // (e.g. "Premier point de départ: {boarding_point}") — the frontend must still let the
            // customer pick the exact one from 'point_deps' before booking, since no point_dep_id is
            // returned here.
            $result['point_deps'] = $originPointDeps;
        }

        return $result;
    }

    /**
     * Resolves the actual pickup time at a given point_dep for a depart/bus, falling back to the
     * trajet's overall departure time when no specific schedule was recorded for that stop.
     */
    private function resolveDepartureTimeForPointDep(Depart $depart, ?Bus $bus, PointDep $pointDep): string
    {
        $heureDepart = $bus?->heuresDeparts()
            ->join('point_deps', 'point_deps.id', '=', 'point_dep_id')
            ->where('point_dep_id', $pointDep->id)
            ->where('point_deps.disabled', false)
            ->orderBy('position')
            ->first();

        if ($heureDepart === null) {
            $heureDepart = $depart->heuresDeparts()->where('point_dep_id', $pointDep->id)
                ->join('point_deps', 'point_deps.id', '=', 'point_dep_id')
                ->where('point_deps.disabled', false)
                ->orderBy('position')
                ->first();
        }

        return $heureDepart !== null ? $heureDepart->heureDepart->format('H\hi') : $depart->date->format('H\hi');
    }

    public function getCities(): JsonResponse
    {
        $departureCities = Trajet::whereNotNull('departure_city')
            ->distinct()
            ->pluck('departure_city')
            ->map(function ($city, $index) {
                return ['id' => $index + 1, 'name' => $city];
            });

        $arrivalCities = Trajet::whereNotNull('arrival_city')
            ->distinct()
            ->pluck('arrival_city')
            ->map(function ($city, $index) {
                return ['id' => $index + 100, 'name' => $city];
            });

        // Mid-route boarding cities (e.g. Thies) — a PointDep marked with a city customers can board at.
        $pointDepCities = PointDep::withoutGlobalScope('order')
            ->whereNotNull('city')
            ->where('disabled', false)
            ->distinct()
            ->pluck('city')
            ->map(function ($city, $index) {
                return ['id' => $index + 200, 'name' => $city];
            });

        $cities = $departureCities->merge($arrivalCities)
            ->merge($pointDepCities)
            ->unique('name')
            ->sortBy('name')
            ->values();

        return response()->json([
            'data' => $cities,
            'message' => 'Cities retrieved successfully',
        ]);
    }
}
