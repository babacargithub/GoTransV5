<?php

namespace Tests\Feature;

use App\Manager\BookingManager;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WavePaiementControllerTest extends TestCase
{
    private const WEBHOOK_URL = '/go_travel_v5/public/api/mobile/payment/wave/success';

    private const WEBHOOK_SECRET = 'wave-test-signing-secret';

    private const FROZEN_NOW = '2026-09-26 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.wave_webhook_secret' => self::WEBHOOK_SECRET]);
    }

    private function completedCheckoutWebhookBody(): string
    {
        return json_encode([
            'type' => 'checkout.session.completed',
            'data' => [
                'id' => 'cos-test-1',
                'transaction_id' => 'TX-1',
                'client_reference' => json_encode(['type' => 'booking', 'id' => 987654321]),
            ],
        ]);
    }

    private function signedHeader(string $body, string $secret = self::WEBHOOK_SECRET, ?int $timestamp = null): string
    {
        $timestamp ??= now()->getTimestamp();

        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.$body, $secret);
    }

    private function postWebhook(string $body, ?string $waveSignatureHeader)
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($waveSignatureHeader !== null) {
            $server['HTTP_WAVE_SIGNATURE'] = $waveSignatureHeader;
        }

        return $this->call('POST', self::WEBHOOK_URL, [], [], [], $server, $body);
    }

    private function assertRejectedWithLoggedReason($response, string $expectedReasonFragment): void
    {
        $response->assertUnauthorized();
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => is_string($message)
                && str_starts_with($message, 'Wave signature rejected: ')
                && str_contains($message, $expectedReasonFragment))
            ->once();
    }

    public function test_valid_signature_is_accepted_and_the_event_is_processed(): void
    {
        $this->freezeTime();
        Log::spy();
        $body = json_encode(['type' => 'checkout.session.expired', 'data' => ['id' => 'cos-test-1']]);

        $this->postWebhook($body, $this->signedHeader($body))->assertOk();

        Log::shouldHaveReceived('alert')->with('event sent by wavecheckout.session.expired')->once();
        Log::shouldNotHaveReceived('error');
    }

    public function test_valid_signature_is_accepted_when_it_is_one_of_several_during_secret_rotation(): void
    {
        $this->freezeTime();
        Log::spy();
        $body = json_encode(['type' => 'checkout.session.expired', 'data' => ['id' => 'cos-test-1']]);
        $timestamp = now()->getTimestamp();
        $rotatedSecretHeader = 't='.$timestamp
            .',v1='.hash_hmac('sha256', $timestamp.$body, 'previous-secret')
            .',v1='.hash_hmac('sha256', $timestamp.$body, self::WEBHOOK_SECRET);

        $this->postWebhook($body, $rotatedSecretHeader)->assertOk();

        Log::shouldNotHaveReceived('error');
    }

    public function test_rejects_with_401_and_saves_no_payment_when_the_signature_header_is_missing(): void
    {
        $this->freezeTime();
        Log::spy();
        $this->mock(BookingManager::class)->shouldNotReceive('saveTicketPayementForOnlineUsers');

        $response = $this->postWebhook($this->completedCheckoutWebhookBody(), null);

        $this->assertRejectedWithLoggedReason($response, 'Wave-Signature header is missing');
    }

    public function test_rejects_with_401_when_the_signature_header_is_malformed(): void
    {
        $this->freezeTime();
        Log::spy();
        $this->mock(BookingManager::class)->shouldNotReceive('saveTicketPayementForOnlineUsers');

        $response = $this->postWebhook($this->completedCheckoutWebhookBody(), 'garbage-without-parts');

        $this->assertRejectedWithLoggedReason($response, 'Wave-Signature header is malformed');
    }

    public function test_rejects_with_401_when_the_signature_was_made_with_another_secret(): void
    {
        $this->freezeTime();
        Log::spy();
        $this->mock(BookingManager::class)->shouldNotReceive('saveTicketPayementForOnlineUsers');
        $body = $this->completedCheckoutWebhookBody();

        $response = $this->postWebhook($body, $this->signedHeader($body, secret: 'some-other-secret'));

        $this->assertRejectedWithLoggedReason($response, 'no v1 signature matches');
    }

    public function test_rejects_with_401_when_the_body_was_altered_after_signing(): void
    {
        $this->freezeTime();
        Log::spy();
        $this->mock(BookingManager::class)->shouldNotReceive('saveTicketPayementForOnlineUsers');
        $originalBody = $this->completedCheckoutWebhookBody();
        $signatureHeader = $this->signedHeader($originalBody);

        $response = $this->postWebhook($originalBody.' ', $signatureHeader);

        $this->assertRejectedWithLoggedReason($response, 'no v1 signature matches');
    }

    public function test_rejects_with_401_when_the_timestamp_is_older_than_five_minutes(): void
    {
        $this->travelTo(self::FROZEN_NOW);
        Log::spy();
        $this->mock(BookingManager::class)->shouldNotReceive('saveTicketPayementForOnlineUsers');
        $body = $this->completedCheckoutWebhookBody();
        $sixMinutesAgo = now()->subMinutes(6)->getTimestamp();

        $response = $this->postWebhook($body, $this->signedHeader($body, timestamp: $sixMinutesAgo));

        $this->assertRejectedWithLoggedReason($response, 'more than the 300s tolerance');
    }

    public function test_rejects_with_401_when_the_configured_secret_is_empty_even_if_signed_with_an_empty_key(): void
    {
        $this->freezeTime();
        Log::spy();
        $this->mock(BookingManager::class)->shouldNotReceive('saveTicketPayementForOnlineUsers');
        config(['app.wave_webhook_secret' => null]);
        $body = $this->completedCheckoutWebhookBody();

        $response = $this->postWebhook($body, $this->signedHeader($body, secret: ''));

        $this->assertRejectedWithLoggedReason($response, 'wave_webhook_secret');
    }
}
