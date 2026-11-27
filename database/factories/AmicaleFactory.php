<?php

namespace Database\Factories;

use App\Models\Amicale;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Amicale>
 */
class AmicaleFactory extends Factory
{
    /**
     * Define the model's default state: an active amicale earning 200 FCFA per ticket.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'Amicale '.fake()->unique()->lexify('?????');

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'welcome_message' => null,
            'commission_per_ticket' => 200,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
