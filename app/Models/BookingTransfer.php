<?php

namespace App\Models;

use App\Enums\BookingTransferType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingTransfer extends Model
{
    protected $fillable = [
        'booking_id',
        'source_bus_id',
        'target_bus_id',
        'source_seat_id',
        'target_seat_id',
        'source_seat_number',
        'target_seat_number',
        'transfer_type',
        'user_id',
        'transferred_at',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'transfer_type' => BookingTransferType::class,
            'transferred_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function sourceBus(): BelongsTo
    {
        return $this->belongsTo(Bus::class, 'source_bus_id');
    }

    public function targetBus(): BelongsTo
    {
        return $this->belongsTo(Bus::class, 'target_bus_id');
    }

    public function sourceSeat(): BelongsTo
    {
        return $this->belongsTo(BusSeat::class, 'source_seat_id');
    }

    public function targetSeat(): BelongsTo
    {
        return $this->belongsTo(BusSeat::class, 'target_seat_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
