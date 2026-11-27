<?php

namespace Database\Factories;

use App\Enums\ScreenshotBatchStatus;
use App\Models\ScreenshotBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ScreenshotBatch>
 */
class ScreenshotBatchFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'note' => null,
            'status' => ScreenshotBatchStatus::Pending,
            'file_count' => 0,
        ];
    }
}
