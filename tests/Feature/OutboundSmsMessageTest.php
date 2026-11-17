<?php

namespace Tests\Feature;

use App\Enums\OutboundSmsMessageStatus;
use App\Models\OutboundSmsMessage;
use App\Utils\NotificationSender\SMSSender\SMSSender;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OutboundSmsMessageTest extends TestCase
{
    use DatabaseTransactions;

    private const ORANGE_SMS_URL_PATTERN = 'api.orange.com/smsmessaging/*';

    /**
     * A sender that really calls (the faked) Orange, without touching the access token file on disk.
     */
    private function createSenderThatDeliversInTests(): SMSSender
    {
        config([
            'app.orange_api_client_id' => 'client-id',
            'app.orange_api_client_secret' => 'client-secret',
            'app.orange_api_sender_name' => 'Globe One',
            'app.orange_api_sender_number' => '+221200600',
        ]);

        $smsSender = new class extends SMSSender
        {
            public function getToken(): string
            {
                return 'fake-token';
            }
        };
        $smsSender->deliverOnTestEnvironment = true;

        return $smsSender;
    }

    public function test_an_accepted_sms_is_stored_as_sent_with_orange_reference_and_international_number(): void
    {
        Http::fake([self::ORANGE_SMS_URL_PATTERN => Http::response([
            'outboundSMSMessageRequest' => ['resourceURL' => 'https://api.orange.com/requests/abc'],
        ])]);

        $wasSent = $this->createSenderThatDeliversInTests()->sendSms('771234567', 'Bonjour');

        $this->assertTrue($wasSent);
        $storedMessage = OutboundSmsMessage::query()->latest('id')->firstOrFail();
        $this->assertSame('221771234567', $storedMessage->recipient_phone_number);
        $this->assertSame('Bonjour', $storedMessage->content);
        $this->assertSame(OutboundSmsMessageStatus::Sent, $storedMessage->status);
        $this->assertSame('https://api.orange.com/requests/abc', $storedMessage->provider_resource_url);
        $this->assertNotNull($storedMessage->sent_at);
    }

    public function test_a_rejected_sms_is_stored_as_failed_with_the_reason(): void
    {
        Http::fake([self::ORANGE_SMS_URL_PATTERN => Http::response(['error' => 'quota exceeded'], 429)]);

        $wasSent = $this->createSenderThatDeliversInTests()->sendSms('221771234567', 'Bonjour');

        $this->assertFalse($wasSent);
        $storedMessage = OutboundSmsMessage::query()->latest('id')->firstOrFail();
        $this->assertSame(OutboundSmsMessageStatus::Failed, $storedMessage->status);
        $this->assertNotEmpty($storedMessage->failure_reason);
        $this->assertNull($storedMessage->sent_at);
    }

    public function test_an_sms_that_orange_answers_without_a_request_is_stored_as_failed(): void
    {
        Http::fake([self::ORANGE_SMS_URL_PATTERN => Http::response(['unexpected' => true])]);

        $wasSent = $this->createSenderThatDeliversInTests()->sendSms('771234567', 'Bonjour');

        $this->assertFalse($wasSent);
        $this->assertSame(OutboundSmsMessageStatus::Failed, OutboundSmsMessage::query()->latest('id')->firstOrFail()->status);
    }

    public function test_in_the_test_environment_the_sms_is_stored_as_skipped_and_nothing_is_sent(): void
    {
        Http::fake();

        $result = app(SMSSender::class)->sendSms('771234567', 'Bonjour');

        $this->assertNull($result);
        $this->assertSame(OutboundSmsMessageStatus::Skipped, OutboundSmsMessage::query()->latest('id')->firstOrFail()->status);
        Http::assertNothingSent();
    }
}
