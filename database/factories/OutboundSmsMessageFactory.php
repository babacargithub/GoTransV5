<?php

namespace Database\Factories;

use App\Enums\OutboundSmsMessageStatus;
use App\Models\OutboundSmsMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutboundSmsMessage>
 */
class OutboundSmsMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recipient_phone_number' => '22177'.fake()->numerify('#######'),
            'content' => fake()->sentence(),
            'status' => OutboundSmsMessageStatus::Pending,
        ];
    }
}
