<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepartCostReportBusRental extends Model
{
    protected $fillable = [
        'depart_cost_report_id',
        'bus_id',
        'bus_owner_name',
        'rental_fee',
        'paid_amount',
    ];

    protected $casts = [
        'rental_fee' => 'integer',
        'paid_amount' => 'integer',
    ];

    public function remainingAmountToPay(): int
    {
        return max(0, $this->rental_fee - $this->paid_amount);
    }

    public function departCostReport(): BelongsTo
    {
        return $this->belongsTo(DepartCostReport::class);
    }

    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);
    }
}
