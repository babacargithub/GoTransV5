<?php

namespace App\Models;

use App\Enums\AmbassadorEarningStatus;
use App\Enums\AmbassadorEarningType;
use Database\Factories\AmbassadorEarningFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an ambassador's earnings ledger. Amounts are snapshotted at creation, so
 * later changes to the global rates never rewrite history.
 */
class AmbassadorEarning extends Model
{
    /** @use HasFactory<AmbassadorEarningFactory> */
    use HasFactory;

    protected $fillable = [
        'ambassador_id',
        'type',
        'amount',
        'status',
        'idempotency_key',
        'booking_id',
        'source_ambassador_id',
        'label',
        'approvable_at',
        'approved_at',
        'paid_at',
        'cancelled_at',
        'ambassador_payout_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AmbassadorEarningType::class,
            'status' => AmbassadorEarningStatus::class,
            'amount' => 'integer',
            'approvable_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function ambassador(): BelongsTo
    {
        return $this->belongsTo(Ambassador::class);
    }

    public function sourceAmbassador(): BelongsTo
    {
        return $this->belongsTo(Ambassador::class, 'source_ambassador_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(AmbassadorPayout::class, 'ambassador_payout_id');
    }

    /**
     * Lines that still count towards what the ambassador is owed (everything except cancelled ones).
     */
    public function scopeNotCancelled(Builder $query): Builder
    {
        return $query->where('status', '!=', AmbassadorEarningStatus::Cancelled);
    }

    /**
     * Pending lines whose waiting period is over and can now be approved.
     */
    public function scopeReadyForApproval(Builder $query): Builder
    {
        return $query
            ->where('status', AmbassadorEarningStatus::Pending)
            ->whereNotNull('approvable_at')
            ->where('approvable_at', '<=', now());
    }

    /**
     * Approved lines not yet part of a payout: the amount currently payable to the ambassador.
     */
    public function scopePayable(Builder $query): Builder
    {
        return $query
            ->where('status', AmbassadorEarningStatus::Approved)
            ->whereNull('ambassador_payout_id');
    }
}
