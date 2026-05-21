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
            "\nContact du convoyeur qui sera dans le bus: " . $contactAgent;
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
                                                            $payment_method, array $data): JsonResponse
    {
        try {
            $messages = [];

            $result = DB::transaction(function () use ($depart, $bookings, $payment_method, $logger, $data, &$messages) {
                // Idempotency: ignore bookings that already have a ticket from a previous callback
                $unpaidBookings = $bookings->filter(fn(Booking $booking) => !$booking->hasTicket());

                if ($unpaidBookings->isEmpty()) {
                    return true;
                }

                // Bookings that already have a seat pre-assigned only need a ticket
                $bookingsWithSeats    = $unpaidBookings->filter(fn(Booking $booking) => $booking->has_seat);
                $bookingsWithoutSeats = $unpaidBookings->reject(fn(Booking $booking) => $booking->has_seat);

                foreach ($bookingsWithSeats as $booking) {
                    $this->assignTicketToBooking($booking, $payment_method, $data);
                }

                // Bookings without seats need a bus + seat assignment
                if ($bookingsWithoutSeats->isNotEmpty()) {
                    $busForBookings = null;
                    foreach ($depart->buses as $candidateBus) {
                        if (!$candidateBus->isClosed()
                            && $candidateBus->getAvailableSeats()->count() >= $bookingsWithoutSeats->count()) {
                            $busForBookings = $candidateBus;
                            break;
                        }
                    }

                    if ($busForBookings === null) {
                        /** @var Booking $firstBooking */
                        $firstBooking = $bookingsWithoutSeats->first();
                        $alertMessage = "Le client " . $firstBooking?->customer->full_name
                            . " " . $firstBooking?->customer->phone_number
                            . " vient de faire un paiement pour une réservation groupée sans assez de places disponibles sur le départ "
                            . $depart->name;
                        $this->SMSSender->sendSms(773300853, $alertMessage);
                        $this->SMSSender->sendSms(771273535, $alertMessage);
                        throw new Exception('No bus available for unseated bookings in group payment');
                    }

                    $availableSeats = $busForBookings->getAvailableSeats()->take($bookingsWithoutSeats->count());
                    if ($availableSeats->count() < $bookingsWithoutSeats->count()) {
                        $logger->error('Not enough available seats for the number of bookings.');
                        throw new UnprocessableEntityHttpException('Not enough available seats for the number of bookings.');
                    }

                    foreach ($bookingsWithoutSeats as $booking) {
                        $booking->bus()->associate($busForBookings);
                        $booking->depart()->associate($busForBookings->depart);
                        $booking->save();
                        $booking->refresh();
                        $this->assignTicketToBooking($booking, $payment_method, $data);

                        $seat = $availableSeats->shift();
                        $seat->book();
                        $seat->save();
                        $booking->seat()->associate($seat);
                        $booking->save();
                    }
                }

                foreach ($unpaidBookings as $entity) {
                    $entity->refresh();
                    $messages[] = [
                        'message' => "Achat de ticket réussi sur Global Transports. Date voyage "
                            . $entity->depart->name
                            . " RV " . $entity->formatted_schedule
                            . " A " . $entity->point_dep->arret_bus . ". "
                            . "\n Contacts du convoyeur du bus  " . AppParams::first()->getBusAgentDefaultNumber(),
                        'phone_number' => $entity->customer->phone_number
                    ];
                }

                return true;
            });

            if ($result && count($messages) > 0) {
                $messages[] = [
                    'message' => "Un client GP vient d'acheter un ticket sur " . $depart->name
                        . "! Sa réservation doit être enregistré sur le terminal Yobuma",
                    'phone_number' => 771273535
                ];
                $this->SMSSender->sendMultipleSms($messages);
                $bookingManager = app(BookingManager::class);
                $bookingManager->checkIfBusIsFullAndNotifyManagerIfYes($bookings[0]);
            }

            return response()->json(['message' => 'Finished: Booking saved successfully']);

        } catch (Exception $e) {
            $stackTrace = __FUNCTION__ . "-- " . __CLASS__ . ' -- ' . __FILE__;
            Log::error('Saving multiple transactions failed ' . $stackTrace);
            Log::error($e->getMessage() . ' --' . $e->getTraceAsString());
        }

        return response()->json(['message' => 'Booking saved successfully']);
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

    public function checkIfBusIsFullAndNotifyManagerIfYes(Booking $booking): void
    {
        $bookings_count = $booking->bus->bookings()
            ->whereHas('ticket')
            ->count();
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