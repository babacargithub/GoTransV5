<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromotionalMessage extends Model
{
    //
    protected $casts = [
        'paused' => 'boolean',
        "depart_ids" => "array",
        "bus_ids" => "array"
    ];
    protected $fillable = [
        'message',
        'paused'
    ];

}
