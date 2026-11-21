<?php

namespace App\Models;

use App\Enums\AmbassadorEarningType;
use Database\Factories\AmbassadorRewardRuleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Says what one kind of ambassador earning pays, to whom and when. A rule can be paused or limited to a
 * period, and applies either to every ambassador or to a single one. Without an applicable rule, nothing
 * is rewarded.
 */
class AmbassadorRewardRule extends Model
{
    /** @use HasFactory<AmbassadorRewardRuleFactory> */
    use HasFactory;

    protected $fillable = [
        'earning_type',
        'amount',
        'is_enabled',
        'starts_at',
        'ends_at',
        'ambassador_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'earning_type' => AmbassadorEarningType::class,
            'amount' => 'integer',
            'is_enabled' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function ambassador(): BelongsTo
    {
        return $this->belongsTo(Ambassador::class);
    }

    /**
     * Rules, paused or not, whose period covers the given moment.
     */
    public function scopeCoveringPeriodAt(Builder $query, Carbon $moment): Builder
    {
        return $query
            ->where(fn (Builder $startQuery) => $startQuery->whereNull('starts_at')->orWhere('starts_at', '<=', $moment))
            ->where(fn (Builder $endQuery) => $endQuery->whereNull('ends_at')->orWhere('ends_at', '>=', $moment));
    }

    /**
     * The rule deciding what an ambassador earns for a type of reward right now: among the rules whose period
     * covers today, the one dedicated to this ambassador when there is one, otherwise the general one. The
     * deciding rule can be paused: a paused dedicated rule stops the reward for that ambassador even though
     * the general rule is on. Null means the reward is not granted.
     */
    public static function findApplicableRule(AmbassadorEarningType $earningType, Ambassador $ambassador): ?self
    {
        $decidingRule = self::query()
            ->where('earning_type', $earningType)
            ->coveringPeriodAt(now())
            ->where(fn (Builder $ambassadorQuery) => $ambassadorQuery
                ->whereNull('ambassador_id')
                ->orWhere('ambassador_id', $ambassador->id))
            ->orderByRaw('ambassador_id is null')
            ->orderByDesc('id')
            ->first();

        return $decidingRule?->is_enabled ? $decidingRule : null;
    }
}
