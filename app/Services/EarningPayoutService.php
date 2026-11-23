<?php

namespace App\Services;

use App\Enums\AmbassadorEarningStatus;
use App\Enums\AmbassadorPayoutStatus;
use App\Models\Ambassador;
use App\Models\AmbassadorEarning;
use App\Models\AmbassadorPayout;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Records what the company owes an ambassador: groups the approved earnings internally under an
 * AmbassadorPayout reference (no Wave call, so they can never be grouped twice). Sending that reference to
 * Wave is the job of WaveService.
 */
class EarningPayoutService
{
    public const string PAYMENT_REASON = 'Commissions Ambassadeur Globe One';

    public const int MAX_PAYMENT_REASON_LENGTH = 255;

    /**
     * Groups every payable earning of the ambassador under a new pending payout. Returns null when nothing
     * is payable. A null or blank payment reason falls back to PAYMENT_REASON.
     *
     * @throws DomainException when the ambassador has not signed the current contract
     * @throws InvalidArgumentException when the payment reason is longer than MAX_PAYMENT_REASON_LENGTH
     * @throws RuntimeException|\Throwable when the earnings counted in the amount could not all be attached to the payout
     */
    public function createPayoutForPayableEarnings(Ambassador $ambassador, ?int $requestedByUserId = null, ?string $paymentReason = null): ?AmbassadorPayout
    {
        if (! $ambassador->hasSignedContract()) {
            throw new DomainException("L'ambassadeur {$ambassador->full_name} n'a pas signé son contrat : aucun paiement possible.");
        }

        $paymentReason = trim((string) $paymentReason) !== '' ? trim($paymentReason) : self::PAYMENT_REASON;

        if (mb_strlen($paymentReason) > self::MAX_PAYMENT_REASON_LENGTH) {
            throw new InvalidArgumentException('The payment reason cannot exceed '.self::MAX_PAYMENT_REASON_LENGTH.' characters.');
        }

        return DB::transaction(function () use ($ambassador, $requestedByUserId, $paymentReason): ?AmbassadorPayout {
            $payableEarnings = $this->payableEarnings($ambassador, lockForUpdate: true);

            $payableAmount = $this->sumPayableAmount($payableEarnings);

            if ($payableAmount === 0) {
                return null;
            }

            $payout = AmbassadorPayout::query()->create([
                'ambassador_id' => $ambassador->id,
                'amount' => $payableAmount,
                'wave_phone_number' => $ambassador->payout_phone_number,
                'payment_reason' => $paymentReason,
                'status' => AmbassadorPayoutStatus::Pending,
                'idempotency_key' => (string) Str::uuid(),
                'requested_by_user_id' => $requestedByUserId,
            ]);

            $attachedEarningsCount = AmbassadorEarning::query()
                ->whereKey($payableEarnings->modelKeys())
                ->update(['ambassador_payout_id' => $payout->id]);

            if ($attachedEarningsCount !== $payableEarnings->count()) {
                throw new RuntimeException('Not every payable earning was attached to the payout.');
            }

            return $payout;
        });
    }

    /**
     * The single source of truth for what is still owed to the ambassador: approved earnings not yet attached
     * to any payout. Paid, pending and cancelled ones are left out, and so is any earning that already has a
     * payout reference, even when that payout failed (a failed payout is retried as a whole, its earnings must
     * never be counted twice). Every loaded earning is re-checked instead of trusting the query alone.
     *
     * @param  bool  $lockForUpdate  lock the rows until the surrounding transaction ends; only meaningful inside one
     */
    public function payableEarnings(Ambassador $ambassador, bool $lockForUpdate = false): Collection
    {
        $payableEarningsQuery = AmbassadorEarning::query()
            ->where('ambassador_id', $ambassador->id)
            ->payable();

        if ($lockForUpdate) {
            $payableEarningsQuery->lockForUpdate();
        }

        return $payableEarningsQuery->get()->filter(
            fn (AmbassadorEarning $earning): bool => $earning->ambassador_id === $ambassador->id
                && $earning->status === AmbassadorEarningStatus::Approved
                && $earning->ambassador_payout_id === null
        )->values();
    }

    /**
     * The amount (in FCFA) the company currently owes the ambassador, summed from payableEarnings().
     * This figure becomes a debt, so an earning with an invalid amount stops everything.
     *
     * @throws InvalidArgumentException when a payable earning has an invalid amount
     */
    public function calculatePayableAmount(Ambassador $ambassador): int
    {
        return $this->sumPayableAmount($this->payableEarnings($ambassador));
    }

    /**
     * Sums the given earnings, so a payout is always worth exactly the earnings attached to it.
     *
     * @param  Collection<int, AmbassadorEarning>  $payableEarnings
     *
     * @throws InvalidArgumentException when an earning has an invalid amount
     */
    private function sumPayableAmount(Collection $payableEarnings): int
    {
        $payableAmount = 0;

        foreach ($payableEarnings as $earning) {
            if (! is_int($earning->amount) || $earning->amount <= 0) {
                throw new InvalidArgumentException("Earning {$earning->id} has an invalid amount and cannot be paid.");
            }

            $payableAmount += $earning->amount;
        }

        return $payableAmount;
    }
}
