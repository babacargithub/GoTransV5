<?php


namespace App\Manager;


use App\Models\Booking;
use App\Models\Bus;
use App\Models\BusSeat;
use App\Models\Depart;
use App\Models\Seat;
use App\Models\User;
use App\Services\NotificationService;
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
    private NotificationService $notificationService;

    public function __construct(TicketManager $ticketManager, NotificationService $notificationService)
    {
        $this->ticketManager = $ticketManager;
        $this->notificationService = $notificationService;
    }

    /**
     * Generates an id shared by every Booking row created together in one multi-passenger/round-trip
     * request (see BookingService::buildBookingsForLeg and MobileMultipleBookingRequest::normalizeBookings).
     *
     * Deliberately NOT derived from "the next booking id" or the current time: predicting the next
     * autoincrement id via Booking::latest() is a read-then-write race — two unrelated requests can read
     * the same "latest id" before either has inserted, and (combined with the old minute-only timestamp
     * suffix) end up with the exact same group_id, silently merging two strangers' bookings into one
     * "group" on their tickets. Drawing from a large random space and checking for a clash instead removes
     * the dependency on other rows' state entirely.
     */
    public static function generateBookingGroupId(): string
    {
        do {
            $candidate = (string) random_int(100_000_000_000, 999_999_999_999);
        } while (Booking::where('group_id', $candidate)->exists());

        return $candidate;
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
                $this->notificationService->notifyCustomerOfTicketPayment($booking, true);
                $bookingManager = app(BookingManager::class);
                $bookingManager->checkIfBusIsFullAndNotifyManagerIfYes($booking);
            }
        } catch (Exception $e) {
            $logger->error($e->getMessage() . ' --' . $e->getTraceAsString());

        }

    }

    /**
     * @param Depart $depart Kept for caller compatibility (historically "the" depart of the group);
     *                       no longer relied on internally — a group can span two different departs
     *                       (a round trip's outbound and return legs), so bus/seat assignment is done
     *                       per booking's own depart instead. See assignBusAndSeatsForUnseatedBookings().
     * @throws Exception
     */
    public function saveTicketPaymentMultipleBooking(Depart $depart, ?Bus $bus, Collection $bookings, LoggerInterface
                                                            $logger, string
                                                            $payment_method, array $data): JsonResponse
    {
        try {
            $notifiedBookings = collect();

            $result = DB::transaction(function () use ($bookings, $payment_method, $logger, $data, &$notifiedBookings) {
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

                // Bookings without seats need a bus + seat assignment. A round-trip group can span two
                // different departs (outbound/return), each with their own buses, so this is done once
                // per depart actually present in the group rather than against a single shared depart.
                foreach ($bookingsWithoutSeats->groupBy('depart_id') as $bookingsForDepart) {
                    $this->assignBusAndSeatsForUnseatedBookings($bookingsForDepart, $payment_method, $data, $logger);
                }

                foreach ($unpaidBookings as $entity) {
                    $entity->refresh();
                }
                $notifiedBookings = $unpaidBookings;

                return true;
            });

            if ($result && $notifiedBookings->isNotEmpty()) {
                $this->notificationService->notifyCustomerOfGroupTicketPayment($notifiedBookings);

                // A round-trip group involves two departs (outbound + return); name both in the alert.
                $departNames = $bookings->pluck('depart.name')->filter()->unique()->implode(', ');
                $this->notificationService->notifyGpTeamOfBusEvent(
                    "Un client GP vient d'acheter un ticket sur " . $departNames
                    . "! Sa réservation doit être enregistré sur le terminal Yobuma"
                );

                $bookingManager = app(BookingManager::class);
                // Check every distinct bus involved (outbound and return legs can use different buses).
                foreach ($bookings->unique('bus_id') as $bookingOnBus) {
                    $bookingManager->checkIfBusIsFullAndNotifyManagerIfYes($bookingOnBus);
                }
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
     * Assigns a bus (with enough available seats) and seats to a set of unseated bookings that all
     * belong to the same depart, then tickets them. Extracted so saveTicketPaymentMultipleBooking can
     * run this once per depart present in a group — a round trip's outbound and return legs have
     * different departs, each with their own pool of buses/seats.
     *
     * @throws Exception
     */
    private function assignBusAndSeatsForUnseatedBookings(Collection $bookingsForDepart, string $paymentMethod, array $data, LoggerInterface $logger): void
    {
        /** @var Booking $firstBooking */
        $firstBooking = $bookingsForDepart->first();
        $depart = $firstBooking->depart;

        $busForBookings = null;
        foreach ($depart->buses as $candidateBus) {
            if (!$candidateBus->isClosed()
                && $candidateBus->getAvailableSeats()->count() >= $bookingsForDepart->count()) {
                $busForBookings = $candidateBus;
                break;
            }
        }

        if ($busForBookings === null) {
            $alertMessage = "Le client " . $firstBooking->customer->full_name
                . " " . $firstBooking->customer->phone_number
                . " vient de faire un paiement pour une réservation groupée sans assez de places disponibles sur le départ "
                . $depart->name;
            $this->notificationService->notifyManagerOfBusEvent($alertMessage);
            $this->notificationService->notifyGpTeamOfBusEvent($alertMessage);
            throw new Exception('No bus available for unseated bookings in group payment');
        }

        $availableSeats = $busForBookings->getAvailableSeats()->take($bookingsForDepart->count());
        if ($availableSeats->count() < $bookingsForDepart->count()) {
            $logger->error('Not enough available seats for the number of bookings.');
            throw new UnprocessableEntityHttpException('Not enough available seats for the number of bookings.');
        }

        foreach ($bookingsForDepart as $booking) {
            $booking->bus()->associate($busForBookings);
            $booking->depart()->associate($busForBookings->depart);
            $booking->save();
            $booking->refresh();
            $this->assignTicketToBooking($booking, $paymentMethod, $data);

            $seat = $availableSeats->shift();
            $seat->book();
            $seat->save();
            $booking->seat()->associate($seat);
            $booking->save();
        }
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
            $this->notificationService->notifyManagerOfBusEvent(
                "Le bus ".$booking->bus->name." depart ".$booking->depart->name." est arrivé à ". $bookings_count
            );
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