<?php

namespace App\Services;

use App\Enums\AmbassadorEarningStatus;
use App\Enums\AmbassadorEarningType;
use App\Jobs\RecordAmbassadorCommissionReserveJob;
use App\Jobs\ReleaseAmbassadorCommissionReserveJob;
use App\Models\Ambassador;
use App\Models\AmbassadorEarning;
use App\Models\AppParams;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Depart;
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
     * Returns null when the customer was not brought by an ambassador, or when the booking has no departure
     * to date the approval from.
     */
    public function recordAcquisitionEarning(Booking $paidBooking): ?AmbassadorEarning
    {
        $customer = $paidBooking->customer;
        $depart = $paidBooking->depart;

        if ($customer === null || $customer->ambassador_id === null || $depart === null) {
            return null;
        }

        $acquisitionEarning = AmbassadorEarning::query()->firstOrCreate(
            ['idempotency_key' => $this->acquisitionIdempotencyKey($customer)],
            [
                'ambassador_id' => $customer->ambassador_id,
                'type' => AmbassadorEarningType::Acquisition,
                'amount' => $this->appParams()->getAmbassadorAcquisitionAmount(),
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
     * Rewards the sponsor, once, when the sponsored ambassador has acquired enough customers (cancelled
     * acquisitions do not count). The bonus is due immediately, so the next approval run releases it.
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

        $sponsorshipBonus = AmbassadorEarning::query()->firstOrCreate(
            ['idempotency_key' => "sponsorship:{$sponsoredAmbassador->id}"],
            [
                'ambassador_id' => $sponsoredAmbassador->sponsor_ambassador_id,
                'type' => AmbassadorEarningType::SponsorshipBonus,
                'amount' => $this->appParams()->getAmbassadorSponsorshipBonusAmount(),
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
     * What the ambassador commissions cost one départ: the acquisition commissions of its bookings, counted as
     * soon as they are earned (pending included) and left out once cancelled. Computed live, never stored, like
     * the départ's ticket sales. Sponsorship bonuses belong to no départ and are not included.
     */
    public function totalCommissionsOfDepart(Depart $depart): int
    {
        return (int) AmbassadorEarning::query()
            ->where('type', AmbassadorEarningType::Acquisition)
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
