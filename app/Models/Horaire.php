<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Horaire extends Model
{
    //
    const PERIODE_MATIN = "matin";
    const PERIODE_APRES_MIDI = "apres-midi";
    const PERIODE_NUIT = "nuit";
    const PERIODES = [
        self::PERIODE_MATIN,
        self::PERIODE_APRES_MIDI,
        self::PERIODE_NUIT,
    ];
    const HEURE_DEPART_MATIN = "07:00";
    const HEURE_DEPART_APRES_MIDI = "14:00";
    const HEURE_DEPART_NUIT = "21:00";
    public $timestamps = false;
    protected $fillable = [
        "trajet_id",
        "name",
        "bus_leave_time",
        "constant_name",
    ];
    protected $casts = [
        "bus_leave_time" => "datetime:H:i",
    ];

}
