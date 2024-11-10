<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaitingCustomer extends Model
{
    //

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
    public function depart(): BelongsTo
    {
        return $this->belongsTo(Depart::class);
    }
    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);

    }

}
