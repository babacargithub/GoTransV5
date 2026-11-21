<?php

namespace App\Services;

use App\Data\Ambassador\AmbassadorBalanceDTO;
use App\Enums\AmbassadorEarningStatus;
use App\Enums\AmbassadorEarningType;
use App\Enums\AmbassadorPayoutStatus;
use App\Models\Ambassador;
use App\Models\AmbassadorEarning;
use App\Models\AmbassadorPayout;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Read-only queries about an ambassador's earnings and performance. It never writes anything.
 */
class AmbassadorPerformanceService
{
    /**
     * The ambassador's account balance, computed from the earnings ledger in a single query:
     * available = approved earnings, pending = earnings still in their waiting period,
     * total = both together, paid = already received. Cancelled earnings are excluded.
     */
    public function getAccountBalance(Ambassador $ambassador): AmbassadorBalanceDTO
    {
        $amountsByStatus = AmbassadorEarning::query()
            ->where('ambassador_id', $ambassador->id)
            ->whereIn('status', [AmbassadorEarningStatus::Approved, AmbassadorEarningStatus::Pending, AmbassadorEarningStatus::Paid])
            ->selectRaw('status, SUM(amount) as total_amount')
            ->groupBy('status')
            ->pluck('total_amount', 'status');

        $availableBalance = (int) ($amountsByStatus[AmbassadorEarningStatus::Approved->value] ?? 0);
        $pendingBalance = (int) ($amountsByStatus[AmbassadorEarningStatus::Pending->value] ?? 0);
        $paidBalance = (int) ($amountsByStatus[AmbassadorEarningStatus::Paid->value] ?? 0);

        return new AmbassadorBalanceDTO(
            totalBalance: $availableBalance + $pendingBalance,
            availableBalance: $availableBalance,
            pendingBalance: $pendingBalance,
            paidBalance: $paidBalance,
        );
    }

    public function countAcquiredCustomers(Ambassador $ambassador): int
    {
        return $ambassador->customersWithActiveBooking()->count();
    }

    public function countSponsoredAmbassadors(Ambassador $ambassador): int
    {
        return $ambassador->sponsoredAmbassadors()->count();
    }

    /**
     * Bookings made with the ambassador's code, newest first, with everything the ambassador space
     * lists for each one (customer, départ, bus, seat, schedule and the commission it earned).
     */
    public function paginateReferredBookings(Ambassador $ambassador, int $perPage = 10): LengthAwarePaginator
    {
        return $ambassador->bookings()
            ->with([
                'customer',
                'depart.trajet',
                'depart.heuresDeparts',
                'bus.heuresDeparts',
                'seat.seat',
                'ambassadorBookingEarning',
            ])
            ->latest()
            ->paginate($perPage);
    }

    /**
     * The ambassador's earnings ledger, newest first, optionally narrowed to one status.
     */
    public function paginateEarnings(Ambassador $ambassador, ?AmbassadorEarningStatus $status = null, int $perPage = 15): LengthAwarePaginator
    {
        return AmbassadorEarning::query()
            ->where('ambassador_id', $ambassador->id)
            ->when($status !== null, fn ($earningsQuery) => $earningsQuery->where('status', $status))
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * The ambassadors sponsored by this ambassador, each with the number of customers they acquired
     * (acquired_customers_count, the figure the sponsorship threshold is measured on) and whether the
     * sponsorship bonus was already earned for them (sponsorship_bonus_earned).
     */
    public function paginateSponsoredAmbassadorsWithProgress(Ambassador $ambassador, int $perPage = 15): LengthAwarePaginator
    {
        return $ambassador->sponsoredAmbassadors()
            ->withCount(['earnings as acquired_customers_count' => fn ($earningsQuery) => $earningsQuery
                ->where('type', AmbassadorEarningType::Acquisition)
                ->notCancelled()])
            ->withExists(['earningsGeneratedForSponsor as sponsorship_bonus_earned' => fn ($earningsQuery) => $earningsQuery
                ->where('type', AmbassadorEarningType::SponsorshipBonus)
                ->where('ambassador_id', $ambassador->id)
                ->notCancelled()])
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * The payouts requested for the ambassador, newest first.
     */
    public function paginatePayouts(Ambassador $ambassador, int $perPage = 15): LengthAwarePaginator
    {
        return AmbassadorPayout::query()
            ->where('ambassador_id', $ambassador->id)
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * Every payout for the back-office payout screen, newest first, optionally narrowed to one status.
     */
    public function paginatePayoutsForBackOffice(?AmbassadorPayoutStatus $status = null, int $perPage = 15): LengthAwarePaginator
    {
        return AmbassadorPayout::query()
            ->with('ambassador')
            ->when($status !== null, fn ($payoutsQuery) => $payoutsQuery->where('status', $status))
            ->latest('id')
            ->paginate($perPage);
    }
}
