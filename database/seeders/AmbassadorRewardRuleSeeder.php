<?php

namespace Database\Seeders;

use App\Enums\AmbassadorEarningType;
use App\Models\AmbassadorRewardRule;
use App\Models\AppParams;
use Illuminate\Database\Seeder;

/**
 * Makes sure the general (all ambassadors) reward rules the program cannot run without exist. Safe to run
 * again: a rule that already exists, whatever its amount or state, is left as the back office set it.
 */
class AmbassadorRewardRuleSeeder extends Seeder
{
    private const DEFAULT_SPONSORSHIP_BONUS_AMOUNT = 2000;

    public function run(): void
    {
        $legacySponsorshipBonusAmount = AppParams::query()->first()?->data['ambassador_sponsorship_bonus_amount'] ?? null;

        AmbassadorRewardRule::query()->firstOrCreate(
            ['earning_type' => AmbassadorEarningType::SponsorshipBonus, 'ambassador_id' => null],
            ['amount' => (int) ($legacySponsorshipBonusAmount ?? self::DEFAULT_SPONSORSHIP_BONUS_AMOUNT), 'is_enabled' => true],
        );
    }
}
