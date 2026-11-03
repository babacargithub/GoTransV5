<?php

namespace App\Services;

use App\Enums\AmbassadorEarningStatus;
use App\Enums\AmbassadorEarningType;
use App\Models\Ambassador;
use App\Models\AmbassadorEarning;
use App\Models\AppParams;
use App\Models\Booking;
use App\Models\Customer;

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
     * Returns null when the customer was not brought by an ambassador.
     */
    public function recordAcquisitionEarning(Booking $paidBooking): ?AmbassadorEarning
    {
        $customer = $paidBooking->customer;

        if ($customer === null || $customer->ambassador_id === null) {
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
                'label' => "Nouveau client : {$customer->full_name}",
                'approvable_at' => $paidBooking->depart->date->copy()->addDay(),
            ],
        );

        if ($acquisitionEarning->wasRecentlyCreated) {
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

        return AmbassadorEarning::query()->firstOrCreate(
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
        return AmbassadorEarning::query()
            ->where('booking_id', $booking->id)
            ->whereIn('status', [AmbassadorEarningStatus::Pending, AmbassadorEarningStatus::Approved])
            ->whereNull('ambassador_payout_id')
            ->update([
                'status' => AmbassadorEarningStatus::Cancelled,
                'cancelled_at' => now(),
            ]);
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

    private function acquisitionIdempotencyKey(Customer $customer): string
    {
        return "acquisition:{$customer->id}";
    }

    private function appParams(): AppParams
    {
        return AppParams::query()->first() ?? new AppParams(['data' => []]);
    }
}
