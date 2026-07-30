<?php


namespace App\Manager;



use App\Http\Controllers\OrangeMoneyController;
use App\Http\Controllers\WavePaiementController;
use App\Http\Resources\PaymentResponseResource;
use App\Models\Booking;
use App\Models\Bus;
use App\Models\PointDep;
use App\Models\PointDepBus;
use App\Models\Ticket;
use App\Models\Vehicule;
use App\Utils\NotificationSender\SMSSender\SMSSender;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\InvalidArgumentException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Psr\Log\LoggerInterface;

class  TicketManager
{
    // TODO: make this dynamic
    const DISCOUNT_AMOUNT = 10;
    const WAVE_FEES = 0.01;
    const OM_FEES = 0.01;
    private SMSSender $smsSender;

    public function __construct(SMSSender $smsSender)
    {
        $this->smsSender = $smsSender;
    }

    /**
     * @param Booking $booking
     * @param null $paymentMethod
     * @param bool $isForCheckout
     * @return int
     */

    public function calculateTicketPriceForBooking(Booking $booking, $paymentMethod = null, bool $isForCheckout =
    true) :
    int
    {
        return $this->calculateTicketPrice(
            $booking->bus,
            $booking->point_dep,
            \is_request_for_gp_customers() || $booking->is_for_gp,
            $paymentMethod,
            $isForCheckout,
        );
    }

    /**
     * Calculates the ticket price (base price + applicable fees) for a bus, optionally boarding at a
     * specific point_dep. This is the single source of pricing logic, shared by calculateTicketPriceForBooking
     * (a real booking) and anywhere else that needs to show a price ahead of booking (e.g. a departs search),
     * where there is no Booking yet.
     */
    public function calculateTicketPrice(Bus $bus, ?PointDep $pointDep = null, bool $forGp = false, $paymentMethod = null, bool $isForCheckout = true): int
    {
        $price = $this->resolveBaseTicketPrice($bus, $pointDep, $forGp);

        if ($forGp) {
            return $price + ($isForCheckout ? $price * self::WAVE_FEES : 0);
        }
        if ($paymentMethod == "wave") {
            $price += ($price * self::WAVE_FEES);
        } else if ($paymentMethod == "om") {
            $price += ($price * self::OM_FEES);
        }

        return $price;
    }

    /**
     * Resolves the base ticket price (before fees) for a bus, optionally boarding at a specific point_dep,
     * checking increasingly specific overrides first: the price at the exact bus stop (bus + point_dep pair),
     * then the point_dep's own city price, falling back to the bus's flat price when neither is set.
     */
    private function resolveBaseTicketPrice(Bus $bus, ?PointDep $pointDep, bool $forGp): int
    {
        if ($pointDep !== null) {
            $busStopPrice = PointDepBus::where('bus_id', $bus->id)
                ->where('point_dep_id', $pointDep->id)
                ->value('ticket_price');
            if ($busStopPrice !== null) {
                return $busStopPrice;
            }

            if ($pointDep->ticket_price !== null) {
                return $pointDep->ticket_price;
            }
        }

        return $forGp ? ($bus->gp_ticket_price ?? $bus->ticket_price) : $bus->ticket_price;
    }

    /**
     * @param Booking[] $bookings
     * @param $payment_method string
     * @param string $platform
     * @return array
     */
    public function calculatePriceForMultipleBookings(array $bookings, string $payment_method, string $platform): array
    {
        $totalPrice = 0;
        $totalDiscount = 0;
        $normalPrice = 0;

        foreach ($bookings as $booking) {
            $ticketPrice = $this->calculateTicketPriceForBooking($booking, $payment_method);
            $normalPrice += $ticketPrice;

            // Debugging: Print current booking and initial ticket price

            // Calculate discount based on value "booked_with_platform"
            // If booked with mobile app, discount is 10%
            // If booked with web platform, discount is 0%
            if ($platform == "iphone" || $platform == "android") {
                if ($ticketPrice >= 3550) {
                    $ticketPrice -= self::DISCOUNT_AMOUNT;
                    $totalDiscount += self::DISCOUNT_AMOUNT;
                }
            }

            $totalPrice += $ticketPrice;
        }

        // Debugging: Print final total price and total discount


        return ["totalPrice" => $totalPrice, "discount" => $totalDiscount, "normalPrice" => $normalPrice];
    }

    /**
     * @throws Exception
     */
    public function provideOne(?int $price = null) : Ticket
    {
        $ticket =
            new Ticket();
            $ticket->number = Ticket::orderByDesc("id")->first()->id.now()->timestamp;
            $ticket->expiryDate = now()->addDays(30);
            $ticket->soldAt = now();
            $ticket->used = true;
            $ticket->price = ($price != null? $price : 3550);
            $ticket->warning = 'Ticket non remboursable';

            return $ticket;

    }

    /**
     * @throws Exception
     */
    public function provideOneForBooking($price): Ticket
    {

        return $this->provideOne($price);
    }

    /**
     * @throws ConnectionException
     */
    public function triggerWavePayment(Booking $booking): PaymentResponseResource
    {

        $wavePaymentController = app(WavePaiementController::class);
        $wavePaiementResponse =  $wavePaymentController->getWavePaymentUrlForBooking($booking, app(TicketManager::class));
        $paiementUrl = $wavePaiementResponse->waveLaunchUrl();
        $message = "Bnjr. Payez votre réservation Globe Transport sur le départ ". $booking->depart->name."  sur ce lien : $paiementUrl";
        $this->smsSender->sendSms(substr($booking->customer->phone_number,-9,9),$message);

        return  $wavePaiementResponse;
    }

    /**
     * @throws GuzzleException
     */
    public function triggerOmPayment(Booking $booking): PaymentResponseResource
    {

        try {

            if ($booking->depart->closed) {
                throw new InvalidArgumentException("Paiement OM: Aucune place disponible pour le départ : " .
                    $booking->depart->name);
            }
            $amount = $this->calculateTicketPriceForBooking($booking, 'om');
            $customerNumber = $booking->customer->phone_number;
            $bookingId = $booking->id;
            $requestData =
                [
                    'amount' => $amount,

                    'customer' => $customerNumber,
                    'metadata' => [
                        'booking_id' => $bookingId
                    ],
                ];
            $omController = app(OrangeMoneyController::class);
            return $omController->initOMPayment($requestData);
        } catch (GuzzleException $e) {
            $logger = app(LoggerInterface::class);
            $logger->error($e->getMessage());
            throw $e;


        }catch (ConnectionException|RequestException $e) {
            $logger = app(LoggerInterface::class);
            $logger->error($e->getMessage());
            return new PaymentResponseResource($e->response, paymentMethod: "om");
        }

    }

}