<?php

namespace App\Http\Resources;

use App\Models\Bus;
use App\Models\Depart;
use App\Models\Trajet;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @property Carbon $date
 * @property bool $isPassed
 * @property bool $shouldShowSeatNumbers
 * @property Trajet $trajet
 * @property Collection|HasMany $buses
 */
class DepartResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var  $this Depart */
            'id' => $this->id,
            'name' => $this->identifier(with_trajet_prefix: true),
            'isPassed' => $this->isPassed,
            'shouldShowSeatNumbers' => (bool)$this->shouldShowSeatNumbers,
            'trajet' => $this->trajet->name,
            'date' => $this->date->format('Y-m-d H:i:s'),
            "trajet_id" => $this->trajet->id,
            "horaire_id" => $this->horaire_id,
            "event_id" => $this->event_id,
            "visibilite" => $this->visibilite,
//            'date' => $this->date->format('d/m/Y'),
            'buses' => $this->buses->map(fn(Bus $bus) => [
                'id' => $bus->id,
                'name' => $bus->name,
//                "nom" => $bus->name,
                "vehicule_id" => $bus->vehicule_id,
                "full" => $bus->isFull(),
                "closed" => $bus->closed,
                "seatLeft" => $bus->seatsLeft(),
                "nombre_place" => $bus->nombre_place,
                "ticket_price" => $bus->ticket_price,
                "gp_ticket_price" => $bus->gp_ticket_price,
                "visibilite" => $bus->visibilite,
                "numberOfBookedSeats" => $bus->numberOfBookedSeats(),
                "numberOfTicketSold" => $bus->numberOfTicketsSold(),
                "numberOfBookings" => $bus->bookings->count(),
                "itinerary_id" => $bus->itinerary_id,

            ]),
        ];
    }
}
