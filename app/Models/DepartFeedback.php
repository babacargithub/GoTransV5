<?php

namespace App\Models;

use App\Enums\FeedbackCallOutcome;
use Database\Factories\DepartFeedbackFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One customer feedback call after a depart: who must be called (one row per booking, created when the depart
 * is marked arrived), how the call went, the per-criterion ratings and the call recording.
 *
 * Several agents share the queue, so a card is claimed by the agent who takes it; a claim not renewed within
 * {@see self::CLAIM_EXPIRY_MINUTES} is considered released.
 */
class DepartFeedback extends Model
{
    /** @use HasFactory<DepartFeedbackFactory> */
    use HasFactory;

    public const CLAIM_EXPIRY_MINUTES = 30;

    protected $table = 'depart_feedbacks';

    protected $fillable = [
        'depart_id',
        'customer_id',
        'booking_id',
        'outcome',
        'claimed_by_user_id',
        'claimed_at',
        'called_at',
        'completed_at',
        'comment',
        'recording_path',
        'recording_uploaded_at',
        'recording_last_listened_at',
        'recording_last_listened_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => FeedbackCallOutcome::class,
            'claimed_at' => 'datetime',
            'called_at' => 'datetime',
            'completed_at' => 'datetime',
            'recording_uploaded_at' => 'datetime',
            'recording_last_listened_at' => 'datetime',
        ];
    }

    public function depart(): BelongsTo
    {
        return $this->belongsTo(Depart::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by_user_id');
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(DepartFeedbackRating::class);
    }

    /**
     * Calls that are over: the customer answered and was rated, or refused to take part.
     */
    public function scopeFinished(Builder $query): Builder
    {
        return $query->whereNotNull('completed_at');
    }

    /**
     * Customers whose call is not over: never called, called without answer, or answered but not yet rated.
     */
    public function scopeStillToCall(Builder $query): Builder
    {
        return $query->whereNull('completed_at');
    }

    /**
     * Whether another agent than the given one currently holds this card.
     */
    public function isClaimedByAnotherAgent(int $agentUserId): bool
    {
        return $this->claimed_by_user_id !== null
            && $this->claimed_by_user_id !== $agentUserId
            && $this->claimed_at?->gt(now()->subMinutes(self::CLAIM_EXPIRY_MINUTES)) === true;
    }

    public function isFinished(): bool
    {
        return $this->completed_at !== null;
    }

    public function hasRecording(): bool
    {
        return $this->recording_path !== null;
    }

    /**
     * Average of the ratings given on this call, or null when none were recorded.
     */
    public function averageScore(): ?float
    {
        $ratings = $this->relationLoaded('ratings') ? $this->ratings : $this->ratings()->get();

        return $ratings->isEmpty() ? null : round((float) $ratings->avg('score'), 1);
    }
}
