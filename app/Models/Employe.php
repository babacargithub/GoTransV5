<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Employe extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'prenom',
        'nom',
        'tel',
        'email',
        'adresse',
        'poste',
        'sexe',
        'employe_category_id',
        'actif',
        'suspendu',
        'can_sell_ticket',
        'can_cancel_paid_booking',
        'can_choose_seats',
    ];

    protected $casts = [
        'actif' => 'boolean',
        'suspendu' => 'boolean',
        'can_sell_ticket' => 'boolean',
        'can_cancel_paid_booking' => 'boolean',
        'can_choose_seats' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(EmployeCategory::class, 'employe_category_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->prenom.' '.$this->nom);
    }
}
