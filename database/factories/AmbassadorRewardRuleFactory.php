<?php

namespace Database\Factories;

use App\Enums\AmbassadorEarningType;
use App\Models\Ambassador;
use App\Models\AmbassadorRewardRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AmbassadorRewardRule>
 */
class AmbassadorRewardRuleFactory extends Factory
{
    /**
     * An enabled general acquisition rule without period limits.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'earning_type' => AmbassadorEarningType::Acquisition,
            'amount' => 500,
            'is_enabled' => true,
        ];
    }

    public function forExistingCustomerBookings(): static
    {
        return $this->state(fn (): array => ['earning_type' => AmbassadorEarningType::ExistingCustomerBooking]);
    }

    public function paused(): static
    {
        return $this->state(fn (): array => ['is_enabled' => false]);
    }

    public function forAmbassador(Ambassador $ambassador): static
    {
        return $this->state(fn (): array => ['ambassador_id' => $ambassador->id]);
    }
}
