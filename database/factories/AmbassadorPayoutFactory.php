<?php

namespace Database\Factories;

use App\Enums\AmbassadorPayoutStatus;
use App\Models\Ambassador;
use App\Models\AmbassadorPayout;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AmbassadorPayout>
 */
class AmbassadorPayoutFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ambassador_id' => Ambassador::factory(),
            'amount' => 5000,
            'wave_phone_number' => '77'.fake()->numerify('#######'),
            'status' => AmbassadorPayoutStatus::Pending,
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    public function succeeded(): static
    {
        return $this->state(fn (): array => [
            'status' => AmbassadorPayoutStatus::Succeeded,
            'provider_payout_id' => 'wave_'.Str::random(12),
            'paid_at' => now(),
        ]);
    }
}
