<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'text',
        'to',
        'status',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
