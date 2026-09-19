<?php

namespace App\Http\Resources;

use App\Models\Bus;
use App\Models\Depart;
use App\Models\PromotionalMessage;
use App\Models\Trajet;
use App\Models\Vehicule;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Lean payload for the public website caravane page (resources/views/website/caravanes/show.blade.php).
 *
 * The mobile app's {@see MobileTrajetDepartsResource} carries per-bus point départs, destinations,
 * attachments and discount fields the website never renders, and resolves them with a query per bus
 * (hundreds of queries on a busy trajet). This resource returns only the fields the caravane page
 * uses and eager-loads everything it needs in 3 queries (départs + buses + one shared promo table),
 * while reusing the same bus-selection rule ({@see Depart::getBusesForBooking()}). Départ pickup
 * times are loaded lazily by the page (MobileAppController::caravaneDepartSchedule()), not here.
 *
 * @property-read Trajet $resource
 */
class CaravaneDepartsResource extends JsonResource
{
    /**
     * @return array{departs: list<array<string, mixed>>}
     */
    public function toArray(Request $request): array
    {
        $upcomingDeparts = $this->loadUpcomingDeparts();
        $promotionalMessages = PromotionalMessage::all();

        // Every vehicle type present on this trajet's buses, id-ordered — the only vehicles
        // Depart::getBusesForBooking() can match, so deriving them here avoids a Vehicule::all() query.
        $availableVehicules = $upcomingDeparts
            ->pluck('buses')->flatten(1)
            ->pluck('vehicule')->filter()
            ->unique('id')->sortBy('id')->values();

        return [
            'departs' => $upcomingDeparts->map(function (Depart $depart) use ($promotionalMessages, $availableVehicules) {
                $bookableBuses = $this->resolveBookableBuses($depart, $availableVehicules);
                $promotionalMessage = $promotionalMessages->first(
                    fn (PromotionalMessage $message) => in_array($depart->id, $message->depart_ids ?? [])
                );

                return [
                    'id' => $depart->id,
                    'name' => $depart->name,
                    'date' => $depart->date->format('Y-m-d H:i:s'),
                    // No `is_closed`/`full` fields: a full or closed départ/bus stays bookable on
                    // the website — the backend puts the customer on the waiting list instead of
                    // rejecting them outright (see StudentBooking's docblock). Only a départ that
                    // has already left ("is_passed") is a hard block, so that's the only
                    // availability flag the UI needs.
                    'is_passed' => $depart->isPassed(),
                    'ticket_price' => $bookableBuses->first()?->ticket_price,
                    'show_promotional_message' => $promotionalMessage !== null && ! $promotionalMessage->paused,
                    'promotional_message' => $promotionalMessage?->message,
                    'buses' => $bookableBuses->map(fn (Bus $bus) => [
                        'id' => $bus->id,
                        'name' => $bus->vehicule?->name ?? 'Bus ordinaire',
                        'climatise' => (bool) $bus->vehicule?->climatise,
                        'ticket_price' => $bus->ticket_price,
                    ])->values()->all(),
                ];
            })->values()->all(),
        ];
    }

    /**
     * Upcoming départs visible to customers, with their buses + each bus's vehicle and a
     * `has_available_seat` flag (an EXISTS probe, cheaper than counting) so {@see Bus::isFull()}
     * needs no per-bus query. That flag isn't exposed in the output (see toArray()) — it's still
     * loaded because {@see Depart::getBusesForBooking()} calls isFull() internally to prefer an
     * open bus when several share the same vehicle type.
     *
     * @return EloquentCollection<int, Depart>
     */
    private function loadUpcomingDeparts(): EloquentCollection
    {
        $departs = $this->resource->departs()
            ->where('date', '>=', now())
            ->whereIn('visibilite', [Depart::VISIBILITE_ALL_CUSTOMERS, Depart::VISIBILITE_ST_CUSTOMERS_ONLY])
            ->orderBy('date')
            ->with(['buses' => fn ($query) => $query->with('vehicule')->withExists([
                'seats as has_available_seat' => fn ($seatsQuery) => $seatsQuery->whereNotExists(
                    fn ($bookingQuery) => $bookingQuery->select('id')
                        ->from('bookings')
                        ->whereColumn('bookings.seat_id', 'bus_seats.id')
                        ->whereNull('bookings.deleted_at')
                ),
            ])])
            ->get();

        // Point each loaded bus back at its départ so Bus::isClosed() reads $bus->depart->closed
        // from memory instead of lazy-loading the départ again.
        $departs->each(fn (Depart $depart) => $depart->buses->each->setRelation('depart', $depart));

        return $departs;
    }

    /**
     * @param  Collection<int, Vehicule>  $availableVehicules
     * @return Collection<int, Bus>
     */
    private function resolveBookableBuses(Depart $depart, Collection $availableVehicules): Collection
    {
        try {
            return $depart->getBusesForBooking($availableVehicules);
        } catch (ModelNotFoundException) {
            return new Collection;
        }
    }
}
