<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vehicule extends Model
{
    //
    const VEHICULE_TYPE_SIMPLE = 1;
    const VEHICULE_TYPE_CLIMATISE = 2;
    protected $casts = [
        'default' => 'boolean',
        "attachments" => "array",
        "features" => "array",
    ];

    public function getClimatiseAttribute() : bool
    {
        return $this->vehicule_type === self::VEHICULE_TYPE_CLIMATISE;

    }
}
