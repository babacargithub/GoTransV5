<?php

namespace App\Models;

use App\Observers\BookingObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

#[ObservedBy(BookingObserver::class)]
class Booking extends Model
{
    //
    use SoftDeletes;

    protected $fillable = [
        "online",
    "customer_id",
    "depart_id",
    "point_dep_id",
    "employe_id",
    "destination_id",
    "paye",
    "seat_id",
    "ticket_id",
    "bus_id",
    "created_by",
    "updated_by",
    "deleted",
    "deleted_by",
    "deletion_timestamp",
    "rating",
    "comment",
    "booked_with_platform",
    "group_id",
    "referer_id",

    ];

    // create relations
    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);
    }
    public function seat(): BelongsTo
    {
        return $this->belongsTo(BusSeat::class);
    }
    public function employe(): BelongsTo
    {
        return $this->belongsTo(Employe::class);
    }
    public function depart(): BelongsTo
    {
        return $this->belongsTo(Depart::class);
    }
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }
    public function point_dep(): BelongsTo
    {
        return $this->belongsTo(PointDep::class);
    }
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
    public function  getOtherBookingsOfSameGroup(): Collection
    {
        return Booking::where('group_id',$this->group_id)->where('id','!=',$this->id)->get();
    }
    public function getSeatNumberAttribute()
    {
        return $this->seat?->seat->number;

    }

    public function getFormattedScheduleAttribute(): ?string
    {
        $schedule = $this->depart->heuresDeparts()->where("point_dep_id",$this->point_dep_id)->first();
        return $schedule?->heureDepart->format('H:i');

    }
    public function getHasSeatAttribute(): bool
    {
        return $this->seat_id !== null;

    }
    public function getHasTicketAttribute(): bool
    {
        return $this->ticket_id !== null;

    }
    public function hasTicket() : bool
    {
        return $this->ticket_id !== null;

    }
    public static function bookingsOrdererByTrajet(Trajet $trajet, Builder $query): Builder
    {
        if ($trajet->id== 1) {
            $query->join('bus_seats', 'bookings.seat_id', '=', 'bus_seats.id')
                ->join('seats', 'bus_seats.seat_id', '=', 'seats.id')
                ->orderBy('seats.number');
        } else {
            $query->join('point_deps', 'bookings.point_dep_id', '=', 'point_deps.id')
                ->orderBy('point_deps.position');
        }
        return $query;

    }
    public function freeSeat(): self
    {
        $this->seat_id = null;
        $this->save();
        $this->seat?->free();
        $this->seat?->save();

        return $this;

    }

    public function belongsToAGroup(): bool
    {
        return $this->group_id !== null;
    }
    public function isPaid() : bool
    {
        return $this->hasTicket();
    }


}
