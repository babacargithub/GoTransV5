<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vehicule extends Model
{
    //
    protected $casts = [
        'default' => 'boolean',
        "attachments" => "array",
        "features" => "array",
    ];
}
