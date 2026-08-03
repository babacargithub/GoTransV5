<?php

namespace App\Services;

use App\Models\Bus;
use App\Models\BusSeat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Throwable;

class BusService
{
    /**
     * Fetches every seat marked as booked (either via the `booked` flag or a
     * lingering `booked_at` timestamp) that has no active booking attached
     * to it, and frees them one by one using BusSeat::freeSeat().
     *
     * @throws Throwable
     */
    public function freeAllBusSeatsRegardlessOfBus(): void
    {
        $this->freeBookedSeatsWithoutActiveBooking(BusSeat::query());
    }

    /**
     * Same as freeAllBusSeatsRegardlessOfBus() but scoped to the seats of a single bus.
     *
     * @throws Throwable
     */
    public function freeBusSeats(Bus $bus): void
    {
        $this->freeBookedSeatsWithoutActiveBooking($bus->seats());
    }

    /**
     * @param Builder|HasMany $query
     * @throws Throwable
     */
    private function freeBookedSeatsWithoutActiveBooking(Builder|HasMany $query): void
    {
        DB::transaction(function () use ($query) {
            $query->where(function ($query) {
                    $query->where("booked", true)
                        ->orWhereNotNull("booked_at");
                })
                ->whereNotExists(function ($query) {
                    $query->select("id")
                        ->from("bookings")
                        ->whereColumn("bookings.seat_id", "bus_seats.id")
                        ->whereNull("bookings.deleted_at");
                })
                ->get()
                ->each(function (BusSeat $busSeat) {
                    $busSeat->freeSeat();
                    $busSeat->save();
                });
        });
    }
}
