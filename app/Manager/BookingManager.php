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
use Illuminate\Support\Collection;
use Log;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use function Laravel\Prompts\error;

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
            $transactionSuccess = DB::transaction(function () use ($booking,$paymentMethod, $logger) {
                $stackTrace = __FUNCTION__ . "-- " . __CLASS__ . ' -- ' . __FILE__;

                if ($booking->bus->isFull() || $booking->bus->isClosed()) {

                    $bus = $booking->depart->getBusForBooking();
                    // we find another bus for another seat
                    if (!$bus->isFull() && !$bus->isClosed()) {

                        $booking->bus()->associate($bus);
                        $booking->depart()->associate($bus->depart);

                    }else{
                        $logger->error("Bus ".$booking->bus->full_name." is full or closed for booking with id " .
                            $booking->id . " in $stackTrace");
                        throw new UnprocessableEntityHttpException("Bus ".$booking->bus->full_name." is full or closed for booking with id " .
                            $booking->id . " in $stackTrace");

                    }

                }


                $ticket = $this->ticketManager->provideOneForBooking($booking->bus->ticket_price - ($booking->bus->ticket_price * 0.01));
                $soldBy = User::requireMobileAppUser()->username;
                $ticket->soldBy = $soldBy;
                $ticket->payment_method = $paymentMethod;
                $seatBus = $booking->bus->getOneAvailableSeat();
                $seatBus->book();
                $ticket->save();
                $booking->ticket()->associate($ticket);
                $booking->seat()->associate($seatBus);
                $seatBus->save();
                $booking->save();
                return true;
            });
            //<-- send notification to user -->
            if ($transactionSuccess) {
                $this->sendNotificationOfTicketPaymentToCustomer($booking, true);
            }
        } catch (Exception $e) {
            $logger->error($e->getMessage() . ' --' . $e->getTraceAsString());

        }

    }

    /**
     * @throws Exception
     */
    public function saveTicketPaymentMultipleBooking(Depart $depart, ?Bus $bus, Collection $bookings, LoggerInterface
                                                            $logger, string
                                                            $payment_method): JsonResponse
    {

        try {
            $messages = [];

            $result = DB::transaction(function () use ($depart, $bookings, $payment_method, $logger, &$messages) {
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
                    $firsBooking = $bookings->first();
                    $message = "Le client ".$firsBooking?->customer->full_name
                        ." ".$firsBooking?->customer->phone_number." vient de faire un paiement pour une réservation groupée sur le départ sans assez de places disponibles " . $depart->name."\n Pas assez de places disponibles pour le départ " .
                        $depart->name;
                    $this->SMSSender->sendSms(773300853, $message);
                    $this->SMSSender->sendSms(771273535, $message);
                    throw new Exception('No bus available for booking for multiple bookings');
                }
                if ($busForBookings instanceof Bus) {
                    // we take seats the number of bookings
                    $seats = $busForBookings->getAvailableSeats()->slice(0, $bookings->count());
                    if (count($seats) < $bookings->count()) {
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
                        $ticket->soldAt = now();
                        $ticket->save();
                        $booking->ticket()->associate($ticket);
                        $booking->save();
                        // take one available seat and assign it to booking, seats array is not 0 indexed
                        $seat = $seats->shift();
                        if (!$seat){
                            $logger->error('No available seat for booking');
                            throw new UnprocessableEntityHttpException('No available seat for booking');
                        }
                        if ($seat instanceof BusSeat) {
                            $seat->book();
                            $seat->save();
                            $booking->seat()->associate($seat);
                            $booking->save();
                        }

                    }

                } else {

                    throw new Exception( 'Pas assez de places disponibles pour le départ ' . $depart->name);
                }
                foreach ($bookings as $entity) {
                    if ($entity instanceof Booking) {
                        $messages[] = [
                            'message' => "Quelqu'un a acheté un ticket pour vous sur Globe Transport pour le  départ " .
                                $entity->depart->name .
                                " RV " . $entity->formatted_schedule . " A " . $entity->point_dep->arret_bus .". " .
                                "\n Contactez l'agent du bus ".AppParams::first()->getBusAgentDefaultNumber(),
                            'phone_number' => $entity->customer->phone_number
                        ];
                    }
                }

                return  true;
            });
            if ($result && count($messages) > 0) {
                $this->SMSSender->sendMultipleSms($messages);
            }
            return response()->json(['message' => 'Finished: Booking saved successfully']);

        } catch (Exception $e) {
            Log:error($e->getMessage());
            // rollback the transaction if something went wrong
            $stackTrace = __FUNCTION__ . "-- " . __CLASS__ . ' -- ' . __FILE__;
            Log::error('Saving multiple transactions failed ' . $stackTrace);
            Log::error($e->getMessage() . ' --' . $e->getTraceAsString());

        }
        // send notifications to users after saving the bookings



        return response()->json(['message' => ' Booking saved successfully']);
    }
}