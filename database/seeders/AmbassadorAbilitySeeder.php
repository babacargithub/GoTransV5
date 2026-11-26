<?php

namespace Database\Seeders;

use App\Enums\AmbassadorAbilityName;
use App\Models\AmbassadorAbility;
use Illuminate\Database\Seeder;

/**
 * Seeds the ambassador abilities catalogue. Idempotent: only creates missing codes, so a label reworded in
 * the database is never overwritten. Granting abilities to ambassadors is done from the back office.
 */
class AmbassadorAbilitySeeder extends Seeder
{
    public function run(): void
    {
        foreach (AmbassadorAbilityName::cases() as $abilityName) {
            AmbassadorAbility::query()->firstOrCreate(
                ['code' => $abilityName->value],
                ['label' => $abilityName->defaultLabel()],
            );
        }
    }
}
