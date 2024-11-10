<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    //
    public $timestamps = false;
    protected $fillable=[

        'libelle',
        'date_start',
        'date_end',
        "trajet"
    ];
    public function departs(): HasMany
    {
        return $this->hasMany(Depart::class);

    }
    public function getNameAttribute()
    {
        return $this->libelle;

    }
    protected $casts = [
        'date_end' => 'datetime:Y-m-d',
        'date_start' => 'datetime:Y-m-d',
    ];
}
