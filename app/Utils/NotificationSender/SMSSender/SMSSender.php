<?php


namespace App\Utils\NotificationSender\SMSSender;



use DateTime;
use Exception;
use Http;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Psr\Http\Client\ClientExceptionInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;


/**
 * helps send SMS messages using the Bundle MediumArt for Orange Api
 * Class SMSSender
 * @package App\Utils\NotificationSender\SMSSender
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

        $this->clientId = config("app.orange_api_client_id");
        $this->clientSecret = config("app.orange_api_client_secret");
        $this->clientSenderName = config("app.orange_api_sender_name");
        $this->clientSenderPhoneNumber = config("app.orange_api_sender_number");
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
        $tokenFilePath = __DIR__ . '/tokens/orange_sms_token.json'; // Specify the file path

        // Check if the token file exists and is still valid
        if (file_exists($tokenFilePath)) {
            $tokenData = json_decode(file_get_contents($tokenFilePath), true);

            // Check if the token has not expired
            if (isset($tokenData['expires_at']) && new DateTime() < new DateTime($tokenData['expires_at'])) {
                return $tokenData['access_token'];
            }
        }

        // If no valid token is found, request a new one

        $response = Http::asForm()->withHeaders([
            'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
//            'Content-Type' => 'application/x-www-form-urlencoded',
        ])->post($this->tokenUrl, [
            'grant_type' => 'client_credentials',
        ]);

        $response->throw();


        // Parse the response
        $data = $response->json();

        // Prepare the token data to be saved
        $accessToken = $data['access_token'];
        $expiresAt = new DateTime('+' . $data['expires_in'] . ' seconds');

        // Save the token and expiry time in a local file
        $tokenData = [
            'access_token' => $accessToken,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),  // Save the expiration time
        ];

        // Ensure the token directory exists
        if (!file_exists(dirname($tokenFilePath))) {
            mkdir(dirname($tokenFilePath), 0777, true);
        }

        // Save the token data as JSON in the file
        file_put_contents($tokenFilePath, json_encode($tokenData));

        return $accessToken;
    }


    public function sendSms($phoneNumber, $content): ?bool
    {
        // if the country code is missing we add it

        try {
            if (!str_starts_with($phoneNumber, "221")) {
                $phoneNumber = "221" . $phoneNumber;
            }//        $clientId = $this->container->getParameter("orange_api_client_id");
            //        $clientSecret = $this->container->getParameter("orange_api_client_secret");
            //        $clientSenderName = $this->container->getParameter("orange_api_sender_name");
            //        $clientSenderPhoneNumber = $this->container->getParameter("orange_api_sender_number");
            // avoid sending real sms in test and dev environment
            if ( app()->environment('testing')) {
                if (!$this->deliverOnTestEnvironment) {
                    return null;
                }
            }
            //        $client = SMSClient::getInstance($clientId, $clientSecret);
            //
            //        $sms = new SMS($client);
            //        $sms->message($content)
            //            ->from($clientSenderPhoneNumber, $clientSenderName)
            //            ->to($phoneNumber);
            return self::hasRequestSucceeded($this->sendSmsV2($this->getToken(), $phoneNumber, $content));
        } catch (ClientExceptionInterface|TransportExceptionInterface|Exception $e) {
            print_r($e->getMessage());
            return false;
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
            'Authorization' => 'Bearer ' . $token,
        ])
        ->post($smsUrl, [
                'outboundSMSMessageRequest' => [
                    'address' => 'tel:+' . $phoneNumber,
                    'senderAddress' => 'tel:+221200600',
                        "senderName" => $this->clientSenderName,
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
        return isset($response["outboundSMSMessageRequest"]);

    }

    public function sendMultipleSms(array $messages): void
    {
        foreach ($messages as $message) {
            $this->sendSms($message["phone_number"], $message["message"]);
        }
    }


}