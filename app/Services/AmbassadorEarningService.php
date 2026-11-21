<?php

namespace App\Services;

use App\Enums\AmbassadorEarningStatus;
use App\Enums\AmbassadorEarningType;
use App\Enums\AmbassadorStatus;
use App\Jobs\RecordAmbassadorCommissionReserveJob;
use App\Jobs\ReleaseAmbassadorCommissionReserveJob;
use App\Models\Ambassador;
use App\Models\AmbassadorEarning;
use App\Models\AmbassadorRewardRule;
use App\Models\AppParams;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Depart;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Write side of the ambassador earnings ledger: creates lines, approves them once their waiting
 * period is over and cancels them when the ticket behind them is refunded or cancelled, and postpones them when the booking is transferred to another departure.
 *
 * Callers decide when a lifecycle event happens (ticket paid, booking cancelled, scheduler tick);
 * every method here is idempotent so a retried call can never pay twice. Reads live in
 * AmbassadorPerformanceService.
 */
class AmbassadorEarningService
{
    /**
     * Records the acquisition prime for a paid booking of a customer brought by an ambassador. Only the
     * first call per customer creates a line (a customer is acquired once); later bookings return it
     * unchanged. The line stays pending until 24h after the departure, then approveDueEarnings() releases it.
     * Returns null when the customer was not brought by an ambassador, when the booking has no departure
     * to date the approval from, or when no acquisition reward rule currently applies to the ambassador.
     */
    public function recordAcquisitionEarning(Booking $paidBooking): ?AmbassadorEarning
    {
        $customer = $paidBooking->customer;
        $depart = $paidBooking->depart;

        if ($customer === null || $customer->ambassador_id === null || $depart === null) {
            return null;
        }

        $acquisitionRule = AmbassadorRewardRule::findApplicableRule(AmbassadorEarningType::Acquisition, $customer->ambassador);

        if ($acquisitionRule === null) {
            return null;
        }

        $acquisitionEarning = AmbassadorEarning::query()->firstOrCreate(
            ['idempotency_key' => $this->acquisitionIdempotencyKey($customer)],
            [
                'ambassador_id' => $customer->ambassador_id,
                'type' => AmbassadorEarningType::Acquisition,
                'amount' => $acquisitionRule->amount,
                'status' => AmbassadorEarningStatus::Pending,
                'booking_id' => $paidBooking->id,
                'label' => "Nouveau client : {$customer->full_name} pour depart : {$depart->identifier(with_trajet_prefix: true)}",
                'approvable_at' => $depart->date->copy()->addDay(),
            ],
        );

        if ($acquisitionEarning->wasRecentlyCreated) {
            $this->dispatchAccountingOf(new RecordAmbassadorCommissionReserveJob($acquisitionEarning->id), $acquisitionEarning->id);
            $this->recordSponsorshipBonusIfThresholdReached($customer->ambassador);
        }

        return $acquisitionEarning;
    }

    /**
     * Rewards an ambassador whose link was used to book for a customer who already existed: a customer
     * the ambassador did not acquire themselves (that one is rewarded once, as an acquisition) and who is
     * not the ambassador. One line per paid booking, only while a reward rule for existing customers applies
     * to the ambassador. Returns null when nothing is due.
     */
    public function recordExistingCustomerBookingEarning(Booking $paidBooking): ?AmbassadorEarning
    {
        $customer = $paidBooking->customer;
        $depart = $paidBooking->depart;
        $referringAmbassador = $paidBooking->ambassador;

        if ($customer === null || $depart === null || $referringAmbassador === null) {
            return null;
        }

        $isCustomerAcquiredByReferringAmbassador = $customer->ambassador_id === $referringAmbassador->id;
        $isReferringAmbassadorTheCustomer = substr((string) $referringAmbassador->phone_number, -9) === substr((string) $customer->phone_number, -9);

        if ($isCustomerAcquiredByReferringAmbassador || $isReferringAmbassadorTheCustomer) {
            return null;
        }

        $existingCustomerRule = AmbassadorRewardRule::findApplicableRule(AmbassadorEarningType::ExistingCustomerBooking, $referringAmbassador);

        if ($existingCustomerRule === null) {
            return null;
        }

        $existingCustomerEarning = AmbassadorEarning::query()->firstOrCreate(
            ['idempotency_key' => "existing_booking:{$paidBooking->id}"],
            [
                'ambassador_id' => $referringAmbassador->id,
                'type' => AmbassadorEarningType::ExistingCustomerBooking,
                'amount' => $existingCustomerRule->amount,
                'status' => AmbassadorEarningStatus::Pending,
                'booking_id' => $paidBooking->id,
                'label' => "Client existant : {$customer->full_name} pour depart : {$depart->identifier(with_trajet_prefix: true)}",
                'approvable_at' => $depart->date->copy()->addDay(),
            ],
        );

        if ($existingCustomerEarning->wasRecentlyCreated) {
            $this->dispatchAccountingOf(new RecordAmbassadorCommissionReserveJob($existingCustomerEarning->id), $existingCustomerEarning->id);
        }

        return $existingCustomerEarning;
    }

    /**
     * Rewards the sponsor, once, when the sponsored ambassador has acquired enough customers (cancelled
     * acquisitions do not count) and a sponsorship reward rule applies to the sponsor. The bonus is due
     * immediately, so the next approval run releases it.
     */
    public function recordSponsorshipBonusIfThresholdReached(Ambassador $sponsoredAmbassador): ?AmbassadorEarning
    {
        if ($sponsoredAmbassador->sponsor_ambassador_id === null) {
            return null;
        }

        $acquiredCustomersCount = $sponsoredAmbassador->earnings()
            ->where('type', AmbassadorEarningType::Acquisition)
            ->notCancelled()
            ->count();

        if ($acquiredCustomersCount < $this->appParams()->getAmbassadorSponsorshipThreshold()) {
            return null;
        }

        $sponsorshipRule = AmbassadorRewardRule::findApplicableRule(AmbassadorEarningType::SponsorshipBonus, $sponsoredAmbassador->sponsor);

        if ($sponsorshipRule === null) {
            return null;
        }

        $sponsorshipBonus = AmbassadorEarning::query()->firstOrCreate(
            ['idempotency_key' => "sponsorship:{$sponsoredAmbassador->id}"],
            [
                'ambassador_id' => $sponsoredAmbassador->sponsor_ambassador_id,
                'type' => AmbassadorEarningType::SponsorshipBonus,
                'amount' => $sponsorshipRule->amount,
                'status' => AmbassadorEarningStatus::Pending,
                'source_ambassador_id' => $sponsoredAmbassador->id,
                'label' => "Parrainage : {$sponsoredAmbassador->full_name}",
                'approvable_at' => now(),
            ],
        );

        if ($sponsorshipBonus->wasRecentlyCreated) {
            $this->dispatchAccountingOf(new RecordAmbassadorCommissionReserveJob($sponsorshipBonus->id), $sponsorshipBonus->id);
        }

        return $sponsorshipBonus;
    }

    /**
     * Records, on behalf of the back office, a promotion the ambassador did (an advertisement shared, a video
     * published): one pending line at the amount of the reward rule that currently applies to them. The
     * submission token makes a double click or a replayed request create the line only once.
     *
     * @throws DomainException when the type is not recorded by hand, the ambassador is not active or no rule applies
     */
    public function recordPromotionReward(
        Ambassador $ambassador,
        AmbassadorEarningType $promotionType,
        string $submissionToken,
        ?string $note,
        ?string $proofUrl,
        ?User $recordedBy,
    ): AmbassadorEarning {
        if (! $promotionType->isRecordedManually()) {
            throw new DomainException("« {$promotionType->label()} » ne s'enregistre pas manuellement.");
        }

        if ($ambassador->status !== AmbassadorStatus::Active) {
            throw new DomainException("L'ambassadeur {$ambassador->full_name} n'est pas actif.");
        }

        $promotionRule = AmbassadorRewardRule::findApplicableRule($promotionType, $ambassador);

        if ($promotionRule === null) {
            throw new DomainException("Aucune règle de récompense « {$promotionType->label()} » ne s'applique à {$ambassador->full_name}.");
        }

        $promotionEarning = AmbassadorEarning::query()->firstOrCreate(
            ['idempotency_key' => "promotion:{$submissionToken}"],
            [
                'ambassador_id' => $ambassador->id,
                'type' => $promotionType,
                'amount' => $promotionRule->amount,
                'status' => AmbassadorEarningStatus::Pending,
                'label' => filled($note) ? "{$promotionType->label()} : {$note}" : $promotionType->label(),
                'proof_url' => $proofUrl,
                'recorded_by_user_id' => $recordedBy?->id,
                'approvable_at' => now(),
            ],
        );

        if ($promotionEarning->wasRecentlyCreated) {
            $this->dispatchAccountingOf(new RecordAmbassadorCommissionReserveJob($promotionEarning->id), $promotionEarning->id);
        }

        return $promotionEarning;
    }

    /**
     * Approves every pending line whose waiting period is over. Meant to run on a schedule.
     *
     * @return int number of lines approved
     */
    public function approveDueEarnings(): int
    {
        return AmbassadorEarning::query()
            ->readyForApproval()
            ->update([
                'status' => AmbassadorEarningStatus::Approved,
                'approved_at' => now(),
            ]);
    }

    /**
     * Approves by hand, before their waiting period is over, some pending lines of one ambassador, and keeps
     * who did it and why. Only pending lines of that ambassador are touched: lines that are cancelled, already
     * approved or paid, or that belong to someone else, are skipped. The status is checked in the update itself,
     * so a replayed or concurrent call approves each line once.
     *
     * @param  array<int, int>  $earningIds
     * @return int number of lines approved
     */
    public function approveEarningsEarly(Ambassador $ambassador, array $earningIds, User $approvedBy, string $reason): int
    {
        if ($earningIds === []) {
            return 0;
        }

        return AmbassadorEarning::query()
            ->where('ambassador_id', $ambassador->id)
            ->where('status', AmbassadorEarningStatus::Pending)
            ->whereIn('id', $earningIds)
            ->update([
                'status' => AmbassadorEarningStatus::Approved,
                'approved_at' => now(),
                'approved_by_user_id' => $approvedBy->id,
                'early_approval_reason' => $reason,
            ]);
    }

    /**
     * Voids the acquisition line that stems from a booking that was refunded or cancelled.
     * Only lines still owed (pending or approved, not yet in a payout) can be voided: money already sent
     * stays paid.
     *
     * @return int number of lines cancelled
     */
    public function cancelEarningsOfBooking(Booking $booking): int
    {
        $owedEarningsQuery = AmbassadorEarning::query()
            ->where('booking_id', $booking->id)
            ->whereIn('status', [AmbassadorEarningStatus::Pending, AmbassadorEarningStatus::Approved])
            ->whereNull('ambassador_payout_id');

        $cancelledEarningIds = (clone $owedEarningsQuery)->pluck('id');

        $cancelledEarningsCount = $owedEarningsQuery->update([
            'status' => AmbassadorEarningStatus::Cancelled,
            'cancelled_at' => now(),
        ]);

        $cancelledEarningIds->each(
            fn (int $cancelledEarningId) => $this->dispatchAccountingOf(new ReleaseAmbassadorCommissionReserveJob($cancelledEarningId), $cancelledEarningId),
        );

        return $cancelledEarningsCount;
    }

    /**
     * What the ambassador commissions cost one départ: the acquisition and existing-customer commissions of its bookings, counted as
     * soon as they are earned (pending included) and left out once cancelled. Computed live, never stored, like
     * the départ's ticket sales. Sponsorship bonuses belong to no départ and are not included.
     */
    public function totalCommissionsOfDepart(Depart $depart): int
    {
        return (int) AmbassadorEarning::query()
            ->whereIn('type', [AmbassadorEarningType::Acquisition, AmbassadorEarningType::ExistingCustomerBooking])
            ->notCancelled()
            ->whereHas('booking', fn ($bookingQuery) => $bookingQuery->where('depart_id', $depart->id))
            ->sum('amount');
    }

    /**
     * A transferred booking keeps its earning: the waiting period simply restarts from the new departure
     * (24h after its date). Only pending lines are moved; an already approved line stays approved.
     * Call it after the booking has been re-associated with its new departure.
     *
     * @return int number of lines rescheduled
     */
    public function rescheduleEarningsOfTransferredBooking(Booking $transferredBooking): int
    {
        return AmbassadorEarning::query()
            ->where('booking_id', $transferredBooking->id)
            ->where('status', AmbassadorEarningStatus::Pending)
            ->update(['approvable_at' => $transferredBooking->depart->date->copy()->addDay()]);
    }

    /**
     * Accounting follows the ledger but must never fail or delay the sale, cancellation or approval it follows:
     * a problem is logged, and ambassador:record-missing-commission-entries can catch up later.
     */
    private function dispatchAccountingOf(object $accountingJob, int $earningId): void
    {
        try {
            dispatch($accountingJob)->afterCommit();
        } catch (Throwable $throwable) {
            Log::error("Ambassador commission accounting could not be queued for earning {$earningId}: {$throwable->getMessage()}");
        }
    }

    private function acquisitionIdempotencyKey(Customer $customer): string
    {
        return "acquisition:{$customer->id}";
    }

    private function appParams(): AppParams
    {
        return AppParams::query()->first() ?? new AppParams(['data' => []]);
    }
}
