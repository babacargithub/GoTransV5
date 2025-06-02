<?php

namespace App\Http\Resources;

use App\Models\Booking;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingForExportResource extends JsonResource
{
    public function toArray($request): array
    {
        $booking = $this->resource;
        /** @var Booking $booking */
            return [
                "id" => $booking->id,
                "seatNumber" => $booking->seat?->number,
                "client" => $booking->passenger_full_name,
                "phoneNumber" => $booking->customer->phone_number,
                "pointDep" => $booking->point_dep->name,
                "destination" => $booking->destination->name,
                "arretBus" => $booking->point_dep->arret_bus,
                "formattedSchedule" => $booking->formatted_schedule,
                "hasTicket" => $booking->has_ticket,
                "ticketSoldBy" => $booking->ticket?->soldBy,
                "hasSeat" => $booking->has_seat,
                "rendezVousPoint" => $booking->point_dep->arret_bus,

            ];

    }

}