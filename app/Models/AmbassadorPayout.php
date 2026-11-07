<?php

namespace App\Models;

use App\Enums\AmbassadorPayoutStatus;
use Database\Factories\AmbassadorPayoutFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The reference that groups the approved earnings paid together in one Wave transfer. Earnings are attached
 * to it internally first; only then is Wave called, with this record's idempotency key, so a retry can never
 * pay twice. The amount is the sum of the attached earnings, snapshotted at creation.
 */
class AmbassadorPayout extends Model
{
    /** @use HasFactory<AmbassadorPayoutFactory> */
    use HasFactory;

    public const MAX_ATTEMPTS = 5;

    protected $fillable = [
        'ambassador_id',
        'amount',
        'wave_phone_number',
        'payment_reason',
        'status',
        'idempotency_key',
        'provider_payout_id',
        'failure_reason',
        'attempts_count',
        'last_attempted_at',
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
            'attempts_count' => 'integer',
            'last_attempted_at' => 'datetime',
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

    /**
     * Whether Wave may be called for this payout: not settled, not past MAX_ATTEMPTS, and not already
     * accepted by Wave.
     */
    public function canBeSent(): bool
    {
        if ($this->attempts_count >= self::MAX_ATTEMPTS) {
            return false;
        }

        return match ($this->status) {
            AmbassadorPayoutStatus::Failed => true,
            // A pending payout holding a Wave id was accepted but not settled yet: resending would be pointless.
            AmbassadorPayoutStatus::Pending => $this->provider_payout_id === null,
            AmbassadorPayoutStatus::Success => false,
        };
    }
}
