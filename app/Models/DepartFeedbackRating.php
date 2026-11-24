<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The 1 to 20 grade (Senegalese system) a customer gave one criterion during a feedback call.
 */
class DepartFeedbackRating extends Model
{
    public const MINIMUM_SCORE = 1;

    public const MAXIMUM_SCORE = 20;

    protected $fillable = [
        'depart_feedback_id',
        'feedback_criterion_id',
        'score',
    ];

    public function departFeedback(): BelongsTo
    {
        return $this->belongsTo(DepartFeedback::class);
    }

    public function criterion(): BelongsTo
    {
        return $this->belongsTo(FeedbackCriterion::class, 'feedback_criterion_id');
    }
}
