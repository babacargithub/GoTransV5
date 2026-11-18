<?php

namespace Tests\Feature;

use App\Enums\AmbassadorEarningStatus;
use App\Enums\AmbassadorPayoutStatus;
use App\Models\Ambassador;
use App\Models\AmbassadorEarning;
use App\Models\AmbassadorPayout;
use App\Services\EarningPayoutService;
use App\Services\NotificationService;
use App\Services\WaveService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WaveServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Real money only moves in production: simulate it, with every HTTP call faked and strays refused.
        $this->app['env'] = 'production';
        config(['app.wave_key' => 'test-wave-key']);
        Http::preventStrayRequests();
    }

    private function waveService(): WaveService
    {
        return app(WaveService::class);
    }

    private function createPendingPayoutWithEarnings(int ...$earningAmounts): AmbassadorPayout
    {
        $ambassador = Ambassador::factory()->create(['wave_phone_number' => '771234567']);
        foreach ($earningAmounts as $earningAmount) {
            AmbassadorEarning::factory()->for($ambassador)->approved()->create(['amount' => $earningAmount]);
        }

        return app(EarningPayoutService::class)->createPayoutForPayableEarnings($ambassador);
    }

    public function test_a_settled_wave_payout_marks_the_payout_and_its_earnings_as_paid(): void
    {
        Http::fake(['api.wave.com/v1/payout' => Http::response(['id' => 'pt-123', 'status' => 'succeeded'])]);
        $payout = $this->createPendingPayoutWithEarnings(500, 2000);

        $this->waveService()->pay($payout);

        $payout->refresh();
        $this->assertSame(AmbassadorPayoutStatus::Success, $payout->status);
        $this->assertSame('pt-123', $payout->provider_payout_id);
        $this->assertNotNull($payout->paid_at);
        $this->assertSame(1, $payout->attempts_count);
        $this->assertSame(2, $payout->earnings()->where('status', AmbassadorEarningStatus::Paid)->whereNotNull('paid_at')->count());
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Idempotency-Key', $payout->idempotency_key)
            && $request['receive_amount'] === '2500'
            && $request['mobile'] === '+221771234567'
            && $request['payment_reason'] === 'Commissions Ambassadeur Globe One');
    }

    public function test_a_wave_error_fails_the_payout_with_a_reason_and_keeps_earnings_attached(): void
    {
        Http::fake(['api.wave.com/v1/payout' => Http::response(['message' => 'Insufficient funds'], 400)]);
        $payout = $this->createPendingPayoutWithEarnings(500);

        $this->waveService()->pay($payout);

        $payout->refresh();
        $this->assertSame(AmbassadorPayoutStatus::Failed, $payout->status);
        $this->assertStringContainsString('Insufficient funds', $payout->failure_reason);
        $this->assertNull($payout->paid_at);
        $this->assertSame(AmbassadorEarningStatus::Approved, $payout->earnings()->first()->status);
        $this->assertSame(1, $payout->earnings()->count());
    }

    public function test_retrying_a_failed_payout_reuses_the_same_idempotency_key_and_can_succeed(): void
    {
        Http::fake(['api.wave.com/v1/payouts/search*' => Http::response(['result' => []])]);
        Http::fakeSequence('api.wave.com/v1/payout')
            ->push(['message' => 'Server error'], 500)
            ->push(['id' => 'pt-9', 'status' => 'succeeded']);
        $payout = $this->createPendingPayoutWithEarnings(500);
        $idempotencyKey = $payout->idempotency_key;

        $this->waveService()->pay($payout);
        $this->waveService()->pay($payout);

        $payout->refresh();
        $this->assertSame(AmbassadorPayoutStatus::Success, $payout->status);
        $this->assertNull($payout->failure_reason);
        $this->assertSame(2, $payout->attempts_count);
        $this->assertSame($idempotencyKey, $payout->idempotency_key);
        Http::assertSentCount(3);
    }

    public function test_a_succeeded_payout_is_never_sent_again(): void
    {
        Http::fake();
        $payout = AmbassadorPayout::factory()->succeeded()->create();

        $this->waveService()->pay($payout);

        Http::assertNothingSent();
    }

    public function test_a_payout_wave_accepted_but_not_settled_is_not_resent(): void
    {
        Http::fake(['api.wave.com/v1/payout' => Http::response(['id' => 'pt-5', 'status' => 'processing'])]);
        $payout = $this->createPendingPayoutWithEarnings(500);

        $this->waveService()->pay($payout);
        $this->waveService()->pay($payout);

        $payout->refresh();
        $this->assertSame(AmbassadorPayoutStatus::Pending, $payout->status);
        $this->assertSame('pt-5', $payout->provider_payout_id);
        Http::assertSentCount(1);
    }

    public function test_a_payout_is_no_longer_retried_after_the_maximum_attempts(): void
    {
        Http::fake();
        $payout = AmbassadorPayout::factory()->create([
            'status' => AmbassadorPayoutStatus::Failed,
            'attempts_count' => AmbassadorPayout::MAX_ATTEMPTS,
        ]);

        $this->waveService()->pay($payout);

        Http::assertNothingSent();
    }

    public function test_retry_unsettled_payouts_resends_failed_ones_only(): void
    {
        Http::fake([
            'api.wave.com/v1/payout' => Http::response(['id' => 'pt-7', 'status' => 'succeeded']),
            'api.wave.com/v1/payouts/search*' => Http::response(['result' => []]),
            'api.wave.com/v1/payout/*' => Http::response(['code' => 'not-found'], 404),
        ]);
        $failedPayout = AmbassadorPayout::factory()->create(['status' => AmbassadorPayoutStatus::Failed, 'attempts_count' => 1]);
        AmbassadorPayout::factory()->succeeded()->create();

        $retriedPayoutsCount = $this->waveService()->retryUnsettledPayouts();

        $this->assertGreaterThanOrEqual(1, $retriedPayoutsCount);
        $this->assertSame(AmbassadorPayoutStatus::Success, $failedPayout->fresh()->status);
    }

    public function test_fetching_a_payout_by_its_wave_id_reports_its_wave_state(): void
    {
        Http::fake(['api.wave.com/v1/payout/pt-1' => Http::response(['id' => 'pt-1', 'status' => 'succeeded'])]);
        $payout = AmbassadorPayout::factory()->create(['provider_payout_id' => 'pt-1']);

        $this->assertSame(
            ['state' => 'succeeded', 'transaction_id' => 'pt-1', 'failure_reason' => null],
            $this->waveService()->fetchPayout($payout),
        );
    }

    public function test_fetching_a_payout_without_wave_id_searches_it_by_client_reference(): void
    {
        Http::fake(['api.wave.com/v1/payouts/search*' => Http::response(['result' => [['id' => 'pt-2', 'status' => 'processing']]])]);
        $payout = AmbassadorPayout::factory()->create(['provider_payout_id' => null]);

        $wavePayout = $this->waveService()->fetchPayout($payout);

        $this->assertSame('processing', $wavePayout['state']);
        $this->assertSame('pt-2', $wavePayout['transaction_id']);
        Http::assertSent(fn (Request $request): bool => $request['client_reference'] === "ambassador-payout-{$payout->id}");
    }

    public function test_a_failed_or_reversed_wave_payout_is_reported_failed_with_its_reason(): void
    {
        Http::fake([
            'api.wave.com/v1/payout/pt-3' => Http::response(['id' => 'pt-3', 'status' => 'failed', 'payout_error' => ['error_code' => 'recipient-account-blocked', 'error_message' => 'Compte bloqué']]),
            'api.wave.com/v1/payout/pt-4' => Http::response(['id' => 'pt-4', 'status' => 'reversed']),
        ]);

        $failed = $this->waveService()->fetchPayout(AmbassadorPayout::factory()->create(['provider_payout_id' => 'pt-3']));
        $reversed = $this->waveService()->fetchPayout(AmbassadorPayout::factory()->create(['provider_payout_id' => 'pt-4']));

        $this->assertSame('failed', $failed['state']);
        $this->assertSame('recipient-account-blocked : Compte bloqué', $failed['failure_reason']);
        $this->assertSame('failed', $reversed['state']);
        $this->assertStringContainsString('reversed', $reversed['failure_reason']);
    }

    public function test_a_payout_unknown_to_wave_is_not_found_and_a_wave_outage_is_unreachable(): void
    {
        Http::fake([
            'api.wave.com/v1/payout/pt-5' => Http::response(['code' => 'not-found'], 404),
            'api.wave.com/v1/payout/pt-6' => Http::response([], 500),
        ]);

        $this->assertSame('not_found', $this->waveService()->fetchPayout(AmbassadorPayout::factory()->create(['provider_payout_id' => 'pt-5']))['state']);
        $this->assertSame('unreachable', $this->waveService()->fetchPayout(AmbassadorPayout::factory()->create(['provider_payout_id' => 'pt-6']))['state']);
    }

    public function test_reconciling_a_processing_payout_that_wave_settled_pays_its_earnings(): void
    {
        Http::fake([
            'api.wave.com/v1/payout' => Http::response(['id' => 'pt-8', 'status' => 'processing']),
            'api.wave.com/v1/payout/pt-8' => Http::response(['id' => 'pt-8', 'status' => 'succeeded']),
        ]);
        $payout = $this->createPendingPayoutWithEarnings(500, 1500);
        $this->waveService()->pay($payout);

        $this->waveService()->reconcilePayout($payout);

        $payout->refresh();
        $this->assertSame(AmbassadorPayoutStatus::Success, $payout->status);
        $this->assertNotNull($payout->paid_at);
        $this->assertSame(2, $payout->earnings()->where('status', AmbassadorEarningStatus::Paid)->count());
    }

    public function test_a_payout_whose_first_call_timed_out_but_reached_wave_is_settled_instead_of_resent(): void
    {
        Http::fake([
            'api.wave.com/v1/payout' => Http::response(['message' => 'Server error'], 500),
            'api.wave.com/v1/payouts/search*' => Http::response(['result' => [['id' => 'pt-10', 'status' => 'succeeded']]]),
        ]);
        $payout = $this->createPendingPayoutWithEarnings(500);
        $this->waveService()->pay($payout);

        $this->waveService()->pay($payout);

        $payout->refresh();
        $this->assertSame(AmbassadorPayoutStatus::Success, $payout->status);
        $this->assertSame('pt-10', $payout->provider_payout_id);
        $this->assertSame(1, $payout->attempts_count);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/payouts/search'));
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => $request->method() === 'POST'));
    }

    public function test_reconciliation_leaves_a_payout_untouched_when_wave_cannot_be_asked(): void
    {
        Http::fake(['api.wave.com/v1/payout/pt-11' => Http::response([], 503)]);
        $payout = AmbassadorPayout::factory()->create(['status' => AmbassadorPayoutStatus::Pending, 'provider_payout_id' => 'pt-11', 'attempts_count' => 1]);

        $this->waveService()->reconcilePayout($payout);

        $this->assertSame(AmbassadorPayoutStatus::Pending, $payout->fresh()->status);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonProductionEnvironmentProvider(): array
    {
        return ['testing' => ['testing'], 'local' => ['local'], 'staging' => ['staging']];
    }

    #[DataProvider('nonProductionEnvironmentProvider')]
    public function test_no_real_money_is_sent_outside_production(string $environment): void
    {
        $this->app['env'] = $environment;
        Http::fake();
        $payout = $this->createPendingPayoutWithEarnings(500);

        $this->waveService()->pay($payout);

        Http::assertNothingSent();
        $payout->refresh();
        $this->assertSame(AmbassadorPayoutStatus::Success, $payout->status);
        $this->assertStringStartsWith('simulated-', $payout->provider_payout_id);
    }

    public function test_nothing_is_sent_to_wave_when_the_wave_key_is_missing(): void
    {
        config(['app.wave_key' => '']);
        Http::fake();
        $this->mock(NotificationService::class)->shouldReceive('notifyAmbassadorOfFailedPayout')->once();
        $payout = $this->createPendingPayoutWithEarnings(500);

        $this->waveService()->pay($payout);

        Http::assertNothingSent();
        $this->assertSame(AmbassadorPayoutStatus::Failed, $payout->fresh()->status);
    }

    public function test_a_checkout_session_is_created_with_the_encoded_client_reference(): void
    {
        Http::fake(['api.wave.com/v1/checkout/sessions' => Http::response(['wave_launch_url' => 'https://pay.wave.com/c/1'])]);

        $paymentResponse = $this->waveService()->createCheckoutSession([
            'amount' => 5000,
            'client_reference' => ['type' => 'booking', 'id' => 12],
            'error_url' => 'https://example.test/error',
            'success_url' => 'https://example.test/success',
        ]);

        $this->assertTrue($paymentResponse->isOK());
        $this->assertSame('https://pay.wave.com/c/1', $paymentResponse->waveLaunchUrl());
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-wave-key')
            && $request['amount'] === '5000'
            && $request['currency'] === 'XOF'
            && $request['client_reference'] === json_encode(['type' => 'booking', 'id' => 12]));
    }

    public function test_a_checkout_session_with_incomplete_data_is_refused_before_calling_wave(): void
    {
        Http::fake();

        try {
            $this->waveService()->createCheckoutSession(['amount' => 5000]);
            $this->fail('An InvalidArgumentException was expected.');
        } catch (InvalidArgumentException) {
            Http::assertNothingSent();
        }
    }

    public function test_a_refund_by_checkout_session_id_calls_the_refund_endpoint_directly(): void
    {
        Http::fake(['api.wave.com/v1/checkout/sessions/cos-1/refund' => Http::response([])]);

        $this->assertNull($this->waveService()->refundTransaction('cos-1'));

        Http::assertSentCount(1);
    }

    public function test_a_refund_by_transaction_id_first_looks_up_the_checkout_session(): void
    {
        Http::fake([
            'api.wave.com/v1/checkout/sessions?transaction_id=TX-1' => Http::response(['id' => 'cos-9']),
            'api.wave.com/v1/checkout/sessions/cos-9/refund' => Http::response([]),
        ]);

        $this->assertNull($this->waveService()->refundTransaction('TX-1'));

        Http::assertSentCount(2);
    }

    public function test_a_refused_refund_returns_the_failure_reason(): void
    {
        Http::fake(['api.wave.com/v1/checkout/sessions/cos-1/refund' => Http::response(['message' => 'Already refunded'], 400)]);

        $this->assertNotNull($this->waveService()->refundTransaction('cos-1'));
    }

    public function test_the_balance_and_latest_transactions_are_read_from_wave(): void
    {
        Http::fake([
            'api.wave.com/v1/balance' => Http::response(['amount' => 125000]),
            'api.wave.com/v1/transactions' => Http::response(['items' => [['id' => 'TX-1']]]),
        ]);

        $this->assertSame(125000, $this->waveService()->getBalance());
        $this->assertSame([['id' => 'TX-1']], $this->waveService()->getLatestTransactions());
    }

    public function test_the_transaction_id_of_a_checkout_session_is_null_when_wave_fails(): void
    {
        Http::fake(['api.wave.com/v1/checkout/sessions/cos-1' => Http::response([], 500)]);

        $this->assertNull($this->waveService()->findTransactionIdOfCheckoutSession('cos-1'));
    }

    #[DataProvider('nonProductionEnvironmentProvider')]
    public function test_no_refund_is_sent_outside_production(string $environment): void
    {
        $this->app['env'] = $environment;
        Http::fake();

        $this->assertNull($this->waveService()->refundTransaction('cos-1'));
        $this->assertNull($this->waveService()->refundTransaction('TX-1'));

        Http::assertNothingSent();
    }

    public function test_no_refund_is_sent_when_the_wave_key_is_missing(): void
    {
        config(['app.wave_key' => '']);
        Http::fake();

        $this->assertNotNull($this->waveService()->refundTransaction('cos-1'));

        Http::assertNothingSent();
    }

    public function test_the_ambassador_is_notified_once_when_the_payout_is_settled(): void
    {
        Http::fake(['api.wave.com/v1/payout' => Http::response(['id' => 'pt-123', 'status' => 'succeeded'])]);
        $payout = $this->createPendingPayoutWithEarnings(5000);
        $this->mock(NotificationService::class)->shouldReceive('notifyAmbassadorOfSuccessfulPayout')->once();

        $this->waveService()->pay($payout);
        $this->waveService()->pay($payout);
    }

    public function test_only_the_first_failed_attempt_is_announced_to_the_ambassador(): void
    {
        Http::fake([
            'api.wave.com/v1/payout' => Http::response(['message' => 'boom'], 500),
            'api.wave.com/v1/payouts/search*' => Http::response(['result' => []]),
        ]);
        $payout = $this->createPendingPayoutWithEarnings(5000);
        $this->mock(NotificationService::class)->shouldReceive('notifyAmbassadorOfFailedPayout')->once();

        $this->waveService()->pay($payout);
        $this->waveService()->pay($payout);

        $this->assertSame(2, $payout->fresh()->attempts_count);
    }

    public function test_a_notification_failure_never_undoes_a_settled_payout(): void
    {
        Http::fake(['api.wave.com/v1/payout' => Http::response(['id' => 'pt-123', 'status' => 'succeeded'])]);
        $payout = $this->createPendingPayoutWithEarnings(5000);
        $this->mock(NotificationService::class)
            ->shouldReceive('notifyAmbassadorOfSuccessfulPayout')
            ->andThrow(new \RuntimeException('sms provider down'));

        $this->waveService()->pay($payout);

        $this->assertSame(AmbassadorPayoutStatus::Success, $payout->fresh()->status);
    }
}
