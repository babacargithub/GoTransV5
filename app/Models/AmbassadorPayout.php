<?php

namespace App\Models;

use App\Enums\AmbassadorPayoutStatus;
use Database\Factories\AmbassadorPayoutFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AmbassadorPayout extends Model
{
    /** @use HasFactory<AmbassadorPayoutFactory> */
    use HasFactory;

    protected $fillable = [
        'ambassador_id',
        'amount',
        'wave_phone_number',
        'status',
        'idempotency_key',
        'provider_payout_id',
        'failure_reason',
        'requested_by_user_id',
        'paid_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AmbassadorPayoutStatus::class,
            'amount' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function ambassador(): BelongsTo
    {
        return $this->belongsTo(Ambassador::class);
    }

    public function earnings(): HasMany
    {
        return $this->hasMany(AmbassadorEarning::class);
    }
}
