<?php

namespace Database\Factories;

use App\Models\ExpenseType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpenseType>
 */
class ExpenseTypeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => null,
            'name' => fake()->unique()->words(2, true),
            'is_system' => false,
            'is_active' => true,
            'is_planned_monthly' => false,
        ];
    }

    public function plannedMonthly(): static
    {
        return $this->state(['is_planned_monthly' => true]);
    }
}
