<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    //
    use SoftDeletes;
    protected $fillable =[
        "nombre_voyage",
    "prenom",
    "nom",
    "phone_number",
    "adresse",
    "sexe",
    "disabled",
    "active",
    "categorie",
    "email",
    "deleted",
    "delete_at",
    "last_active"];
    // get full name
    public function getFullNameAttribute(): string
    {
        return $this->prenom . ' ' . $this->nom;

    }
    // get short name
    public function getShortNameAttribute(): string
    {
       // first letter of prenom and a point and the nom
        return strtoupper(substr($this->prenom,0,1)) . '. ' . strtoupper($this->nom);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);

    }

    public function getCurrentBooking()
    {
        return $this->bookings()
            ->whereHas('depart', function ($query) {
                $query->where('date', '>=', now());
            })
            ->orderBy(function ($query) {
                $query->select('date')
                    ->from('departs')
                    ->whereColumn('depart_id', 'departs.id')
                    ->orderBy('date', 'asc')
                    ->limit(1);
            })->first();
    }
    public function updateLastActivity(): self
    {
        $this->last_active = now();
        return $this;
    }
}

