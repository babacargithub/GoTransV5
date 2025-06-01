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

    public static function generateBookingGroupId(): string
    {
        return (Booking::latest()->first()?->id+1).now()->format("dHi");
    }


    public function sendNotificationOfTicketPaymentToCustomer(Booking $booking, bool $online = true): bool
    {

        $departName = $booking->depart->name;
        $seatNumber = $booking->depart->trajet_id == Trajet::UGB_DAKAR ? "\n Num siège :" . $booking->seat_number . " " :
            '';
        $schedule = $seatNumber . "\n Heure:  " . $booking->formatted_schedule . "\n Arret du bus " .
            $booking->point_dep->arret_bus;
        $contactAgent = is_request_for_gp_customers() || $booking->is_for_gp ? 777794818 : AppParams::first()
            ->getBusAgentDefaultNumber();
        $notificationMessageForOnlineUsers = "Vous avez acheté un ticket sur Global Transports  pour le départ $departName. RV: " . $schedule . ",
         \nBus: " . $booking->bus->name . ",".
            "\nContact du convoyeur qui sera dans le bus Bus: " . $contactAgent;
        $notificationMessage = "Votre  ticket est enregistré sur Global Transports pour $departName, paiement reçu. " . $booking->bus->name . ",".
            $seatNumber . "
            RV " . $schedule . ", 
            Contact convoyeur du bus: " . $contactAgent;
        $message = $online ? $notificationMessageForOnlineUsers : $notificationMessage;
        $this->SMSSender->sendSms($booking->customer->phone_number, $message);
        return true;

    }

    /**
     * @throws Exception
     */
    public function saveTicketPayementForOnlineUsers(Booking $booking, LoggerInterface $logger, $paymentMethod =
    null, array $data = []): void
    {

        try {
            $transactionSuccess = DB::transaction(function () use ($booking,$paymentMethod, $logger, $data) {
                $stackTrace = __FUNCTION__ . "-- " . __CLASS__ . ' -- ' . __FILE__;

                if ($booking->bus->isFull() || $booking->bus->isClosed()) {

                    $bus = $booking->depart->getBusForBooking(climatise: is_request_for_gp_customers());
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


                $this->assignTicketToBooking($booking, $paymentMethod, $data);

                $seatBus = $booking->bus->getOneAvailableSeat();
                if ($seatBus instanceof BusSeat) {
                    $seatBus->book();
                    $seatBus->save();
                    $booking->seat()->associate($seatBus);
                    $booking->save();
                }else{
                    $logger->error("No available seat for booking with id " . $booking->id . " in $stackTrace");
                    throw new UnprocessableEntityHttpException("No available seat for booking with id " . $booking->id . " in $stackTrace");
                }
                return true;
            });
            //<-- send notification to user -->
            if ($transactionSuccess) {
                $this->sendNotificationOfTicketPaymentToCustomer($booking, true);
                $bookingManager = app(BookingManager::class);
                $bookingManager->checkIfBusIsFullAndNotifyManagerIfYes($booking);
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

            $result = DB::transaction(function () use ($depart,$bus, $bookings, $payment_method, $logger, &$messages) {
                $number_of_seats_available = $depart->getBusForBooking() !== null ? $depart->getBusForBooking()->getAvailableSeats()->count() : 0;
                $busForBookings = $bus ?? null;
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
                    foreach ($bookings as /** @var Booking $booking */$booking) {
                        $booking->bus()->associate($busForBookings);
                        $booking->depart()->associate($busForBookings->depart);
                        $booking->save();
                        $booking->refresh();
                        $booking = $this->assignTicketToBooking($booking, $payment_method);
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
                // notifications sending
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
                $bookingManager = app(BookingManager::class);

                    $bookingManager->checkIfBusIsFullAndNotifyManagerIfYes($bookings[0]);

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

    /**
     * @param Booking $booking
     * @param mixed $paymentMethod
     * @return void
     * @throws Exception
     */
    function assignTicketToBooking(Booking $booking, string $paymentMethod, array $data =[]): Booking
    {
        $ticketPrice = $this->ticketManager->calculateTicketPriceForBooking($booking, $paymentMethod);
        $ticket = $this->ticketManager->provideOneForBooking($ticketPrice);
        $soldBy = User::requireMobileAppUser()->username;
        $ticket->price = $ticketPrice;
        $ticket->soldBy = $soldBy;
        $ticket->comment = $data['checkout_id'] ?? null;
        $ticket->payment_method = $paymentMethod;

        $ticket->save();
        $booking->ticket()->associate($ticket);
        $booking->save();
        return $booking;
    }

//    /** @noinspection PhpUnused */
//    public function checkIfBusShouldBeClosed(Booking $booking): void
//    {
//        $bookings_count = $booking->bus->bookings()->count();
//        if ($booking->bus->seats_count == $bookings_count || $booking->bus->isFull()){
//            $booking->bus->close();
//            $booking->bus->save();
//        }
//
//    }
    public function checkIfBusIsFullAndNotifyManagerIfYes(Booking $booking): void
    {
        $bookings_count = $booking->bus->bookings()->whereHas('ticket')->count();
        if (($booking->bus->seats_count-1) == $bookings_count){
            $smsSender = app(SMSSender::class);
            $smsSender->sendSms("773300853", "Le bus ".$booking->bus->name." depart ".$booking->depart->name." est arrivé à ". $bookings_count);
        }else{
            if ($bookings_count == $booking->bus->seats_count) {
                if (!$booking->bus->isClosed()) {
                    $booking->bus->close();
                    $booking->bus->save();
                }
            }
        }


    }
}