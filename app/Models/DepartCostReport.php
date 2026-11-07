<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Financial report of one départ: the rental fee paid to each bus owner plus free-form notes.
 * Ticket sales are never stored; they are always computed live from the départ's tickets.
 */
class DepartCostReport extends Model
{
    protected $fillable = [
        'depart_id',
        'notes',
        'created_by',
        'updated_by',
    ];

    public function depart(): BelongsTo
    {
        return $this->belongsTo(Depart::class);
    }

    public function busRentals(): HasMany
    {
        return $this->hasMany(DepartCostReportBusRental::class);
    }

    public function totalPaidAmount(): int
    {
        return (int) $this->busRentals->sum('paid_amount');
    }

    public function totalRentalFees(): int
    {
        return (int) $this->busRentals->sum('rental_fee');
    }
}
