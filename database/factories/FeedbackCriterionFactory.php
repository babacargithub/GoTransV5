<?php

namespace Database\Factories;

use App\Models\FeedbackCriterion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeedbackCriterion>
 */
class FeedbackCriterionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(2),
            'label' => fake()->unique()->words(2, true),
            'position' => fake()->numberBetween(1, 20),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
