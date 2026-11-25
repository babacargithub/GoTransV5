<?php

namespace App\Services;

use App\Models\Bus;
use App\Models\BusSeat;
use App\Models\Trajet;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Throwable;

class BusService
{
    /**
     * Buses of the non-cancelled départs whose date falls in the period, optionally on a single trajet.
     */
    public function countBuses(CarbonInterface $periodStart, CarbonInterface $periodEnd, ?Trajet $trajet = null): int
    {
        return Bus::query()
            ->whereHas('depart', function (Builder $departs) use ($periodStart, $periodEnd, $trajet): void {
                $departs->whereBetween('date', [$periodStart, $periodEnd])
                    ->when($trajet, fn (Builder $trajetDeparts) => $trajetDeparts->where('trajet_id', $trajet->id));
            })
            ->count();
    }

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
     * @throws Throwable
     */
    private function freeBookedSeatsWithoutActiveBooking(Builder|HasMany $query): void
    {
        DB::transaction(function () use ($query) {
            $query->where(function ($query) {
                $query->where('booked', true)
                    ->orWhereNotNull('booked_at');
            })
                ->whereNotExists(function ($query) {
                    $query->select('id')
                        ->from('bookings')
                        ->whereColumn('bookings.seat_id', 'bus_seats.id')
                        ->whereNull('bookings.deleted_at');
                })
                ->get()
                ->each(function (BusSeat $busSeat) {
                    $busSeat->freeSeat();
                    $busSeat->save();
                });
        });
    }
}
