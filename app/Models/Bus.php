<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bus extends Model
{
    //
    protected $table = 'buses';

    protected $fillable = [
        'name',
        'depart_id',
        'closed',
        'closed_at',
        'deleted_at',
        "nombre_place",
        "ticket_price",
        "vehicule_id",


    ];

    public function depart(): BelongsTo
    {
        return $this->belongsTo(Depart::class);
    }
    public function vehicule(): BelongsTo
    {
        return $this->belongsTo(Vehicule::class);

    }
    public function seats(): HasMany
    {
        return $this->hasMany(BusSeat::class);
    }
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);

    }
    public function seatsLeft(): int
    {
        return $this->getAvailableSeats()->count();

    }
    public function numberOfBookedSeats(): int
    {
        return $this->bookings()->whereNotNull('seat_id')->count();

    }
    public function isFull(): bool
    {
        return $this->seatsLeft() <= 0 ;

    }
    public function isClosed(): bool
    {
        return (bool)$this->closed || (bool) $this->depart->closed;

    }

    public function hasEnoughSeatsForBookings(int $numberOfBookings): bool
    {
        return $this->seatsLeft() >= $numberOfBookings;

    }
    public function getAvailableSeats(): Collection
    {
//        return  $this->seats()->where('booked', false)->get();
        return $this->seats()
            ->whereNotExists(function ($query) {
                $query->select('id')
                    ->from('bookings')
                    ->whereColumn('bookings.seat_id', 'bus_seats.id');
            })
            ->lockForUpdate()
            ->orderBy("bus_seats.seat_id","asc")->get();

    }
    public function getOneAvailableSeat(): BusSeat
    {
        $availableSeat = $this->getAvailableSeats()->first();
        if ($availableSeat == null){
            throw new ModelNotFoundException("Aucun siège trouvé dans ce bus ".$this->full_name);
        }
        return $availableSeat;


    }
    public function numberOfTicketsSold() : int
    {
        // bookings that have tickets
        return $this->bookings()->whereNotNull('ticket_id')->count();
    }
    public function getFullNameAttribute(): string
    {

        return $this->depart->identifier(with_trajet_prefix: true) . ' - ' . $this->name;

    }
    // add global scope filter buses for departs that are not cancelled
    protected static function boot(): void
    {
        parent::boot();
        static::addGlobalScope('notCancelled', function ($query) {
            $query->whereHas('depart', function ($query) {
                $query->where('canceled', false);
            });
        });
    }
    public function close() : self
    {
        $this->closed = true;
        $this->closed_at = now();

        return $this;
    }
    public function open() : self
    {
        $this->closed = false;
        $this->closed_at = null;

        return $this;
    }
    public function waitingCustomers(): HasMany
    {
        return $this->hasMany(WaitingCustomer::class);

    }




    protected $casts = [
        'closed' => 'boolean',
        'closed_at' => 'datetime'

    ];
}
