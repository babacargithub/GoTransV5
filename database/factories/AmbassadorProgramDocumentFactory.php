<?php

namespace Database\Factories;

use App\Enums\AmbassadorProgramDocumentType;
use App\Models\AmbassadorProgramDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AmbassadorProgramDocument>
 */
class AmbassadorProgramDocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => AmbassadorProgramDocumentType::Presentation,
            'title' => fake()->sentence(3),
            'content' => '# '.fake()->sentence(3)."\n\n".fake()->paragraph(),
        ];
    }

    public function contract(): static
    {
        return $this->state(fn (): array => ['type' => AmbassadorProgramDocumentType::Contract]);
    }
}
