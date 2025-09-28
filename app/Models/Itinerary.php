<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class
Itinerary extends Model
{
    use HasFactory;
    public $timestamps = false;

    protected $casts =[
        "point_deps"=>"array",
    ];

    public function trajet(): BelongsTo
    {
        return $this->belongsTo(Trajet::class);

    }
    public function  pointDeparts() : Collection
    {
        return PointDep::whereIn("id", $this->point_deps)->get();

    }
}
