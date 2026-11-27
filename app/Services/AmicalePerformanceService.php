<?php

namespace App\Services;

use App\Models\Amicale;
use App\Models\Booking;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only figures about what an amicale earns from the bookings made on its website. It never writes anything.
 */
class AmicalePerformanceService
{
    /**
     * What the amicale has earned so far: its commission per ticket times the paid tickets booked on its site.
     * A booking is one ticket; cancelled bookings are soft-deleted and so never counted, and a booking only
     * counts once paid.
     */
    public function getBalance(Amicale $amicale): int
    {
        return $this->countPaidBookings($amicale) * $amicale->commission_per_ticket;
    }

    public function countPaidBookings(Amicale $amicale): int
    {
        return $this->bookingsOf($amicale)->where('paye', true)->count();
    }

    /**
     * The bookings made on the amicale's website, newest first.
     */
    public function paginateBookings(Amicale $amicale, int $perPage = 15): LengthAwarePaginator
    {
        return $this->bookingsOf($amicale)
            ->with(['customer', 'depart'])
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * @return Builder<Booking>
     */
    private function bookingsOf(Amicale $amicale): Builder
    {
        return Booking::query()->where('amicale_id', $amicale->id);
    }
}
