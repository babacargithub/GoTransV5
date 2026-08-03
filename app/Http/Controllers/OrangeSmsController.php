<?php

namespace App\Http\Controllers;

use App\Models\SmsDeliveryReceipt;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class OrangeSmsController extends Controller
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * Receives SMS delivery receipts (DR) pushed by Orange's SMS API once a callback URL has been
     * registered for our account (Orange whitelists an IP and pushes here per-message, there is no
     * per-request notifyURL for this API - see https://developer.orange.com/apis/sms-sn/getting-started).
     *
     * Expected payload:
     * {
     *   "deliveryInfoNotification": {
     *     "callbackData": "<id of the sent sms>",
     *     "deliveryInfo": {
     *       "address": "tel:+221xxxxxxxxx",
     *       "deliveryStatus": "DeliveredToTerminal"
     *     }
     *   }
     * }
     *
     * Must respond with HTTP 200 to acknowledge receipt, otherwise Orange will retry.
     */
    public function deliveryReceipt(Request $request): Response
    {
        $payload = $request->json('deliveryInfoNotification', []);
        $address = $payload['deliveryInfo']['address'] ?? null;

        $this->logger->info("Orange SMS delivery receipt received ::::: " . $request->getContent());

        SmsDeliveryReceipt::create([
            'callback_data' => $payload['callbackData'] ?? null,
            'phone_number' => $address !== null ? preg_replace('/^tel:\+?/', '', $address) : null,
            'delivery_status' => $payload['deliveryInfo']['deliveryStatus'] ?? null,
            'raw_payload' => $request->json()->all(),
        ]);

        return new Response('OK');
    }
}
