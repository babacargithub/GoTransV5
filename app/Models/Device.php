<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    use HasFactory;

    protected $fillable = ["device_id","name","phone_number","last_heartbeat"];
    protected $casts = [
        "last_heartbeat" => "datetime"
    ];
    public function isOnline(): bool
    {
        return (bool)$this->last_heartbeat?->diffInSeconds(now()) < 32;

    }

    public function messages(): HasMany
    {
        return $this->hasMany(SmsMessage::class);
    }
}
