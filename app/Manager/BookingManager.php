<?php


namespace App\Manager;


use App\Models\AppParams;
use App\Models\Booking;
use App\Models\Bus;
use App\Models\BusSeat;
use App\Models\Depart;
use App\Models\Seat;
use App\Models\Trajet;
use App\Models\User;
use App\Utils\NotificationSender\SMSSender\SMSSender;
use DB;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class BookingManager
{

    /** @var TicketManager */
    private TicketManager $ticketManager;
    /** @var SMSSender */
    private SMSSender $SMSSender;

    /**
     * BookingManager constructor.
     * @param TicketManager $ticketManager
     * @param SMSSender $SMSSender
     */
    public function __construct(TicketManager $ticketManager,  SMSSender $SMSSender)
    {
        $this->ticketManager = $ticketManager;
        $this->SMSSender = $SMSSender;
    }


    public function sendNotificationOfTicketPaymentToCustomer(Booking $booking, bool $online = false): bool
    {

        $departName = $booking->depart->name;
        $seatNumber = $booking->depart->trajet_id == Trajet::UGB_DAKAR ? "\n Num place :" . $booking->seat_number . " " :
        '';
        $schedule = $seatNumber . "\n Heure:  " . $booking->formatted_schedule . "\n Arret du bus " .
            $booking->point_dep->arret_bus;
        $contactAgent = AppParams::first()->getBusAgentDefaultNumber();
        $notificationMessageForOnlineUsers = "Vous avez acheté un ticket sur GolobOne Transport  pour le départ $departName" . $schedule . ", 
            \nContact de l'agent qui sera dans le bus Bus: " . $contactAgent;
        $notificationMessage = "Votre  ticket est enregistré sur GolobOne Transport pour $departName, paiement reçu. " . $seatNumber . "
            \ RV " . $schedule . ", 
            Contactez agent dans le bus: " . $contactAgent;
        $message = $online ? $notificationMessageForOnlineUsers : $notificationMessage;
        $this->SMSSender->sendSms($booking->customer->phone_number, $message);
        return true;

    }

    /**
     * @throws Exception
     */
    public function saveTicketPayementForOnlineUsers(Booking $booking, LoggerInterface $logger, $paymentMethod = null): void
    {

        try {
            $stackTrace = __FUNCTION__ . "-- " . __CLASS__ . ' -- ' . __FILE__;
                $seatBus = $booking->bus->getOneAvailableSeat();
                $seatBus->book();
                $booking->seat()->associate($seatBus);

                if ($booking->bus->isFull() || $booking->bus->isClosed()) {
                    // we find another bus for another seat
                    $bus = $booking->depart->getBusForBooking();
                        if (!$bus->isFull() || !$bus->isClosed()) {
                            $seat = $bus->getOneAvailableSeat();
                            $seat->book();
                            $booking->seat()->associate($seat);

                        }

                } else {

                    $logger->error("got null value when trying to fetch a record of " . Seat::class . " for booking with id " . $booking->id . " in $stackTrace");
                }


            $ticket = $this->ticketManager->provideOneForBooking($booking->bus->ticket_price - ($booking->bus->ticket_price * 0.01));
            $soldBy = User::requireMobileAppUser()->username;
            $ticket->soldBy = $soldBy;
                $ticket->payment_method = $paymentMethod;
            $booking->ticket()->associate($ticket);
            DB::transaction(function () use ($booking, $seatBus, $ticket) {
                $seatBus->save();
                $ticket->save();
                $booking->save();
            });
            //<-- send notification to user -->
            $this->sendNotificationOfTicketPaymentToCustomer($booking, true);
        } catch (Exception $e) {
            $logger->error($e->getMessage() . ' --' . $e->getTraceAsString());

        }

    }

    /**
     * @throws Exception
     */
    public function saveTicketPaymentMultipleBooking(Depart $depart, ?Bus $bus, array $bookings, LoggerInterface
$logger, string
    $payment_method): JsonResponse
    {
        $entities = [];
        $number_of_seats_available = $depart->getBusForBooking() !== null ? $depart->getBusForBooking()->getAvailableSeats()->count() : 0;
        $busForBookings = null;
        if ($number_of_seats_available >= count($bookings)){
            $busForBookings = $depart->getBusForBooking();
        } else{
            // We try to find another bus  with enough seats for the bookings
            foreach ($depart->buses as $bus) {
                if ($bus->getAvailableSeats()->count() >= count($bookings) && !$bus->isFull() && !$bus->isClosed()) {
                    $busForBookings = $bus;
                    break;
                }
            }
        }
        if ($busForBookings === null) {
            // not enough seats available
            // We notify user and a refund is made
            /** @var Booking $firsBooking */
            $firsBooking = $bookings[0];
            $message = "Le client ".$firsBooking?->customer->full_name
                ." ".$firsBooking?->customer->phone_number." vient de faire un paiement pour une réservation groupée sur le départ sans assez de places disponibles " . $depart->name."\n Pas assez de places disponibles pour le départ " .
                $depart->name;
            $this->SMSSender->sendSms(773300853, $message);
            $this->SMSSender->sendSms(771273535, $message);
            throw new Exception('No bus available for booking');
        }
        if ($busForBookings instanceof Bus) {
            // we take seats the number of bookings
            $seats = $busForBookings->getAvailableSeats()->slice(0, count($bookings));
            if (count($seats) < count($bookings)) {
                // Handle the case where there are not enough seats
                $logger->error('Not enough available seats for the number of bookings.');
                throw new UnprocessableEntityHttpException('Not enough available seats for the number of bookings.');
            }
            $soldBy = User::requireMobileAppUser()->username;
            foreach ($bookings as $booking) {
                $ticket = $this->ticketManager->provideOneForBooking
                ($this->ticketManager->calculateTicketPriceForBooking($booking, $payment_method));
                $ticket->soldBy = $soldBy;
                    $ticket->payment_method = $payment_method;
                $booking->setTicket($ticket);
               // take one available seat and assign it to booking, seats array is not 0 indexed
                foreach ($seats as /** @var BusSeat $seat */&$seat) {
                    if ($seat instanceof BusSeat) {
                        if (!$seat->isBooked() && $seat->isAvailable()) {
                            $booking->seat()->associate($seat);
                            $seat->book();
                            $entities[] = $seat;
                            break;
                        }
                    }
                }
                $entities[] = $booking;


            }

        } else {

            return response()->json(['message' => 'Pas assez de places disponibles pour le départ ' . $depart->name
            ()]);
        }

        // save entities in a doctrine transaction with commit and rollback
        // start the transaction
        DB::transaction(function () use ($entities, $logger) {
            try {
                foreach ($entities as $entity) {
                    if ($entity instanceof Model) {
                        $entity->save();
                    }
                }
            } catch (Exception $e) {
                // rollback the transaction if something went wrong
                $stackTrace = __FUNCTION__ . "-- " . __CLASS__ . ' -- ' . __FILE__;
                $logger->error('Saving multiple transactions failed ' . $stackTrace);
                $logger->error($e->getMessage() . ' --' . $e->getTraceAsString());
            }


        });
        // send notifications to users after saving the bookings
        $messages = [];
        foreach ($entities as $entity) {
            if ($entity instanceof Booking) {
                $messages[] = [
                    'message' => "On a acheté un ticket pour vous sur Globe Transport pour le  départ " .
                        $entity->depart->name .
                        " RV " . $entity->formatted_schedule . " \n Contactez l'agent du bus ".AppParams::first()->getBusAgentDefaultNumber(),
                    'phone_number' => $entity->customer->phone_number
                ];
            }
        }
        $this->SMSSender->sendMultipleSms($messages);


        return response()->json(['message' => 'Booking saved successfully']);
    }
}