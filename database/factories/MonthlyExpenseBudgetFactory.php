<?php

namespace Database\Factories;

use App\Models\ExpenseType;
use App\Models\MonthlyExpenseBudget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonthlyExpenseBudget>
 */
class MonthlyExpenseBudgetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'expense_type_id' => ExpenseType::factory(),
            'month' => now()->startOfMonth()->toDateString(),
            'amount' => fake()->numberBetween(10, 500) * 1000,
        ];
    }
}
