<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusSeat extends Model
{
    //
    public $timestamps = false;
    protected $fillable = [
        'seat_id',
        'bus_id',
        'booked',
        "price",
        "position_in_bus",
        'booked_at',
    ];
    protected $casts = [
        'booked' => 'boolean',
        'booked_at' => 'datetime',
    ];

    public function seat(): BelongsTo
    {
        return $this->belongsTo(Seat::class);
    }
    public function freeSeat(): void
    {
        $this->booked = false;
        $this->bookedAt = null;
    }
    public function book(): void
    {
        $this->booked = true;
        $this->bookedAt = now();
    }
    public function getNumberAttribute()
    {
        return $this->seat->number;

    }

    public function isAvailable(): bool
    {
        return !(boolean)$this->booked;
    }
    public function isBooked(): bool
    {
        return (boolean)$this->booked;
    }

    public function free(): self
    {
        $this->booked = false;
        $this->bookedAt = null;
        return $this;

    }
}
