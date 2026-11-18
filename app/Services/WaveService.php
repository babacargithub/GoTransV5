<?php

namespace App\Services;

use App\Enums\AmbassadorEarningStatus;
use App\Enums\AmbassadorPayoutStatus;
use App\Http\Resources\PaymentResponseResource;
use App\Jobs\RecordAmbassadorPayoutInCaisseJob;
use App\Manager\TicketManager;
use App\Models\AmbassadorPayout;
use App\Models\Booking;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Everything that talks to Wave: checkout sessions, webhook signature check, balance, refunds and ambassador
 * payouts. A payout is paid as many times as needed: its idempotency key makes every retry safe. What is owed
 * is recorded beforehand by EarningPayoutService.
 */
class WaveService
{
    private const API_BASE_URL = 'https://api.wave.com/v1';

    private const WEBHOOK_SIGNATURE_TOLERANCE_IN_SECONDS = 300;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * @return array{Authorization: string, Content-Type: string, Accept: string}
     */
    public function headers(): array
    {
        return [
            'Authorization' => 'Bearer '.config('app.wave_key'),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    public function getRedirectEndpoint(): string
    {
        return is_request_for_gp_customers() ? 'https://globaltransports.app' : 'https://globeone.site';
    }

    /**
     * @throws ConnectionException
     */
    public function createCheckoutSessionForBooking(Booking $booking, TicketManager $ticketManager): PaymentResponseResource
    {
        try {
            $requestBody = [
                'amount' => ''.$ticketManager->calculateTicketPriceForBooking($booking, 'wave'),
                'currency' => 'XOF',
                'client_reference' => [
                    'type' => 'booking',
                    'id' => $booking->id,
                    'phone_number' => $booking->customer->phone_number,
                    'client' => $booking->customer->full_name,
                ],
                'error_url' => $this->getRedirectEndpoint().'/#/paiement/error/'.$booking->id,
                'success_url' => $this->getRedirectEndpoint().'/#/paiement/success/'.$booking->id,
            ];

            return $this->createCheckoutSession($requestBody);
        } catch (RequestException $e) {
            $this->logger->error($e->getMessage());

            return new PaymentResponseResource($e->response, paymentMethod: 'wave');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException
     * @throws ConnectionException
     */
    public function createCheckoutSession(array $data): PaymentResponseResource
    {
        if (! isset($data['amount']) || ! isset($data['client_reference']) || ! is_array($data['client_reference']) ||
            ! isset($data['error_url']) || ! isset($data['success_url'])) {
            throw new InvalidArgumentException('Invalid data for wave paiement');
        }

        $response = Http::withHeaders($this->headers())
            ->asJson()
            ->post(self::API_BASE_URL.'/checkout/sessions', [
                'amount' => ''.$data['amount'],
                'currency' => 'XOF',
                'client_reference' => json_encode($data['client_reference']),
                'error_url' => $data['error_url'],
                'success_url' => $data['success_url'],
            ]);

        try {
            $response->throw();

            return new PaymentResponseResource($response, paymentMethod: 'wave');
        } catch (RequestException $e) {
            $this->logger->error($e->getMessage());

            return new PaymentResponseResource($e->response, paymentMethod: 'wave');
        }
    }

    /**
     * @return array{0: string, 1: bool} The raw request body and whether Wave's signature is valid.
     */
    public function verifyWebhookSignature(Request $request): array
    {
        $rawBody = $request->getContent();
        $rejectionReason = $this->findWebhookSignatureRejectionReason($request, $rawBody);

        if ($rejectionReason !== null) {
            $this->logger->error('Wave signature rejected: '.$rejectionReason);
        }

        return [$rawBody, $rejectionReason === null];
    }

    /**
     * Header format documented by Wave: "Wave-Signature: t=<unix timestamp>,v1=<hmac>[,v1=<hmac>...]"
     * where each hmac is HMAC-SHA256(secret, timestamp . rawBody). Several v1 values appear during secret rotation.
     *
     * @return string|null Why the request is rejected, or null when the signature is valid.
     */
    private function findWebhookSignatureRejectionReason(Request $request, string $rawBody): ?string
    {
        $webhookSecret = (string) config('app.wave_webhook_secret');
        if ($webhookSecret === '') {
            // An empty key would let anyone forge a valid HMAC.
            return "config('app.wave_webhook_secret') is empty (check WAVE_WEBHOOK_SECRET and refresh the config cache / PHP-FPM)";
        }

        $signatureHeader = (string) $request->header('Wave-Signature');
        if ($signatureHeader === '') {
            return 'Wave-Signature header is missing (the Wave webhook is probably configured with a shared secret '
                .'instead of a signing secret)';
        }

        $timestamp = null;
        $receivedSignatures = [];
        foreach (explode(',', $signatureHeader) as $headerPart) {
            [$partName, $partValue] = array_pad(explode('=', trim($headerPart), 2), 2, '');
            if ($partName === 't') {
                $timestamp = $partValue;
            } elseif ($partName === 'v1' && $partValue !== '') {
                $receivedSignatures[] = $partValue;
            }
        }

        if ($timestamp === null || ! ctype_digit($timestamp) || $receivedSignatures === []) {
            return 'Wave-Signature header is malformed (expected t=<timestamp>,v1=<signature>)';
        }

        $secondsSinceWaveSentRequest = abs(now()->getTimestamp() - (int) $timestamp);
        if ($secondsSinceWaveSentRequest > self::WEBHOOK_SIGNATURE_TOLERANCE_IN_SECONDS) {
            return 'timestamp is '.$secondsSinceWaveSentRequest.'s away from server time, more than the '
                .self::WEBHOOK_SIGNATURE_TOLERANCE_IN_SECONDS.'s tolerance (replayed request or server clock drift)';
        }

        $expectedSignature = hash_hmac('sha256', $timestamp.$rawBody, $webhookSecret);
        foreach ($receivedSignatures as $receivedSignature) {
            if (hash_equals($expectedSignature, $receivedSignature)) {
                return null;
            }
        }

        return 'no v1 signature matches the HMAC computed from the configured secret (secret length '
            .strlen($webhookSecret).', body length '.strlen($rawBody).'); the secret differs from the one '
            .'in the Wave dashboard, or a proxy altered the body';
    }

    /**
     * @return array<int, mixed>
     *
     * @throws ConnectionException
     */
    public function getLatestTransactions(): array
    {
        $response = Http::withHeaders($this->headers())
            ->asJson()
            ->get(self::API_BASE_URL.'/transactions')
            ->json();

        return $response['items'] ?? [];
    }

    /**
     * The Wave transaction id (the reference listed in the Wave app) of a checkout session, or null when Wave
     * cannot be reached or does not return one.
     */
    public function findTransactionIdOfCheckoutSession(string $checkoutSessionId): ?string
    {
        try {
            $response = Http::withHeaders($this->headers())
                ->asJson()
                ->get(self::API_BASE_URL.'/checkout/sessions/'.$checkoutSessionId);
        } catch (ConnectionException) {
            return null;
        }

        return $response->successful() ? $response->json('transaction_id') : null;
    }

    /**
     * Refunds a checkout session. A reference that does not start with "cos" is a transaction id, so its
     * checkout session id is looked up first.
     *
     * Real money only moves in production: in any other environment (local, testing, staging...) Wave is never
     * called and the refund is reported as accepted.
     *
     * @return string|null null when the refund was accepted, otherwise the reason it was not
     */
    public function refundTransaction(string $transactionOrCheckoutId): ?string
    {
        if (! app()->isProduction()) {
            $this->logger->warning("Wave refund of {$transactionOrCheckoutId} simulated: no real money is sent outside production.");

            return null;
        }

        if ((string) config('app.wave_key') === '') {
            return "config('app.wave_key') is empty";
        }

        $checkoutSessionId = $transactionOrCheckoutId;

        if (! str_starts_with($checkoutSessionId, 'cos')) {
            try {
                $response = Http::withHeaders($this->headers())
                    ->asJson()
                    ->get(self::API_BASE_URL.'/checkout/sessions?transaction_id='.$checkoutSessionId);
                $response->throw();
                $checkoutSessionId = $response->json()['id'];
            } catch (RequestException $e) {
                return $e->getMessage();
            }
        }

        try {
            Http::withHeaders($this->headers())
                ->asJson()
                ->send('POST', self::API_BASE_URL.'/checkout/sessions/'.$checkoutSessionId.'/refund')
                ->throw();

            return null;
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * @throws ConnectionException
     * @throws RequestException
     */
    public function getBalance(): int
    {
        $response = Http::withHeaders($this->headers())
            ->asJson()
            ->get(self::API_BASE_URL.'/balance');
        $response->throw();

        return $response->json()['amount'] ?? 0;
    }

    /**
     * Sends an ambassador payout to the Wave payout API, using the payout's idempotency key so Wave ignores a
     * request it already received. Real money only moves in production: in any other environment (local,
     * testing, staging...) Wave is never called and a simulated settled result is returned instead.
     *
     * @return array{transaction_id: ?string, is_settled: bool, failure_reason: ?string}
     */
    public function sendPayout(AmbassadorPayout $payout): array
    {
        if (! app()->isProduction()) {
            $this->logger->warning("Wave payout {$payout->id} simulated: no real money is sent outside production.");

            return ['transaction_id' => 'simulated-'.$payout->idempotency_key, 'is_settled' => true, 'failure_reason' => null];
        }

        if ((string) config('app.wave_key') === '') {
            return ['transaction_id' => null, 'is_settled' => false, 'failure_reason' => "config('app.wave_key') is empty"];
        }

        try {
            $response = Http::withHeaders($this->headers() + ['Idempotency-Key' => $payout->idempotency_key])
                ->asJson()
                ->timeout(20)
                ->post(self::API_BASE_URL.'/payout', [
                    'currency' => 'XOF',
                    'receive_amount' => (string) $payout->amount,
                    'mobile' => '+221'.$payout->wave_phone_number,
                    'client_reference' => $this->payoutClientReference($payout),
                    'payment_reason' => $payout->payment_reason,
                ]);
        } catch (ConnectionException $connectionException) {
            return ['transaction_id' => null, 'is_settled' => false, 'failure_reason' => 'Wave injoignable : '.$connectionException->getMessage()];
        }

        if ($response->failed()) {
            $waveMessage = $response->json('message') ?? $response->body();

            return ['transaction_id' => null, 'is_settled' => false, 'failure_reason' => "Wave HTTP {$response->status()} : {$waveMessage}"];
        }

        $waveStatus = $response->json('status');

        return [
            'transaction_id' => $response->json('id'),
            'is_settled' => $waveStatus === 'succeeded',
            'failure_reason' => $waveStatus === 'failed' ? $this->describePayoutError($response->json('payout_error')) : null,
        ];
    }

    /**
     * Asks Wave what became of a payout: by its Wave id when it has one, otherwise by the client reference it
     * was sent with (the case when the first call timed out and Wave's answer never reached us). Nothing is
     * changed locally. Outside production there is no real Wave payout to ask about, so the state is unreachable.
     *
     * state is one of: succeeded, processing, failed (also covers reversed), not_found (Wave never received
     * it, so resending is safe) or unreachable (Wave could not be asked or answered something unexpected:
     * never act on it).
     *
     * @return array{state: string, transaction_id: ?string, failure_reason: ?string}
     */
    public function fetchPayout(AmbassadorPayout $payout): array
    {
        $unreachable = ['state' => 'unreachable', 'transaction_id' => null, 'failure_reason' => null];

        if (! app()->isProduction() || (string) config('app.wave_key') === '') {
            return $unreachable;
        }

        $isLookedUpByWaveId = $payout->provider_payout_id !== null;

        try {
            $request = Http::withHeaders($this->headers())->asJson()->timeout(20);
            $response = $isLookedUpByWaveId
                ? $request->get(self::API_BASE_URL.'/payout/'.$payout->provider_payout_id)
                : $request->get(self::API_BASE_URL.'/payouts/search', ['client_reference' => $this->payoutClientReference($payout)]);
        } catch (ConnectionException $connectionException) {
            $this->logger->warning("Wave payout {$payout->id} could not be fetched: {$connectionException->getMessage()}");

            return $unreachable;
        }

        if ($isLookedUpByWaveId && ($response->status() === 404 || $response->json('code') === 'not-found')) {
            return ['state' => 'not_found', 'transaction_id' => null, 'failure_reason' => null];
        }

        if ($response->failed()) {
            return $unreachable;
        }

        $wavePayout = $isLookedUpByWaveId
            ? $response->json()
            : collect($response->json('result', []))->firstWhere('status', 'succeeded') ?? collect($response->json('result', []))->first();

        if ($wavePayout === null) {
            return ['state' => 'not_found', 'transaction_id' => null, 'failure_reason' => null];
        }

        $transactionId = $wavePayout['id'] ?? null;

        return match ($wavePayout['status'] ?? null) {
            'succeeded' => ['state' => 'succeeded', 'transaction_id' => $transactionId, 'failure_reason' => null],
            'processing' => ['state' => 'processing', 'transaction_id' => $transactionId, 'failure_reason' => null],
            'failed' => ['state' => 'failed', 'transaction_id' => $transactionId, 'failure_reason' => $this->describePayoutError($wavePayout['payout_error'] ?? null)],
            'reversed' => ['state' => 'failed', 'transaction_id' => $transactionId, 'failure_reason' => 'Paiement annulé par Wave (reversed)'],
            default => $unreachable,
        };
    }

    /**
     * Brings an unconfirmed payout in line with what Wave reports. A succeeded payout is settled exactly like
     * a direct success, a failed one is marked failed, a processing one keeps waiting (with its Wave id). A
     * payout Wave never received, or one Wave could not be asked about, is left untouched.
     */
    public function reconcilePayout(AmbassadorPayout $payout): AmbassadorPayout
    {
        $payout->refresh();

        if ($payout->status === AmbassadorPayoutStatus::Success) {
            return $payout;
        }

        $wavePayout = $this->fetchPayout($payout);

        if ($wavePayout['transaction_id'] !== null) {
            $payout->update(['provider_payout_id' => $wavePayout['transaction_id']]);
        }

        match ($wavePayout['state']) {
            'succeeded' => $this->settlePayoutAndNotify($payout),
            'processing' => $payout->update(['status' => AmbassadorPayoutStatus::Pending, 'failure_reason' => null]),
            'failed' => $payout->update(['status' => AmbassadorPayoutStatus::Failed, 'failure_reason' => $wavePayout['failure_reason']]),
            default => null,
        };

        return $payout;
    }

    /**
     * Reconciles every payout Wave may know about without us having confirmed it: the ones Wave accepted but
     * did not settle yet, and the ones we attempted without ever getting a usable answer.
     *
     * @return int number of payouts that were checked against Wave
     */
    public function reconcileUnconfirmedPayouts(): int
    {
        $checkedPayoutsCount = 0;

        AmbassadorPayout::query()
            ->where('status', '!=', AmbassadorPayoutStatus::Success)
            ->where('attempts_count', '>', 0)
            ->each(function (AmbassadorPayout $payout) use (&$checkedPayoutsCount): void {
                $this->reconcilePayout($payout);
                $checkedPayoutsCount++;
            });

        return $checkedPayoutsCount;
    }

    private function payoutClientReference(AmbassadorPayout $payout): string
    {
        return "ambassador-payout-{$payout->id}";
    }

    /**
     * @param  array{error_code?: string, error_message?: string}|null  $payoutError
     */
    private function describePayoutError(?array $payoutError): string
    {
        if ($payoutError === null) {
            return 'Paiement refusé par Wave';
        }

        return trim(($payoutError['error_code'] ?? 'unknown-error').' : '.($payoutError['error_message'] ?? ''), ' :');
    }

    private function settlePayoutAndNotify(AmbassadorPayout $payout): void
    {
        $this->markAsSucceeded($payout);

        $this->notifyAmbassadorWithoutRisk(fn () => $this->notificationService->notifyAmbassadorOfSuccessfulPayout($payout));
    }

    /**
     * Sends the payout to Wave. Safe to call again on a failed, pending or unconfirmed payout: the same
     * idempotency key is reused, and a settled payout or one past MAX_ATTEMPTS is returned untouched.
     */
    public function pay(AmbassadorPayout $payout): AmbassadorPayout
    {
        $payout->refresh();

        if (! $payout->canBeSent()) {
            return $payout;
        }

        // An earlier attempt may have reached Wave although we never got its answer: ask before sending again.
        if ($payout->attempts_count > 0 && ! $this->reconcilePayout($payout)->canBeSent()) {
            return $payout;
        }

        $payout->update([
            'status' => AmbassadorPayoutStatus::Pending,
            'attempts_count' => $payout->attempts_count + 1,
            'last_attempted_at' => now(),
        ]);

        $waveResult = $this->sendPayout($payout);
        if ($waveResult['transaction_id'] !== null) {
            $payout->update(['provider_payout_id' => $waveResult['transaction_id']]);
        }

        if ($waveResult['is_settled']) {
            $this->settlePayoutAndNotify($payout);
        } elseif ($waveResult['failure_reason'] !== null) {
            $payout->update([
                'status' => AmbassadorPayoutStatus::Failed,
                'failure_reason' => $waveResult['failure_reason'],
            ]);

            // Only the first failure is announced: the retries that follow must not flood the ambassador.
            if ($payout->attempts_count === 1) {
                $this->notifyAmbassadorWithoutRisk(fn () => $this->notificationService->notifyAmbassadorOfFailedPayout($payout));
            }
        }

        return $payout;
    }

    /**
     * Ambassadors are only texted about payouts that really moved money, so like the Wave call itself this
     * only happens in production (a simulated payout must never message a real person). An SMS problem must
     * never affect a payout that is already recorded: it is only logged.
     */
    private function notifyAmbassadorWithoutRisk(callable $sendNotification): void
    {
        if (! app()->isProduction()) {
            return;
        }

        try {
            $sendNotification();
        } catch (Throwable $throwable) {
            $this->logger->error("Ambassador payout notification failed: {$throwable->getMessage()}");
        }
    }

    /**
     * Retries every failed or never-sent payout that still has attempts left.
     *
     * @return int number of payouts that were sent again
     */
    public function retryUnsettledPayouts(): int
    {
        // Settle what Wave already processed first, so only payouts Wave really lacks are sent again.
        $this->reconcileUnconfirmedPayouts();

        $retriedPayoutsCount = 0;

        AmbassadorPayout::query()
            ->where(function ($unsettledPayouts): void {
                $unsettledPayouts->where('status', AmbassadorPayoutStatus::Failed)
                    ->orWhere(fn ($neverSentPayouts) => $neverSentPayouts
                        ->where('status', AmbassadorPayoutStatus::Pending)
                        ->whereNull('provider_payout_id'));
            })
            ->where('attempts_count', '<', AmbassadorPayout::MAX_ATTEMPTS)
            ->each(function (AmbassadorPayout $payout) use (&$retriedPayoutsCount): void {
                $this->pay($payout);
                $retriedPayoutsCount++;
            });

        return $retriedPayoutsCount;
    }

    private function markAsSucceeded(AmbassadorPayout $payout): void
    {
        DB::transaction(function () use ($payout): void {
            $payout->update([
                'status' => AmbassadorPayoutStatus::Success,
                'failure_reason' => null,
                'paid_at' => now(),
            ]);

            $payout->earnings()->update([
                'status' => AmbassadorEarningStatus::Paid,
                'paid_at' => now(),
            ]);
        });

        // The manager's payout leaves the Wave caisse: recorded by a queued job that can never undo the payout.
        try {
            dispatch(new RecordAmbassadorPayoutInCaisseJob($payout->id))->afterCommit();
        } catch (Throwable $throwable) {
            $this->logger->error("Payout {$payout->id} could not be queued for caisse recording: {$throwable->getMessage()}");
        }
    }
}
