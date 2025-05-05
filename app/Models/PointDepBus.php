<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PointDepBus extends Model
{
    use HasFactory;
    protected $fillable = [
        'point_dep_id',
        'bus_id',
        "arret_bus",
        "disabled"
    ];
    protected $casts = [
        'disabled' => 'boolean',
    ];
    public function pointDep()
    {
        return $this->belongsTo(PointDep::class);
    }
}
