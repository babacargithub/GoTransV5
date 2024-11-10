<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HeureDepart extends Model
{
    //
    protected $table = 'heure_departs';
    public $timestamps = false;
    protected $fillable = [
        "depart_id",
        "point_dep_id",
        "heureDepart",
        "arretBus",
        "bus_id",
    ];
    protected $casts = [
        'heureDepart' => 'datetime',
    ];

    public function depart(): BelongsTo
    {
        return $this->belongsTo(Depart::class);
    }
    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);
    }
    public function pointDep(): BelongsTo
    {
        return $this->belongsTo(PointDep::class);

    }
    // add a global scope to order by point_dep.position
    protected static function booted()
    {
        static::addGlobalScope('orderByPointDepartPosition', function ($builder) {
            $builder->join('point_deps', 'point_deps.id', '=', 'heure_departs.point_dep_id')
                ->orderBy('point_deps.position');
        });
    }
}
