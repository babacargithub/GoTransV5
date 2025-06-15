<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class
Itinerary extends Model
{
    use HasFactory;

    protected $casts =[
        "point_deps"=>"array"
    ];

    public function  pointDeparts() : Collection
    {
        return PointDep::whereIn("id", $this->point_deps)->get();

    }
}
