<?php

namespace App\Http\Resources;

use App\Manager\TicketManager;
use App\Models\Bus;
use App\Models\Depart;
use App\Models\Destination;
use App\Models\HeureDepart;
use App\Models\PointDep;
use App\Models\PromotionalMessage;
use App\Models\Trajet;
use App\Models\Vehicule;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @property string $libelle
 * @property Carbon $date
 * @property bool $isPassed
 * @property bool $shouldShowSeatNumbers
 * @property Trajet $trajet
 * @property Collection|HasMany $buses
 */
class MobileTrajetDepartsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var  $this Trajet */
        return [
            'id' => $this->id,
            'name' => $this->name,
            'departs' => $this->departs()->where("date",">=", now())->orderBy('date')->get()->map(function ($depart) {
               /* return [
                    'id' => $depart->id,
                    'name' => $depart->name,
                    'ticketPrice' => $depart->getBusForBooking()?->ticket_price,
                    'isClosed' => $depart->closed,
                    'schedules' => $depart->heuresDeparts->map(function (HeureDepart $schedule) {
                        return [
                            'name' => $schedule->pointDep->name,
                            'schedule' => $schedule->heureDepart->format('H:i')
                        ];
                    }),
                    'buses' => $this->getBusesForBooking($depart),

                ];*/
                return $this->departResource($depart);
            }),
            'pointDeparts' => $this->pointDeps->map(function (PointDep $pointDepart) {
                return [
                    'name' => $pointDepart->name,
                    'id' => $pointDepart->id
                ];
            }),
            'destinations' => $this->destinations->map(function (Destination $destination) {
                return [
                    'name' => $destination->name,
                    'id' => $destination->id
                ];
            })
        ];

    }
    private function departResource(Depart $depart): array
    {
        $departArray = [
            /** @var  $depart Depart */
            'id' => $depart->id,
            'name' => $depart->name,
            'ticket_price' => $depart->getBusForBooking()?->ticket_price,
            'is_closed' => $depart->closed,
            'is_passed' => $depart->isPassed(),
            'date' => $depart->date->format('Y-m-d H:i:s'),
            "trajet_id" => $depart->trajet->id,
            "show_ticket_price" => false,
            'buses' => $this->getBusesForBooking($depart)->count() >1 ?$this->getBusesForBooking($depart)->map(fn(Bus
                                                                                                                      $bus) =>
    array_merge_recursive([
                'id' => $bus->id,
                'name' => $bus->vehicule != null ? $bus->vehicule->name : "Bus ordinaire",
                "description" => $bus->vehicule?->description,
                "attachements" => $bus->vehicule?->attachements,
                "features" => $bus->vehicule->features ??  [],
                "full" => $bus->isFull(),
                "closed" => $bus->closed,
                "nombre_place" => $bus->nombre_place,
                "ticket_price" => $bus->ticket_price,

            ], $this->dataCommonToDepartAndBus($bus))):[],


        ];
        $commonData = $this->dataCommonToDepartAndBus($depart);
        return array_merge($departArray, $commonData);

    }
    private function dataCommonToDepartAndBus($item ): array
    {
        $bus = null;
        if ($item instanceof Depart) {
            $depart = $item;
        }else if ($item instanceof Bus) {
            $bus = $item;
            $depart = $bus->depart;
        }else{
            return [];
        }
        $discountedPrice = $depart->getBusForBooking()->ticket_price - TicketManager::DISCOUNT_AMOUNT - 50;
        $data = [
            "show_ticket_price" => true,
            "promotional_message" =>null,
            "show_promotional_message" => false,
            "show_discount" => true,
            "discount_amount" => TicketManager::DISCOUNT_AMOUNT,
            "discounted_price" => $discountedPrice,
            "discount_message"=>"Vous aurez une réduction de  ".($discountedPrice)." FCFA sur le prix du ticket",
            "start_point" => $depart->trajet->start_point,
            "end_point" => $depart->trajet->end_point,
        ];
        try {
            $promotion = PromotionalMessage::whereJsonContains("depart_ids", $depart->id)->firstOrFail();
            $data["promotional_message"] = $promotion->message;
            $data["show_promotional_message"] = !$promotion->paused;
            $data["schedules"] =  $depart->heuresDeparts->map(function (HeureDepart $schedule) {
                return [
                    'name' => $schedule->pointDep->name,
                    'schedule' => $schedule->heureDepart->format('H:i'),
                    "arret_bus" => $schedule->arretBus,
                ];
            });
            return $data;
        } catch (ModelNotFoundException $e) {

        }

        if ($bus instanceof Bus) {
            try {
                $promotion = PromotionalMessage::whereJsonContains("bus_ids", $bus->id)->firstOrFail();
                $data["promotional_message"] = $promotion->message;
                $data["show_promotional_message"] = !$promotion->paused;
                return $data;
            } catch (ModelNotFoundException $e) {
                return  $data;
            }

        }
        return  $data;


}
    private function getBusesForBooking(Depart $depart): Collection
    {
        $vehicles = Vehicule::all();
        // for each vehicle, find one bus having that vehicle and not full and not closed
        // we take the first bus matched the above criteria
        // we use collection->filter to filter buses that

        $busesForBooking = [];
        // take also one buses where vehicule is null
        $bus = $depart->buses->filter(function (Bus $bus) {
            return $bus->vehicule_id == null && !$bus->isFull() && !$bus->isClosed();
        })->first();
        if ($bus != null) {
            $busesForBooking[] = $bus;
        }else{
            $bus = $depart->buses->filter(function (Bus $bus) {
                return $bus->vehicule_id == null;
            })->first();
            if ($bus != null) {
                $busesForBooking[] = $bus;
            }
        }
        foreach ($vehicles as $vehicule){
            $bus = $depart->buses->filter(function (Bus $bus) use ($vehicule) {
                return $bus->vehicule_id == $vehicule->id;
//                    && !$bus->isFull() && !$bus->isClosed();
            })->first();
            if ($bus != null) {
                $busesForBooking[] = $bus;
            }
        }

        if (count($busesForBooking) == 0) {
            $busesForBooking[] = $depart->getBusForBooking();
        }
        return collect($busesForBooking);
    }

}
