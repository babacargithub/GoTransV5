<?php

namespace App\Http\Resources;

use App\Models\AppParams;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MobileBookingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {  /** @var Booking $booking */
//        $booking->load('depart', 'seat', 'point_dep', 'destination', 'ticket');
        $data = [
            'id' => $this->id,
            'depart' => $this->bus->depart->name,
            'seatNumber' => $this->seat?->number,
            "hasTicket" => $this->has_ticket,
            "hasSeat" => $this->has_seat,
            "bus"=> $this->bus->name,
            "formattedSchedule" => $this->formatted_schedule,
            "rendezVousPoint" => $this->point_dep->arretBus,
            "paymentMethod" => $this->ticket?->payment_method,
            "isPassed" => $this->depart->isPassed(),
            "group_id" => $this->group_id,
            "belongsToGroup" => $this->group_id != null,
            'pointDep' => $this->point_dep->name,
            'destination' => $this->destination->name,

            "agentNumber"=>is_request_for_gp_customers()? 777794818 : AppParams::first()?->getBusAgentDefaultNumber()
//
        ];
        if (is_request_for_gp_customers()){
            $data["customerName"] = $this->customer->full_name;
        }
        if ($this->has_ticket) {
            $data['ticket_price'] = $this->ticket->price;
        }
        return  $data;
    }
}
