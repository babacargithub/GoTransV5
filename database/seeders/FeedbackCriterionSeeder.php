<?php

namespace Database\Seeders;

use App\Models\FeedbackCriterion;
use Illuminate\Database\Seeder;

/**
 * The starter criteria agents rate during a feedback call. Idempotent: only creates missing codes, so
 * labels, positions and activation edited later are never overwritten.
 */
class FeedbackCriterionSeeder extends Seeder
{
    /**
     * @var array<string, string>
     */
    private const STARTER_CRITERIA_LABEL_BY_CODE = [
        'driver' => 'Chauffeur',
        'punctuality' => 'Ponctualité',
        'staff-behaviour' => 'Comportement du personnel',
        'speed' => 'Rapidité du trajet',
        'comfort' => 'Confort',
        'cleanliness' => 'Propreté',
    ];

    public function run(): void
    {
        $position = 1;

        foreach (self::STARTER_CRITERIA_LABEL_BY_CODE as $code => $label) {
            FeedbackCriterion::query()->firstOrCreate(
                ['code' => $code],
                ['label' => $label, 'position' => $position, 'is_active' => true],
            );
            $position++;
        }
    }
}
