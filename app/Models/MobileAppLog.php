<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobileAppLog extends Model
{
    //
    protected $fillable= [
        "type",
        "data",
        "customer_id",
    ];
    protected $casts = [
        'data' => 'array'
    ];
}
