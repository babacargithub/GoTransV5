<?php

/** @noinspection PhpUnused */

/**
 * Copyright (c) 2020.  All rights reserved for Globe One Software
 */

namespace App\Http\Controllers;

use App\Http\Resources\PaymentResponseResource;
use App\Manager\BookingManager;
use App\Manager\TicketManager;
use App\Models\Booking;
use App\Models\Depart;
use App\Services\WaveService;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response as ResponseAlias;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class WavePaiementController extends Controller
{
    public static function headers()
    {
        return app(WaveService::class)->headers();
    }

    private BookingManager $bookingManager;

    private LoggerInterface $logger;

    private WaveService $waveService;

    public function __construct(BookingManager $bookingManager, LoggerInterface $logger, WaveService $waveService)
    {
        $this->bookingManager = $bookingManager;
        $this->logger = $logger;
        $this->waveService = $waveService;
    }

    public function wavePaymentSuccessCallBack(Request $request): Response
    {
        $this->logger->alert('Wave payment endpoint called');

        try {
            [$body, $valid] = $this->verifyWaveSignature($request);
            if ($valid) {
                // This is a request from Wave.
                // You can proceed decoding the request body.
                $body = json_decode($body);
                $webhook_event = $body->type;
                if ($webhook_event == 'checkout.session.completed') {
                    $webhook_data = $body->data;
                    $waveTransactionId = $webhook_data->transaction_id ?? null;
                    $waveCheckoutId = $webhook_data->id ?? null;

                    $metaData = json_decode($webhook_data->client_reference, true);
                    $id = null;

                    try {
                        // determine if it is for booking or depart
                        // check if booking
                        if ($metaData['type'] == 'booking' || $metaData['type'] == 'depart') {
                            $id = $metaData['id'];
                        }
                        if ($metaData['type'] == 'booking') {
                            $booking = Booking::find($id);

                            if ($booking == null) {
                                throw new NotFoundHttpException('Wave paiement failed : Booking with id '.$id.' not found for wave');
                            }
                            $this->bookingManager->saveTicketPayementForOnlineUsers($booking, $this->logger, 'wave', ['transaction_id' => $waveTransactionId, 'checkout_id' => $waveCheckoutId]);
                            $this->logger->alert('Wave payment saved for booking !'.$id.', for customer '.
                                $booking->customer->full_name.', for depart '.$booking->depart->name);
                        } elseif ($metaData['type'] == 'multiple_booking') {
                            $group_id = $metaData['group_id'];
                            $depart_id = $metaData['depart_id'];

                            $bookings = Booking::whereGroupId($group_id)->get();

                            if ($bookings->count() == 0) {
                                throw new NotFoundHttpException('Wave paiement failed : Booking group with group id '
                                    .$group_id.' not found during Wave payment');
                            }
                            // TODO replace depart with bus
                            $departForMultipleBooking = Depart::find($depart_id);
                            if ($departForMultipleBooking == null) {
                                throw new NotFoundHttpException('Wave paiement failed : Depart with id '.$depart_id.' not found for wave');
                            }
                            $this->bookingManager->saveTicketPaymentMultipleBooking($departForMultipleBooking,
                                $departForMultipleBooking->getBusForBooking(),
                                $bookings, $this->logger, 'wave', ['transaction_id' => $waveTransactionId, 'checkout_id' => $waveCheckoutId]);
                            $this->logger->alert('Wave payment saved for multiple  booking !'.$id.', for group id of bookings  '
                                .$group_id);

                        }// check if transaction is of depart type

                    } catch (Exception $e) {
                        $this->logger->error('Unable to save payment made by phone number: '.$webhook_data->sender_mobile);
                        $this->logger->error($e->getMessage().'---'.$e->getFile().'---'.$e->getTraceAsString());

                    }

                } else {
                    $this->logger->alert('event sent by wave'.$webhook_event);
                }

            } else {
                return (new Response('Unable to verify webhook signature.'))
                    ->setStatusCode(ResponseAlias::HTTP_UNAUTHORIZED);
            }
        } catch (Exception $e) {
            try {
                $this->logger->error($e);
            } catch (Exception $e) {
                return (new Response($e->getMessage()))->setStatusCode(ResponseAlias::HTTP_OK);
            } finally {
                return (new Response)->setStatusCode(ResponseAlias::HTTP_OK);

            }

        }

        return (new Response)->setStatusCode(ResponseAlias::HTTP_OK);
    }

    /**
     * @throws ConnectionException
     */
    public function getWavePaymentUrlForBooking(Booking $booking, TicketManager $ticketManager): PaymentResponseResource
    {
        return $this->waveService->createCheckoutSessionForBooking($booking, $ticketManager);
    }

    /**
     * @throws InvalidArgumentException
     * @throws ConnectionException
     */
    public function getPaymentUrl(array $data): PaymentResponseResource
    {
        return $this->waveService->createCheckoutSession($data);
    }

    protected function getWaveHeaders(): array
    {
        return $this->waveService->headers();
    }

    /**
     * @return array{0: string, 1: bool} The raw request body and whether Wave's signature is valid.
     */
    public function verifyWaveSignature(Request $request): array
    {
        return $this->waveService->verifyWebhookSignature($request);
    }

    /**
     * @throws ConnectionException
     */
    public function getLatestWaveTransactions()
    {
        return $this->waveService->getLatestTransactions();
    }

    public static function findTransactionIdOfCheckoutSession(string $checkoutSessionId): ?string
    {
        return app(WaveService::class)->findTransactionIdOfCheckoutSession($checkoutSessionId);
    }

    public static function getEndpointForRedirect()
    {
        return app(WaveService::class)->getRedirectEndpoint();
    }

    public static function refundTransaction($checkout_id): Response
    {
        $refundFailureReason = app(WaveService::class)->refundTransaction((string) $checkout_id);

        return $refundFailureReason === null
            ? (new Response)->setStatusCode(ResponseAlias::HTTP_OK)
            : (new Response($refundFailureReason))->setStatusCode(ResponseAlias::HTTP_BAD_REQUEST);
    }

    public function getBalance(): int
    {
        return $this->waveService->getBalance();
    }
}
