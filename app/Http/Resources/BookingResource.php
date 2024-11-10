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
    {/*
    {
    "@type": "Booking",
    "@id": "/api/bookings/186408",
    "depart": "Après-midi dimanche 27 octobre-Bus 1",
    "seatNumber": 6,
    "client": {
        "@id": "/api/clients/43326",
        "@type": "Client",
        "nomComplet": "Siakha KABA",
        "shortFirstName": "s.",
        "fullName": "Siakha KABA",
        "prenom": "siakha",
        "nom": "kaba",
        "firstName": "siakha",
        "lastName": "kaba",
        "email": null,
        "phoneNumber": 772000813,
        "adresse": null,
        "createdAt": "2022-04-29T17:04:21+00:00",
        "sexe": null,
        "disabled": false,
        "deleted": false,
        "deleatedAt": null,
        "active": true,
        "lastActive": "2024-07-26T20:42:26+00:00",
        "categorie": null,
        "id": 43326,
        "bookings": [
            "/api/bookings/143729",
            "/api/bookings/145988",
            "/api/bookings/147407",
            "/api/bookings/149685",
            "/api/bookings/149773",
            "/api/bookings/151332",
            "/api/bookings/152247",
            "/api/bookings/156987",
            "/api/bookings/160665",
            "/api/bookings/161648",
            "/api/bookings/164021",
            "/api/bookings/165881",
            "/api/bookings/167848",
            "/api/bookings/173874",
            "/api/bookings/175250",
            "/api/bookings/175512",
            "/api/bookings/179257",
            "/api/bookings/182251",
            "/api/bookings/182353",
            "/api/bookings/186408"
        ],
        "nombreVoyage": 20,
        "unusedTickets": [],
        "unusedTicket": false,
        "currentBooking": "/api/bookings/186408",
        "travelCount": 20
    },
    "destination": {
        "@id": "/api/destinations/3",
        "@type": "Destination",
        "name": "Campus UGB",
        "tarif": 3600,
        "trajet": "/api/trajets/2",
        "id": 3
    },
    "pointDep": {
        "id": 1,
        "name": "Ecole Normale",
        "departureSchedule": "05:20",
        "departureScheduleEvening": "12:00",
        "arretBus": "Devant Ecole Normale",
        "heure": null,
        "disabled": false
    },
    "hasSeat": true,
    "hasTicket": true,
    "formattedSchedule": "12h:30",
    "rendezVousPoint": "Devant Ecole Normale",
    "ticketSoldBy": "appli_mobile",
    "paymentMethod": "wave",
    "isPassed": false,
    "qrCode": null,
    "group_id": null,
    "belongsToGroup": false,
    "groupMembersCount": null,
    "id": 186408
}*/

        return [
            /** @var  $this Booking */
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
            "belongsToGroup" => $this->group_id != null,
//            "groupMembersCount" => $this->getOtherBookingsOfSameGroup()->count(),
            'client' => [
                "fullName" => $this->customer->fullName,
                "phoneNumber" => $this->customer->phoneNumber,

            ],
            'pointDep' => $this->point_dep->name,
            'destination' => $this->destination->name,
//
        ];
    }
}
