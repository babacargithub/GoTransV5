<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vehicule extends Model
{
    //
    const VEHICULE_TYPE_SIMPLE = 1;

    const VEHICULE_TYPE_CLIMATISE = 2;

    public $timestamps = false;

    protected $fillable = [
        'name',
        'matricule',
        'chauffeur',
        'nombre_place',
        'vehicule_type',
        'description',
        'default',
        'features',
        'attachments',
        'template',
    ];

    protected $casts = [
        'default' => 'boolean',
        'attachments' => 'array',
        'features' => 'array',
    ];

    public function getClimatiseAttribute(): bool
    {
        return $this->vehicule_type === self::VEHICULE_TYPE_CLIMATISE;

    }
}
