<?php

namespace Database\Factories;

use App\Models\AmbassadorAbility;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AmbassadorAbility>
 */
class AmbassadorAbilityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(2),
            'label' => fake()->sentence(3),
        ];
    }
}
