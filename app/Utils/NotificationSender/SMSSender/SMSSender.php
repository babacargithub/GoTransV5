<?php

namespace App\Utils\NotificationSender\SMSSender;

use App\Enums\OutboundSmsMessageStatus;
use App\Models\OutboundSmsMessage;
use DateTime;
use Exception;
use Http;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Psr\Http\Client\ClientExceptionInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * helps send SMS messages using the Bundle MediumArt for Orange Api
 * Class SMSSender
 */
class SMSSender
{
    private string $clientId;

    private string $clientSecret;

    private string $tokenUrl = 'https://api.orange.com/oauth/v3/token';

    private string $clientSenderName;

    public bool $deliverOnTestEnvironment = false;

    private string $clientSenderPhoneNumber;

    public function __construct()
    {

        $this->clientId = config('app.orange_api_client_id');
        $this->clientSecret = config('app.orange_api_client_secret');
        $this->clientSenderName = config('app.orange_api_sender_name');
        $this->clientSenderPhoneNumber = config('app.orange_api_sender_number');
    }
    //    public function __construct(HttpClientInterface $client, string $clientId, string $clientSecret)
    //    {
    //        $this->client = $client;
    //        $this->clientId = $clientId;
    //        $this->clientSecret = $clientSecret;
    //        $this->tokenUrl = 'https://api.orange.com/oauth/v3/token';  // Orange token URL
    //    }

    /**
     * @throws Exception
     */
    public function getToken(): string
    {
        $tokenFilePath = __DIR__.'/tokens/orange_sms_token.json'; // Specify the file path

        // Check if the token file exists and is still valid
        if (file_exists($tokenFilePath)) {
            $tokenData = json_decode(file_get_contents($tokenFilePath), true);

            // Check if the token has not expired
            if (isset($tokenData['expires_at']) && new DateTime < new DateTime($tokenData['expires_at'])) {
                return $tokenData['access_token'];
            }
        }

        // If no valid token is found, request a new one

        $response = Http::asForm()->withHeaders([
            'Authorization' => 'Basic '.base64_encode($this->clientId.':'.$this->clientSecret),
            //            'Content-Type' => 'application/x-www-form-urlencoded',
        ])->post($this->tokenUrl, [
            'grant_type' => 'client_credentials',
        ]);

        $response->throw();

        // Parse the response
        $data = $response->json();

        // Prepare the token data to be saved
        $accessToken = $data['access_token'];
        $expiresAt = new DateTime('+'.$data['expires_in'].' seconds');

        // Save the token and expiry time in a local file
        $tokenData = [
            'access_token' => $accessToken,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),  // Save the expiration time
        ];

        // Ensure the token directory exists
        if (! file_exists(dirname($tokenFilePath))) {
            mkdir(dirname($tokenFilePath), 0777, true);
        }

        // Save the token data as JSON in the file
        file_put_contents($tokenFilePath, json_encode($tokenData));

        return $accessToken;
    }

    public function sendSms($phoneNumber, $content): ?bool
    {
        // if the country code is missing we add it
        if (! str_starts_with($phoneNumber, '221')) {
            $phoneNumber = '221'.$phoneNumber;
        }

        $outboundSmsMessage = $this->storeBeforeSending($phoneNumber, $content);

        try {
            // avoid sending real sms in test and dev environment
            if (app()->environment('testing')) {
                if (! $this->deliverOnTestEnvironment) {
                    $outboundSmsMessage?->markAsSkipped();

                    return null;
                }
            }

            $response = $this->sendSmsV2($this->getToken(), $phoneNumber, $content);

            if (! self::hasRequestSucceeded($response)) {
                $outboundSmsMessage?->markAsFailed('Unexpected Orange response: '.json_encode($response));

                return false;
            }

            $outboundSmsMessage?->markAsSent($response['outboundSMSMessageRequest']['resourceURL'] ?? null);

            return true;
        } catch (ClientExceptionInterface|TransportExceptionInterface|Exception $e) {
            $outboundSmsMessage?->markAsFailed($e->getMessage());

            return false;
        }
    }

    /**
     * Keeps a trace of the SMS before Orange is called. A storage problem is logged and never prevents the SMS
     * from being sent.
     */
    private function storeBeforeSending(string $phoneNumber, string $content): ?OutboundSmsMessage
    {
        try {
            return OutboundSmsMessage::create([
                'recipient_phone_number' => $phoneNumber,
                'content' => $content,
                'status' => OutboundSmsMessageStatus::Pending,
            ]);
        } catch (Throwable $throwable) {
            Log::error("Could not store the outbound SMS to {$phoneNumber}: {$throwable->getMessage()}");

            return null;
        }
    }

    /**
     * @throws ConnectionException
     * @throws RequestException
     */
    protected function sendSmsV2(string $token, string $phoneNumber, string $message): array
    {
        $smsUrl = 'https://api.orange.com/smsmessaging/v1/outbound/'.urlencode('tel:'.$this->clientSenderPhoneNumber).'/requests';
        $response = Http::asJson()->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])
            ->post($smsUrl, [
                'outboundSMSMessageRequest' => [
                    'address' => 'tel:+'.$phoneNumber,
                    'senderAddress' => 'tel:+221200600',
                    'senderName' => $this->clientSenderName,
                    'outboundSMSTextMessage' => [
                        'message' => $message,
                    ],
                ],

            ]);
        $response->throw();

        return $response->json();

    }

    public static function hasRequestSucceeded(array $response): bool
    {
        return isset($response['outboundSMSMessageRequest']);

    }

    public function sendMultipleSms(array $messages): void
    {
        foreach ($messages as $message) {
            $this->sendSms($message['phone_number'], $message['message']);
        }
    }
}
