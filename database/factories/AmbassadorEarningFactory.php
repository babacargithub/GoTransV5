<?php

namespace Database\Factories;

use App\Enums\AmbassadorEarningStatus;
use App\Enums\AmbassadorEarningType;
use App\Models\Ambassador;
use App\Models\AmbassadorEarning;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AmbassadorEarning>
 */
class AmbassadorEarningFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ambassador_id' => Ambassador::factory(),
            'type' => AmbassadorEarningType::Acquisition,
            'amount' => 500,
            'status' => AmbassadorEarningStatus::Pending,
            'idempotency_key' => 'test:'.Str::uuid(),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => AmbassadorEarningStatus::Approved,
            'approved_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => AmbassadorEarningStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }
}
