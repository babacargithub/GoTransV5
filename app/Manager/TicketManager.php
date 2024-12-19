<?php


namespace App\Manager;



use App\Http\Controllers\OrangeMoneyController;
use App\Http\Controllers\WavePaiementController;
use App\Http\Resources\PaymentResponseResource;
use App\Models\Booking;
use App\Models\Ticket;
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
    const DISCOUNT_AMOUNT = 450;
    private SMSSender $smsSender;

    public function __construct(SMSSender $smsSender)
    {
        $this->smsSender = $smsSender;
    }

    /**
     * @param Booking $booking
     * @param $paymentMethod
     * @return int
     */

    public function calculateTicketPriceForBooking(Booking $booking, $paymentMethod = null) : int
    {
        $price =   $booking->bus->ticket_price;
        if ($paymentMethod == "wave"){
            $price += ($price * 0.00);
        }
        else if ($paymentMethod == "om"){
            $price += ($price * 0.00);
        }

        return $price;

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

        return $this->provideOne($price- ($price * 0.01));
    }

    /**
     * @throws ConnectionException
     */
    public function triggerWavePayment(Booking $booking): JsonResponse
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