<?php

namespace App\Http\Resources;

use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $fullName = $this->passenger_full_name;

        return [
            /** @var  $booking Booking */
            'id' => $this->id,
//            'depart' => $this->depart->name,
            'seatNumber' => $this->seat?->seat->number,
            "hasTicket" => $this->has_ticket,
            "hasSeat" => $this->has_seat,
            "formattedSchedule" => $this->formatted_schedule,
            "rendezVousPoint" => $this->point_dep->arretBus,
            "ticketSoldBy" => $this->ticket?->soldBy,
            "paymentMethod" => $this->ticket?->payment_method,
            "isPassed" => $this->depart->isPassed(),
            "group_id" => $this->group_id,
            "isForGp" => $this->is_for_gp,
            "belongsToGroup" => $this->group_id != null,
//            "groupMembersCount" => $this->getOtherBookingsOfSameGroup()->count(),
            'client' => [
                "fullName" =>  $fullName,
                "phoneNumber" => $this->customer->phone_number,

            ],
            "extra_info"=>[
                "id" => $this->id,
                "transactionId" => $this->ticket?->comment,
                "group_id" => $this->group_id

            ],
            'pointDep' => $this->point_dep->name,
            'destination' => $this->destination->name,
//
        ];
    }
}
