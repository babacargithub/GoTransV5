<?php

namespace Database\Factories;

use App\Enums\FeedbackCallOutcome;
use App\Models\DepartFeedback;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Callers must pass depart_id, customer_id and booking_id: there is no booking factory, so these
 * belong to rows the test builds itself.
 *
 * @extends Factory<DepartFeedback>
 */
class DepartFeedbackFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [];
    }

    public function withOutcome(FeedbackCallOutcome $outcome): static
    {
        return $this->state([
            'outcome' => $outcome,
            'called_at' => now()->subMinutes(5),
            'completed_at' => $outcome === FeedbackCallOutcome::Completed || $outcome === FeedbackCallOutcome::Refused ? now() : null,
        ]);
    }
}
