<?php

namespace App\Models;

use App\Enums\BookingType;
use App\Observers\BookingObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

#[ObservedBy(BookingObserver::class)]
class Booking extends Model
{
    //
    use SoftDeletes;

    const TRIP_LEG_OUTBOUND = 'outbound';

    const TRIP_LEG_RETURN = 'return';

    protected $fillable = [
        'online',
        'customer_id',
        'depart_id',
        'point_dep_id',
        'employe_id',
        'destination_id',
        'paye',
        'seat_id',
        'ticket_id',
        'bus_id',
        'created_by',
        'updated_by',
        'deleted',
        'deleted_by',
        'deletion_timestamp',
        'rating',
        'comment',
        'booked_with_platform',
        'group_id',
        'booking_type',
        'is_main_booking',
        'uuid',
        'referer_id',
        'booked_for_customer',
        'round_trip_id',
        'trip_leg',

        'referer_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'booking_type' => BookingType::class,
            'is_main_booking' => 'boolean',
        ];
    }

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

    public function getOtherBookingsOfSameGroup(): Collection
    {
        return Booking::where('group_id', $this->group_id)->where('id', '!=', $this->id)->get();
    }

    public function getSeatNumberAttribute()
    {
        return $this->seat?->seat->number;

    }

    public function getFormattedScheduleAttribute(): ?string
    {
        if ($this->bus?->relationLoaded('heuresDeparts') && $this->depart?->relationLoaded('heuresDeparts')) {
            return $this->formattedScheduleFromLoadedSchedules();
        }

        $busSchedule = $this->bus?->heuresDeparts()->where('point_dep_id', $this->point_dep_id)->first();
        if ($busSchedule == null) {
            $busSchedule = $this->depart?->heuresDeparts()->where('point_dep_id', $this->point_dep_id)->first();
        }
        $schedule = $busSchedule;
        if ($schedule == null) {
            $schedule = $this->depart?->heuresDeparts()->orderBy('heureDepart')->firstOrFail();
        }

        return $schedule?->heureDepart->format('H:i');

    }

    /**
     * Same lookup order as getFormattedScheduleAttribute()'s queries (bus stop, then départ stop,
     * then the départ's earliest schedule), answered from already eager-loaded schedules so a
     * list of bookings does not run queries per row.
     */
    private function formattedScheduleFromLoadedSchedules(): string
    {
        $departSchedules = $this->depart->heuresDeparts;

        $schedule = $this->bus->heuresDeparts->firstWhere('point_dep_id', $this->point_dep_id)
            ?? $departSchedules->firstWhere('point_dep_id', $this->point_dep_id)
            ?? $departSchedules->sortBy('heureDepart')->first();

        if ($schedule === null) {
            throw (new ModelNotFoundException)->setModel(HeureDepart::class);
        }

        return $schedule->heureDepart->format('H:i');
    }

    public function getHasSeatAttribute(): bool
    {
        return $this->seat_id !== null;

    }

    public function getHasTicketAttribute(): bool
    {
        return $this->ticket_id !== null;

    }

    public function hasTicket(): bool
    {
        return $this->ticket_id !== null;

    }

    public static function bookingsOrdererByTrajet(Trajet $trajet, Builder $query): Builder
    {
        $query->join('point_deps', 'bookings.point_dep_id', '=', 'point_deps.id')
            ->join('bus_seats', 'bookings.seat_id', '=', 'bus_seats.id')
            ->select('bookings.*')
            ->join('seats', 'bus_seats.seat_id', '=', 'seats.id');

        if ($trajet->id == 1) {

            $query->orderBy('point_deps.position')
                ->orderBy('seats.number');
        } else {
            $query->orderBy('point_deps.position')
                ->orderBy('seats.number');
        }

        return $query;

    }

    public function freeSeat(): self
    {
        \DB::transaction(function () {
            $this->seat?->free();
            $this->seat?->save();
            $this->seat_id = null;
            $this->save();
        });

        return $this;

    }

    public function belongsToAGroup(): bool
    {
        return $this->group_id !== null;
    }

    public function isGroupBooking(): bool
    {
        return $this->booking_type === BookingType::Group;
    }

    /**
     * Whether another booking (cancelled ones included) was paid in the same aggregate payment. Wave can only
     * refund that payment as a whole, so such a booking must never be refunded through the Wave API. This is
     * about the shared payment, not the display type: a lone traveller's round trip is typed Single yet its
     * two legs are still paid together, and a group whose other members were all cancelled was partly refunded.
     */
    public function sharesPaymentWithOtherBookings(): bool
    {
        return $this->group_id !== null
            && static::withTrashed()->where('group_id', $this->group_id)->whereKeyNot($this->getKey())->exists();
    }

    public function isRoundTrip(): bool
    {
        return $this->round_trip_id !== null;
    }

    public function isPaid(): bool
    {
        return $this->hasTicket();
    }

    public function getPhoneNumberAttribute(): int
    {
        return intval(substr($this->customer->phone_number, -9, 9));

    }

    public function getIsForGpAttribute(): bool
    {
        return strtolower($this->comment) == 'for_gp';
    }

    public function getPassengerFullNameAttribute(): string
    {
        $fullName = '';
        if ($this->comment != null && $this->comment != 'for_gp' && $this->comment != 'gp') {
            try {
                $customerInfo = json_decode($this->comment, true);
                if (isset($customerInfo['passenger_full_name'])) {
                    $fullName = $customerInfo['passenger_full_name'];
                } elseif (isset($customerInfo['name'])) {
                    $fullName = $customerInfo['name'];
                } elseif (isset($customerInfo['firstName']) && isset($customerInfo['lastName'])) {
                    $fullName = $customerInfo['firstName'].' '.$customerInfo['lastName'];
                }
            } catch (\Exception $e) {
                // Handle the case where JSON decoding fails or the structure is unexpected
                $fullName = $this->customer->full_name ?? 'N/A';

            }

        } elseif ($this->booked_for_customer != null) {
            $fullName = $this->booked_for_customer;
        } else {
            // If comment is null, use the customer full name directly
            $fullName = $this->customer->full_name ?? 'N/A';
        }

        return $fullName;

    }
}
