<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * @property bool $closed
 */
class Depart extends Model
{
    //
    const
        VISIBILITE_ALL_CUSTOMERS = 1,
        VISIBILITE_GP_CUSTOMERS_ONLY = 2,
        VISIBILITE_ST_CUSTOMERS_ONLY = 3,
        VISIBILITE_STAFF_ONLY = 4;
    protected $fillable = [
        "name",
        "date",
        "horaire_id",
        "trajet_id",
        "event_id",
        "closed",
        "locked",
        "visibilite",
        "deleted_at",
        "canceled",
        "canceled_at",
        "canceled_by",
        "created_by",
        "updated_by",
        "created_at",
        "updated_at",
        "allows_seat_selection",
        "should_show_seat_numbers",



    ];

    // add a scope to filter departs only that are not passed
    public function scopeNotPassed($query)
    {
        return $query->where('date', '>=', now());
    }

    public function trajet(): BelongsTo
    {
        return $this->belongsTo(Trajet::class);
    }
    public function horaire(): BelongsTo
    {
        return $this->belongsTo(Horaire::class);
    }

    protected $casts = [
        'date' => 'datetime',
        "closed" => 'boolean',
        "locked" => 'boolean',
        'shouldShowSeatNumbers' => 'boolean',
    ];

    /** @noinspection PhpUnused */
    public function getIsPassedAttribute() : bool
    {
        return $this->date->isPast();
    }
    public function buses(): HasMany
    {
        return $this->hasMany(Bus::class);

    }
    /** @noinspection PhpUnused */
    public function hasEnoughSeatsForBookings(int $numberOfBookings): bool
    {
        // uses a function to check if there is at least one bus with enough seats
        return $this->buses->some(fn(Bus $bus) => $bus->seatsLeft() >= $numberOfBookings);


    }
    public function isPassed() : bool
    {
        return $this->date->isPast();

    }
    public function heuresDeparts() : HasMany
    {
        return $this->hasMany(HeureDepart::class);
    }

    public function bookings() : HasManyThrough
    {
        return $this->hasManyThrough(Booking::class,Bus::class);
    }

    public function cancel(): self
    {
        $this->closed = true;
        $this->locked = true;
        $this->canceled = true;
        $this->canceled_at = now();

        $this->updated_by = auth()->user()?->username;
        return $this;
    }

    // add global scope to filter out canceled departs
    protected static function booted(): void
    {
        static::addGlobalScope('notCanceled', function ($builder) {
            $builder->where('canceled', false);
            $builder->orderBy('date');
        });
    }

    public function getBusForBooking(bool $climatise = false) : ?Bus
    {
        if (!$climatise) {
            $openedBuses = $this->buses->filter(fn(Bus $bus) => !$bus->isFull() && !$bus->isClosed());
            if (!$openedBuses->isEmpty()) {
                return $openedBuses->first();
            }
            return $this->buses()->latest()->firstOrFail();
        } else {
            $openedBuses = $this->buses->filter(fn(Bus $bus) => !$bus->isFull() && !$bus->isClosed() && $bus->climatise);
            if (!$openedBuses->isEmpty()) {
                return $openedBuses->first();
            }
            return $this->buses()
                ->join("vehicules","vehicules.id","=","buses.vehicule_id")
                ->where("vehicules.vehicule_type","=",Vehicule::VEHICULE_TYPE_CLIMATISE)
                ->where("buses.depart_id","=",$this->id)
                ->orderBy("buses.created_at")
                ->first();
        }

    }

    public function numberOfSeatsAvailableInAllBuses() : int
    {
        return $this->buses->sum(fn(Bus $bus) => $bus->seatsLeft());
    }

    public function isFull(): bool
    {
        return $this->buses->every(fn(Bus $bus) => $bus->isFull());
    }
    public function isClosed(): bool
    {
        return $this->closed;

    }

    public function getClosestNextDepart():?Depart
    {
        return $this->trajet->departs()
            ->where("date",">=", now())
            ->where("date",">", $this->date)
            ->where("closed",false)
            ->whereTrajetId($this->trajet_id)
            ->orderBy('date')
            ->first();
    }

    public function waitingCustomers(): HasMany
    {
        return $this->hasMany(WaitingCustomer::class);

    }

    public function identifier($with_trajet_prefix = false) : string
    {
        return ($with_trajet_prefix ? $this->trajet->name . ' - ' : '') . $this->name;
    }

    public function getNameAttribute() : string
    {
        if (is_request_for_gp_customers()){
            return ucfirst($this->date->translatedFormat('l j F') .
                " " . $this->heuresDeparts()
                    ->where('point_dep_id', "=", ($this->trajet->id == 1 ? 40 : 2))
                    ->orderBy('heureDepart')
                    ->limit(1)->first()?->heureDepart?->format('H\hi'));
        }
        return $this->attributes['name'] ;



    }

}
