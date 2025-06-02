<?php

namespace App\Manager;

use App\Models\Booking;
use App\Models\Bus;
use App\Models\BusSeat;
use App\Models\Customer;
use App\Models\Destination;
use App\Models\PointDep;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class BusManager
{
    public function transferBookings(Bus $sourceBus, Bus $targetBus, $transferData): true|JsonResponse
    {
        if (!isset($transferData['numberOfBookingsToTransfer']) || !isset($transferData['transferType'])) {
            throw new InvalidArgumentException("numberOfBookingsToTransfer and transferType are required");
        }

        $numberOfBookingsToTransfer = $transferData['numberOfBookingsToTransfer'];
        // if $numberOfBookingsToTransfer is -1, transfer all bookings of the transferType chosen
        $transferType = $transferData['transferType'];
        // check if there are enough seats in the target bus

        // transferType 1 means transfer bookings with no ticket
        // transferType 2 means transfer bookings with ticket
        // transferType 3 means transfer all bookings
        if ($numberOfBookingsToTransfer == -1) {
            $numberOfBookingsToTransfer = $sourceBus->bookings()->where(function ($query) use ($transferType) {
                if ($transferType == 1) {
                    $query->whereNull('ticket_id');
                } elseif ($transferType == 2) {
                    $query->whereNotNull('ticket_id');
                }
            })->count();
        }
        if ($transferType == 2 || $transferType == 3) {
            if ($targetBus->seatsLeft() < $numberOfBookingsToTransfer) {
                return response()->json(['message' => 'Il n\'y a pas assez de places dans le bus cible'], 422);
            }
        }
        $bookingsToTransfer = $sourceBus->bookings()->where(function ($query) use ($transferType) {
            if ($transferType == 1) {
                $query->whereNull('ticket_id');
            } elseif ($transferType == 2) {
                $query->whereNotNull('ticket_id');
            }
        })->limit($numberOfBookingsToTransfer)
            ->orderByDesc("created_at")->get();

        $availableSeats = $targetBus->seats()->where('booked', false)->get();
        DB::transaction(function () use ($bookingsToTransfer, $targetBus, $availableSeats) {
            $bookingsToTransfer->each(function (Booking $booking) use ($targetBus, $availableSeats) {

                $booking->bus_id = $targetBus->id;
                $booking->depart_id = $targetBus->depart_id;
                if ($booking->has_seat || $booking->has_ticket) {
                    $seat = $booking->seat;
                    $seat?->freeSeat();
                    $seat?->save();
                    $booking->seat_id = null;// get one available seat et put the cursor to the next seat
                    if ($booking->has_ticket) {
                        $newSeat = $availableSeats->shift();
                        if ($newSeat instanceof BusSeat) {
                            $newSeat->book();
                            $newSeat->save();
                            $booking->seat_id = $newSeat->id;
                            $booking->save();
                        } else {
                            throw new UnprocessableEntityHttpException("Impossible de trouver un siège pour la réservation !",
                                null);
                        }
                    } else {
                    }
                }
                $booking->save();
            });
        });

        return true;

    }

    /**
     * @throws \Exception
     */
    public function convertYobumaPassengersToList(Bus $bus, array $yobumaPassengers, $ticketPrice = 6000)
    {
        if (count($yobumaPassengers) == 0) {
            return [];
        }
        // passengers data look like this:
        /*
         * $passengers = [
    [
        "prenom" => "Idrissa",
        "nom" => "Diop",
        "telephone" => "221784731215",
        "siege" => "22"
    ],
    [
        "prenom" => "Ndeye Aminata",
        "nom" => "Mbaye",
        "telephone" => "221771818596",
        "siege" => "20"
    ],
    [
        "prenom" => "Youhanidou Mar",
        "nom" => "Diop",
        "telephone" => "221771431838",
        "siege" => "19"
    ],
    [
        "prenom" => "Babacar",
        "nom" => "Diop",
        "telephone" => "221774036640",
        "siege" => "2"
    ],
    [
        "prenom" => "Khoudia",
        "nom" => "Niang",
        "telephone" => "221774036640",
        "siege" => "1"
    ],
    [
        "prenom" => "Seydina mouhamed",
        "nom" => "Diop",
        "telephone" => "221774036640",
        "siege" => "3"
    ],
    [
        "prenom" => "Baye malick",
        "nom" => "Gueye",
        "telephone" => "221781741247",
        "siege" => "38"
    ],
    [
        "prenom" => "Ndeye Coumba",
        "nom" => "Keita",
        "telephone" => "221771993857",
        "siege" => "25"
    ],
    [
        "prenom" => "Adama",
        "nom" => "Ka",
        "telephone" => "221774515786",
        "siege" => "31"
    ],
    [
        "prenom" => "Awa",
        "nom" => "Ka",
        "telephone" => "221774478368",
        "siege" => "32"
    ],
    [
        "prenom" => "Cheikh Tidiane",
        "nom" => "Diaw",
        "telephone" => "221766117667",
        "siege" => "33"
    ],
    [
        "prenom" => "Mame tioro",
        "nom" => "CISSE",
        "telephone" => "221772215596",
        "siege" => "29"
    ],
    [
        "prenom" => "Nafissatou zahra",
        "nom" => "DIA",
        "telephone" => "221772215596",
        "siege" => "30"
    ],
    [
        "prenom" => "Babacar",
        "nom" => "Thiam",
        "telephone" => "221766766162",
        "siege" => "5"
    ],
    [
        "prenom" => "Pape Ibrahima",
        "nom" => "Seck",
        "telephone" => "221779580180",
        "siege" => "10"
    ],
    [
        "prenom" => "Mané",
        "nom" => "Diop",
        "telephone" => "221781726274",
        "siege" => "15"
    ],
    [
        "prenom" => "Babacar",
        "nom" => "Pouye",
        "telephone" => "221775764243",
        "siege" => "16"
    ],
    [
        "prenom" => "Adja",
        "nom" => "Kane",
        "telephone" => "221789528329",
        "siege" => "47"
    ],
    [
        "prenom" => "Khady",
        "nom" => "Faye",
        "telephone" => "221776499781",
        "siege" => "48"
    ],
    [
        "prenom" => "Aissatou",
        "nom" => "Mbodji",
        "telephone" => "221773755931",
        "siege" => "11"
    ],
    [
        "prenom" => "Mame Awa",
        "nom" => "Sarr",
        "telephone" => "221773755931",
        "siege" => "12"
    ],
    [
        "prenom" => "Baté",
        "nom" => "Ngom",
        "telephone" => "221779905906",
        "siege" => "21"
    ],
    [
        "prenom" => "Baye Dialor",
        "nom" => "Lo",
        "telephone" => "221781033045",
        "siege" => "34"
    ],
    [
        "prenom" => "Amady bocar",
        "nom" => "Lo",
        "telephone" => "221781033045",
        "siege" => "35"
    ],
    [
        "prenom" => "Seydou",
        "nom" => "Ba",
        "telephone" => "221777774343",
        "siege" => "37"
    ],
    [
        "prenom" => "Khady Didi",
        "nom" => "Wade",
        "telephone" => "221776419420",
        "siege" => "52"
    ],
    [
        "prenom" => "Mame Awa Sow",
        "nom" => "Diallo",
        "telephone" => "221776419420",
        "siege" => "53"
    ],
    [
        "prenom" => "Nafissatou",
        "nom" => "Fall",
        "telephone" => "221765354996",
        "siege" => "6"
    ],
    [
        "prenom" => "Mengue",
        "nom" => "Dieng",
        "telephone" => "221777637588",
        "siege" => "26"
    ],
    [
        "prenom" => "Awa",
        "nom" => "Ba",
        "telephone" => "221776731389",
        "siege" => "44"
    ],
    [
        "prenom" => "Alioune badara",
        "nom" => "Samba",
        "telephone" => "221772829398",
        "siege" => "9"
    ],
    [
        "prenom" => "Abdoulaye",
        "nom" => "Diop",
        "telephone" => "221772225375",
        "siege" => "4"
    ],
    [
        "prenom" => "Virginie",
        "nom" => "Gueye",
        "telephone" => "221771308062",
        "siege" => "58"
    ],
    [
        "prenom" => "Nafissatou marietou P.",
        "nom" => "Diop",
        "telephone" => "221771308062",
        "siege" => "57"
    ],
    [
        "prenom" => "Aissatou",
        "nom" => "Ndiaye",
        "telephone" => "221772251815",
        "siege" => "14"
    ],
    [
        "prenom" => "Maimouna Coumba",
        "nom" => "Camara",
        "telephone" => "221781002584",
        "siege" => "39"
    ],
    [
        "prenom" => "Seydina Mouhamed",
        "nom" => "Mbengue",
        "telephone" => "221770967676",
        "siege" => "49"
    ],
    [
        "prenom" => "Ahmed assane",
        "nom" => "Mbengue",
        "telephone" => "221784426014",
        "siege" => "54"
    ],
    [
        "prenom" => "Assane",
        "nom" => "Fall",
        "telephone" => "221774455082",
        "siege" => "40"
    ],
    [
        "prenom" => "Coura",
        "nom" => "Diouf",
        "telephone" => "221774455082",
        "siege" => "41"
    ],
    [
        "prenom" => "Koty",
        "nom" => "Fall",
        "telephone" => "221774455082",
        "siege" => "42"
    ],
    [
        "prenom" => "ARAME",
        "nom" => "DIOUF",
        "telephone" => "221777732296",
        "siege" => "24"
    ],
    [
        "prenom" => "Bandagne",
        "nom" => "Wade",
        "telephone" => "221779140436",
        "siege" => "8"
    ],
    [
        "prenom" => "Yaye caro",
        "nom" => "Diop",
        "telephone" => "221771228398",
        "siege" => "18"
    ],
    [
        "prenom" => "Mouhamed ahmadou lo",
        "nom" => "Gueye",
        "telephone" => "221768483389",
        "siege" => "50"
    ],
    [
        "prenom" => "Mouhamadou Alamine",
        "nom" => "LO",
        "telephone" => "221767722602",
        "siege" => "51"
    ],
    [
        "prenom" => "Seynabou Fall Lo",
        "nom" => "Gueye",
        "telephone" => "221773941038",
        "siege" => "46"
    ],
    [
        "prenom" => "Papa Amadou",
        "nom" => "Diop",
        "telephone" => "221776242981",
        "siege" => "27"
    ],
    [
        "prenom" => "Fatoumata",
        "nom" => "Sow",
        "telephone" => "221781512113",
        "siege" => "28"
    ],
    [
        "prenom" => "Binetou",
        "nom" => "Kebe",
        "telephone" => "221775418420",
        "siege" => "56"
    ],
    [
        "prenom" => "Adama",
        "nom" => "Dieye",
        "telephone" => "221775682473",
        "siege" => "36"
    ],
    [
        "prenom" => "Aminata",
        "nom" => "Niang",
        "telephone" => "783039839",
        "siege" => "7"
    ],
    [
        "prenom" => "Diariatou",
        "nom" => "Mbodj",
        "telephone" => "771482637",
        "siege" => "13"
    ],
    [
        "prenom" => "Samb",
        "nom" => "Lenna",
        "telephone" => "221786001369",
        "siege" => "17"
    ],
    [
        "prenom" => "Binta",
        "nom" => "Ka",
        "telephone" => "221777792018",
        "siege" => "23"
    ],
    [
        "prenom" => "Abdourakhmane",
        "nom" => "Fall",
        "telephone" => "221773087071",
        "siege" => "55"
    ],
    [
        "prenom" => "Mouhamadou cheikhou",
        "nom" => "Mbow",
        "telephone" => "221775638455",
        "siege" => "45"
    ],
    [
        "prenom" => "Aida Dieylani",
        "nom" => "Fall",
        "telephone" => "221785461924",
        "siege" => "43"
    ]
];
         */
        $bookings = [];
        DB::transaction(function () use ($yobumaPassengers, $bus) {
            $ticketPrice = 6000;
            $passengers = [];
            $phoneGroups = []; // Track phone numbers and their group IDs

            // First pass: Group passengers by phone number and assign group IDs
            $groupedPassengers = [];
            foreach ($yobumaPassengers as $passenger) {
                $phoneNumber = substr($passenger['telephone'], -9, 9);

                if (!isset($groupedPassengers[$phoneNumber])) {
                    $groupedPassengers[$phoneNumber] = [];
                }

                $groupedPassengers[$phoneNumber][] = [
                    'prenom' => $passenger['prenom'],
                    'nom' => $passenger['nom'],
                    'phone_number' => $passenger['telephone'],
                    'siege' => $passenger['siege'] ?? null,
                ];
            }

            // Generate group IDs for phone numbers with multiple passengers
            foreach ($groupedPassengers as $phoneNumber => $passengersGroup) {
                if (count($passengersGroup) > 1) {
                    // Generate a unique group ID (you can use UUID or auto-increment)
                    $phoneGroups[$phoneNumber] = Booking::latest()->first()->id.now()->format('Ymd');
                }
            }

            // Second pass: Process all passengers
            foreach ($yobumaPassengers as $passenger) {
                $passengerData = [
                    'prenom' => $passenger['prenom'],
                    'nom' => $passenger['nom'],
                    'phone_number' => $passenger['telephone'],
                    'siege' => $passenger['siege'] ?? null,
                ];

                // Check if customer exists using phone number otherwise create a new customer
                $normalizedPhone = substr($passengerData['phone_number'], -9, 9);
                $customer = Customer::where('phone_number', $normalizedPhone)->first();

                if ($customer === null) {
                    $customer = new Customer($passengerData);
                    $customer->phone_number = $normalizedPhone;
                    $customer->save();
                }

                $booking = new Booking();
                $booking->bus()->associate($bus);
                $booking->depart()->associate($bus->depart);

                // Set group_id if this phone number has multiple passengers
                if (isset($phoneGroups[$normalizedPhone])) {
                    $booking->group_id = $phoneGroups[$normalizedPhone];
                }

                $seatNumber = $passengerData['siege'];
                if ($seatNumber === null) {
                    throw new UnprocessableEntityHttpException("A booking without a seat is found for "
                        . $passengerData["phone_number"]);
                }

                $seat = $bus->seats->filter(function ($s) use ($seatNumber) {
                    return $s->number == $seatNumber;
                })->first();

                if ($seat === null) {
                    // throw new UnprocessableEntityHttpException("Impossible de trouver le siège {$seatNumber} pour le bus {$bus->name}.");
                }

                if ($seat?->hasBooking()) {
                    // We should free the seat as Yobuma passenger seat should be preserved
                    // We will later take all bookings that have no seat and assign a new seat to them
                    $seat->freeSeat();
                    $seat->save();
                    $existingBooking = Booking::where("seat_id", $seat->id)->first();
                    $existingBooking->freeSeat();
                    $existingBooking->save();
                }

                if ($seat !== null) {
                    $seat->book();
                    $seat->save();
                    $booking->seat()->associate($seat);
                }

                $booking->customer()->associate($customer);
                $booking->booked_with_platform = "yobuma";
                $booking->online = true;
                $booking->paye = true;
                $booking->comment = json_encode([
                    "passenger_full_name" => $passengerData['prenom'] . " " . $passengerData['nom'],
                ]);

                // TODO find a way later to determine the default point dep
                $booking->point_dep()->associate(PointDep::find(2));
                $booking->destination()->associate(Destination::find(36));
                $booking->save();

                $ticketManager = app(TicketManager::class);
                $ticket = $ticketManager->provideOne($ticketPrice);
                $ticket->soldBy = "Yobuma";
                $ticket->payment_method = "wave";
                $ticket->comment = null;
                $ticket->save();

                $booking->ticket()->associate($ticket);
                $booking->save();

                $bookings[] = $booking;
            }
        });

    }

}