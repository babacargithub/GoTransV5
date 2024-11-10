<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Destination extends Model
{
    //
    protected $fillable =[
        "name",
        "tarif",
        "trajet_id",
    ];
    public $timestamps = false;
    public function trajet(): BelongsTo
    {
        return $this->belongsTo(Trajet::class);
    }
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->tarif = $model->tarif ?? 3550;
            // set other defaults as needed
        });
    }
}
