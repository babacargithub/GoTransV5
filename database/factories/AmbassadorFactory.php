<?php

namespace Database\Factories;

use App\Enums\AmbassadorStatus;
use App\Models\Ambassador;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ambassador>
 */
class AmbassadorFactory extends Factory
{
    /**
     * Define the model's default state: an active ambassador with a contract running for a year.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone_number' => '77'.fake()->unique()->numerify('#######'),
            'code' => strtoupper(fake()->unique()->lexify('????')).fake()->numerify('##'),
            'status' => AmbassadorStatus::Active,
            'contract_starts_at' => today()->subMonth(),
            'contract_ends_at' => today()->addYear(),
        ];
    }

    public function pendingApproval(): static
    {
        return $this->state(fn (): array => [
            'status' => AmbassadorStatus::PendingApproval,
            'contract_starts_at' => null,
            'contract_ends_at' => null,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => AmbassadorStatus::Suspended]);
    }

    public function withExpiredContract(): static
    {
        return $this->state(fn (): array => [
            'contract_starts_at' => today()->subYear(),
            'contract_ends_at' => today()->subDay(),
        ]);
    }

    public function sponsoredBy(Ambassador $sponsor): static
    {
        return $this->state(fn (): array => ['sponsor_ambassador_id' => $sponsor->id]);
    }
}
