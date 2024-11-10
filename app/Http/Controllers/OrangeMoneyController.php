<?php /** @noinspection PhpUnused */


/**
 * Copyright (c) 2020.  All rights reserved for Globe One Software
 */

namespace App\Http\Controllers;

use App\Http\Resources\PaymentResponseResource;
use App\Models\Customer;
use App\Manager\BookingManager;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\InvalidArgumentException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrangeMoneyController extends Controller
{
    /**
     * @var BookingManager
     */
    private BookingManager $bookingManager;
    private LoggerInterface $logger;

    /**
     * @param BookingManager $bookingManager
     * @param LoggerInterface $logger
     */
    public function __construct(BookingManager $bookingManager, LoggerInterface $logger)
    {

        $this->bookingManager = $bookingManager;
        $this->logger = $logger;
    }

    /**
     * @throws Exception
     */
    public function orangeMoneyPaymentSuccessCallBack(RequestStack $request): Response
    {

        $data = json_decode($request->getCurrentRequest()->getContent(), true);
        if (isset($data['status']) && isset($data['customer'])) {
            if ($data['status'] == "SUCCESS") {
                $customerPhoneNumber = $data["customer"]['id'];
                $customer = Customer::find($customerPhoneNumber);

                if ($customer != null) {
                    $booking = $customer->getCurrentBooking();
                    if ($booking != null) {
                        // save payment
                        if ($booking->ticket == null) {
                            $this->bookingManager->saveTicketPayementForOnlineUsers($booking, $this->logger, "om");
                        }
                    } else {
                        $this->logger->alert("Booking is null for customer OM payment");
                    }

                }
                    // handle case when customer is not found
                    // if payment is for multiple booking
                    /*$ticketPayment = $this->getDoctrine()->getRepository(TicketPayment::class)
                        ->findOneBy(["status" => TicketPayment::STATUS_PENDING, "phoneNumber" =>
                            $customerPhoneNumber, "payementMethod" => "om"]);
                    if ($ticketPayment == null) {
                        throw new NotFoundHttpException('Om paiement failed : Ticket payment not found for phone number ' . $customerPhoneNumber);
                    }
                        $group_id = $ticketPayment->getGroupId();
                        $depart_id = $ticketPayment->getMetaData()["depart"];
                        $bookings = $this->getDoctrine()->getRepository(Booking::class)
                            ->findBookingsOfGroup($group_id);

                        if (count($bookings) == 0) {
                            throw new NotFoundHttpException('Wave paiement failed : Booking group with group id '
                                . $group_id . ' not found during Wave payment');
                        }
                        $departForMultipleBooking = $this->getDoctrine()->getRepository(Depart::class)->find($depart_id);
                        if ($departForMultipleBooking == null) {
                            throw new NotFoundHttpException('Wave paiement failed : Depart with id ' . $depart_id . ' not found for wave');
                        }
                        $this->bookingManager->saveTicketPaymentMultipleBooking($departForMultipleBooking, $bookings,
                            $this->logger, "om");


                    $ticketPayment->setStatus(TicketPayment::STATUS_SUCCESS);
                    $ticketPayment->setRefunded(false);
                    $ticketPayment->setPayementMethod("om");
                    $this->getDoctrine()->getManager()->persist($ticketPayment);
                    $this->getDoctrine()->getManager()->flush();*/
            }
        }

        return new Response('OM url called');

    }

    /**
     * @throws ConnectionException
     * @throws RequestException
     */
    protected function getAccessTokenOM()
    {

        $response = Http::asForm()
        ->withHeaders([
            'Accept' => 'application/x-www-form-urlencoded',
            'Authorization' => "Basic ".config('app.om_api_key_base_64_encoded')
        ])->post("https://api.orange-sonatel.com/oauth/token",
            [
                "grant_type" => "client_credentials",
            ]
        );
        $response->throw();

        $accessTokens = $response->json();

        return $accessTokens["access_token"];
    }


    /**
     * @throws GuzzleException
     */
    public function initOMPayment($data): PaymentResponseResource
    {
        if (!isset($data['amount']) || !isset($data['customer']) || !isset($data['metadata']) || !is_array($data['metadata'])) {
            throw new InvalidArgumentException("amount, customer and metadata should be present in the request");
        }

        try {

            $amount = $data['amount'];
            $customerNumber = $data['customer'];
            $metadata = $data['metadata'];
            $requestData =
                [
                    'amount' => [
                        'unit' => 'XOF',
                        'value' => $amount,
                    ],
                    'customer' => [
                        'id' => $customerNumber,
                        'idType' => 'MSISDN',
                        'walletType' => 'PRINCIPAL',
                    ],
                    'method' => 'QRCODE',
                    'partner' => [
                        'encryptedPinCode' => config('app.om_merchant_encrypted_pin'),
                        'id' => config('app.om_merchant_msisdn'),
                        'idType' => 'MSISDN',
                        'walletType' => 'PRINCIPAL',
                    ],
                    'metadata' => $metadata,
                    'receiveNotification' => true
                ];
            $headers = $this->omHeaders();
            $response = Http::asJson()
                ->withHeaders($headers)
                ->post("https://api.orange-sonatel.com/api/eWallet/v1/payments", $requestData);
            $response->throw();
            try {
                $response->throw();
                return new PaymentResponseResource($response, paymentMethod: "om");
            } catch (RequestException $e) {
                $this->logger->error($e->getMessage());
                return new PaymentResponseResource($e->response, paymentMethod: "om");
            }
        }catch (ConnectionException|RequestException $e) {
            $this->logger->error($e->getMessage());
            return new PaymentResponseResource($e->response, paymentMethod: "om");
        }
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function balance(): JsonResponse
    {
        $reqBody = [
            'idType' => 'MSISDN',
            'encryptedPinCode' => config('app.om_merchant_encrypted_pin'),
            'id' => config('app.om_merchant_msisdn'),
            'wallet' => 'PRINCIPAL',
        ];
        $headers = $this->omHeaders();
        $response = Http::asJson()
            ->withHeaders($headers)
            ->post("https://api.orange-sonatel.com/api/eWallet/v1/account/retailer/balance", $reqBody);
        $response->throw();
        $data = $response->json();
        return new JsonResponse(["balance" => $data['value']]);
    }

    /**
     * @throws ConnectionException|RequestException
     */
    public function transactions(): JsonResponse
    {
        $headers  = $this->omHeaders();
        $response = Http::asJson()->withHeaders($headers)
            ->get("https://api.orange-sonatel.com/api/eWallet/v1/transactions?size=300");

        return new JsonResponse($response->json());

    }

    /**
     * @throws ConnectionException
     * @throws RequestException
     */
    public function withdraw(Request $request): JsonResponse
    {

        $content = $request->validate([
        'amount' => 'required|integer',
        'secretCode' => 'required',
        'phoneNumber' => 'required']);

        if ($content) {
            $phoneNumber = $content['phoneNumber'];
            $amount = $content['amount'];
            $secretCode = $content['secretCode'];

            if ($secretCode == config('app.om_secret_code')) {
                $reqBody = [
                    'partner' => [
                        'idType' => 'MSISDN',
                        'id' => config('app.om_merchant_msisdn'),
                        'encryptedPinCode' => config('app.om_merchant_encrypted_pin')
                    ],
                    'customer' => [
                        'idType' => 'MSISDN',
                        'id' => $phoneNumber,
                    ],
                    'amount' => [
                        'value' => $amount,
                        'unit' => 'XOF',
                    ],
                    'reference' => '',
                    'receiveNotification' => true,
                ];
                $headers = $this->omHeaders();
                try {
                    $response = Http::asJson()->withHeaders($headers)
                        ->post('https://api.orange-sonatel.com/api/eWallet/v1/cashins', $reqBody);
                    $response->throw();
                } catch (ConnectionException|RequestException $e) {
                    return \response()->json(["message"=>$e->getMessage()],422);

                }
                return \response()->json($response->json());
            } else {
                return \response()->json(["message"=>"bad request: invalid withdraw secret code"],422);

            }
        } else {
            throw new HttpException(400, "bad request: amount and secret code should be present");
        }
    }

    /**
     * @throws ConnectionException|RequestException
     */
    public function omHeaders(): array
    {
        $accessToken = $this->getAccessTokenOM();
        return [
            "Authorization" => "Bearer " . $accessToken,
            'Accept' => 'application/json',

        ];
    }

}

