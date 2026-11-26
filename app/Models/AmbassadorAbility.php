<?php

namespace App\Models;

use App\Enums\AmbassadorAbilityName;
use Database\Factories\AmbassadorAbilityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Something an ambassador may be allowed to do, granted ambassador by ambassador (e.g. sponsoring others).
 */
class AmbassadorAbility extends Model
{
    /** @use HasFactory<AmbassadorAbilityFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'label',
    ];

    public function ambassadors(): BelongsToMany
    {
        return $this->belongsToMany(Ambassador::class, 'ambassador_ability_ambassador')->withTimestamps();
    }

    /**
     * The ability row for a catalogue entry, or null when it was never seeded.
     */
    public static function findByName(AmbassadorAbilityName $abilityName): ?self
    {
        return self::query()->where('code', $abilityName->value)->first();
    }
}
