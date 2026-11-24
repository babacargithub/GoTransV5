<?php

namespace App\Models;

use Database\Factories\FeedbackCriterionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One aspect of a trip the agent asks the customer to rate from 1 to 5 (driver, punctuality, comfort...).
 */
class FeedbackCriterion extends Model
{
    /** @use HasFactory<FeedbackCriterionFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'label',
        'position',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(DepartFeedbackRating::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeInDisplayOrder(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }
}
