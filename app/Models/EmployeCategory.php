<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeCategory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'name',
        'private_name',
        'roles',
    ];

    protected $casts = [
        'roles' => 'array',
    ];

    public function employes(): HasMany
    {
        return $this->hasMany(Employe::class);
    }
}
