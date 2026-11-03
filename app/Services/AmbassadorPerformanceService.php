<?php

namespace App\Services;

use App\Data\Ambassador\AmbassadorBalanceDTO;
use App\Enums\AmbassadorEarningStatus;
use App\Models\Ambassador;
use App\Models\AmbassadorEarning;
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
        return $ambassador->customers()->count();
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
                'ambassadorAcquisitionEarning',
            ])
            ->latest()
            ->paginate($perPage);
    }
}
