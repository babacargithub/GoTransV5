<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Trajet extends Model
{
    //
    const
        UGB_DAKAR = 1,
        DAKAR_UGB = 2;
    const
        HORAIRE_NUIT_UGB_DK = 1,
        HORAIRE_MATIN_UGB_DK =  4,
        HORAIRE_MATIN_DK_UG = 3,
        HORAIRE_APRES_MIDI_DK_UG = 2,
        HORAIRE_APRES_MIDI_UGB_DK = 5;
    public $timestamps = false;

    protected $fillable =[
        "name", "length", "end_point","start_point", "deleted_at"
    ];
    public function destinations() : HasMany
    {
        return $this->hasMany(Destination::class);
    }

    public function pointDeps() : HasMany
    {
        return $this->hasMany(PointDep::class);
    }

    public function  horaires(): HasMany
    {
        return $this->hasMany(Horaire::class);

    }

    public function departs(): HasMany
    {
        return $this->hasMany(Depart::class);
    }
}
