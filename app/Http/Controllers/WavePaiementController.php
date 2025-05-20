<?php /** @noinspection PhpUnused */


/**
 * Copyright (c) 2020.  All rights reserved for Globe One Software
 */

namespace App\Http\Controllers;
use App\Http\Resources\PaymentResponseResource;
use App\Manager\BookingManager;
use App\Manager\TicketManager;
use App\Models\Booking;
use App\Models\Depart;
use Exception;
use Http;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Response;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response as ResponseAlias;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class WavePaiementController extends Controller
{
    public string $waveUrl = 'https://api.wave.com/v1/checkout/sessions';

    /**
     * @var BookingManager
     */
    private BookingManager $bookingManager;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param BookingManager $bookingManager
     * @param LoggerInterface $logger
     */
    public function __construct(BookingManager $bookingManager,  LoggerInterface $logger)
    {
        $this->bookingManager = $bookingManager;
        $this->logger = $logger;
    }

    public function wavePaymentSuccessCallBack(): Response
    {
        $this->logger->alert("Wave payment endpoint called");

        try {
            list($body, $valid) = $this->verifyWaveSignature();
            if ($valid) {
                # This is a request from Wave.
                # You can proceed decoding the request body.
                $body = json_decode($body);
                $webhook_event = $body->type;
                if ($webhook_event == "checkout.session.completed") {
                    $webhook_data = $body->data;

                    $metaData = json_decode($webhook_data->client_reference, true);
                    $id = null;

                    try {
                        // determine if it is for booking or depart
                        // check if booking
                        if ($metaData['type'] == "booking" || $metaData['type'] == "depart") {
                            $id = $metaData["id"];
                        }
                        if ($metaData['type'] == "booking") {
                            $booking = Booking::find($id);

                            if ($booking == null) {
                                throw new NotFoundHttpException('Wave paiement failed : Booking with id ' . $id . ' not found for wave');
                            }
                            $this->bookingManager->saveTicketPayementForOnlineUsers($booking, $this->logger, "wave");
                            $this->logger->alert("Wave payment saved for booking !" . $id . ", for customer " .
                                $booking->customer->full_name . ", for depart " . $booking->depart->name);
                        }
                        else if ($metaData['type'] == "multiple_booking") {
                            $group_id = $metaData["group_id"];
                            $depart_id = $metaData["depart_id"];

                            $bookings = Booking::whereGroupId($group_id)->get();

                            if ($bookings->count() == 0) {
                                throw new NotFoundHttpException('Wave paiement failed : Booking group with group id '
                                    . $group_id . ' not found during Wave payment');
                            }
                            $departForMultipleBooking = Depart::find($depart_id);
                            if ($departForMultipleBooking == null) {
                                throw new NotFoundHttpException('Wave paiement failed : Depart with id ' . $depart_id . ' not found for wave');
                            }
                            $this->bookingManager->saveTicketPaymentMultipleBooking($departForMultipleBooking,
                                $departForMultipleBooking->getBusForBooking(),
                                $bookings, $this->logger, "wave");
                            $this->logger->alert("Wave payment saved for multiple  booking !" . $id . ", for group id of bookings  "
                                .$group_id);

                        }//check if transaction is of depart type

                    } catch (Exception $e) {
                        $this->logger->error("Unable to save payment made by phone number: " . $webhook_data->sender_mobile);
                        $this->logger->error($e->getMessage() . '---' . $e->getFile() . '---' . $e->getTraceAsString());

                    }

                } else {
                    $this->logger->alert("event sent by wave" . $webhook_event);
                }

            } else {
                $this->logger->error("Wave signature is not  valid !");
                die("Unable to verify webhook signature.");
            }
        } catch (Exception $e) {
            try {
                $this->logger->error($e);
            } catch (Exception $e) {
                return (new Response($e->getMessage()))->setStatusCode(ResponseAlias::HTTP_OK);
            } finally {
                return (new Response())->setStatusCode(ResponseAlias::HTTP_OK);

            }

        }
        return (new Response())->setStatusCode(ResponseAlias::HTTP_OK);
    }

    /**
     * @throws ConnectionException
     */
    public function getWavePaymentUrlForBooking(Booking $booking, TicketManager $ticketManager): PaymentResponseResource
    {
        try {
            $requestBody = [
                "amount" => '' . $ticketManager->calculateTicketPriceForBooking($booking, "wave"),
                "currency" => "XOF",
                "client_reference" => [
                    "type" => "booking",
                    "id" => $booking->id,
                    "phone_number" => $booking->customer->phone_number,
                    "client" => $booking->customer->full_name
                ],
                "error_url" => self::getEndpointForRedirect()."/#/paiement/error/" . $booking->id,
                "success_url" => self::getEndpointForRedirect()."/#/paiement/success/" .
            $booking->id,

            ];
            return $this->getPaymentUrl($requestBody);

        } catch (RequestException $e) {
            $this->logger->error($e->getMessage());

            return new PaymentResponseResource($e->response, paymentMethod: "wave");
        }
    }

    /**
     * @throws InvalidArgumentException
     * @throws ConnectionException
     */
    public function getPaymentUrl(array $data) : PaymentResponseResource
    {
        if (!isset($data["amount"]) || !isset($data["client_reference"]) || !is_array($data["client_reference"]) ||
            !isset($data["error_url"])|| !isset
            ($data["success_url"])){
            throw new InvalidArgumentException("Invalid data for wave paiement");
        }
        $headers = $this->getWaveHeaders();
        $requestBody = [
            "amount" => '' .$data['amount'],
            "currency" => "XOF",
            "client_reference" => json_encode($data['client_reference']),
            "error_url" => $data['error_url'],
            "success_url" => $data['success_url'],
        ];
        $response = Http::withHeaders($headers)
            ->asJson()
            ->post($this->waveUrl, $requestBody);
        try {
            $response->throw();
            return new PaymentResponseResource($response, paymentMethod: "wave");
        } catch (RequestException $e) {
            $this->logger->error($e->getMessage());
            return new PaymentResponseResource($e->response, paymentMethod: "wave");
        }

        //


    }
    protected function getWaveHeaders(): array
    {
        return   [
            'Authorization'=>'Bearer '.config('app.wave_key'),
            'Content-Type'=>'application/json',
            'Accept'=>'application/json',
        ];
    }



    /**
     * @return array
     */
    public function verifyWaveSignature(): array
    {
        $body = file_get_contents('php://input');
        $wave_webhook_secret = config('app.wave_webhook_secret');
//# This header is sent with the HMAC for verification.
        $wave_signature = $_SERVER['HTTP_WAVE_SIGNATURE'];
        $parts = explode(",", $wave_signature);
        $timestamp = explode("=", $parts[0])[1];
        $signatures = array();
        foreach (array_slice($parts, 1) as $signature) {
            $signatures[] = explode("=", $signature)[1];
        }
        $computed_hmac = hash_hmac("sha256", $timestamp . $body, $wave_webhook_secret);
        $valid = in_array($computed_hmac, $signatures);
        return array($body, $valid);
    }

    /**
     * @throws ConnectionException
     */
    public function getLatestWaveTransactions()
    {
        $url = "https://api.wave.com/v1/transactions";
        $headers = $this->getWaveHeaders();
        $response = Http::withHeaders($headers)
            ->asJson()
            ->get($url);

        $response = $response->json();
        if (isset($response["items"])){
            return $response["items"];
        }
        return  [];
    }

    public static function getEndpointForRedirect()
    {
        return is_request_for_gp_customers() ? 'https://globaltransports.sn' : 'https://globeone.site';

    }

}

