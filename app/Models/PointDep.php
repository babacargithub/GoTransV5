<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PointDep extends Model
{
    //
    public $timestamps = false;
    protected $fillable = [
       "name",
        "trajet_id",
        "heure_point_dep",
        "heure_point_dep_soir",
        "arret_bus",
        "position",
        "disabled",
        "disabled_at",
        "disabled_by"
    ];
    protected $casts = [
        'disabled' => 'boolean',
        "heure_point_dep" => 'datetime:H:i',
        "heure_point_dep_soir" => 'datetime:H:i'
    ];

    public function trajet(): BelongsTo
    {
        return $this->belongsTo(Trajet::class);
    }
    // create global scope to order by pointDep
    protected static function boot(): void
    {
        parent::boot();
        static::addGlobalScope('order', function ($builder) {

            $builder->orderBy('position');
        });
        static::addGlobalScope('withoutDisabled', function ($builder) {

            $builder->where('disabled', false);
        });
    }
}
