<?php

namespace Database\Factories;

use App\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;

class MessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'recipient' => '+27' . fake()->numerify('8########'),
            'content' => fake()->sentence(),
            'status' => fake()->randomElement(['Pending', 'Queued', 'Sent', 'Delivered', 'Failed']),
            'external_id' => 'msg_' . fake()->unique()->uuid(),
            'cost' => fake()->randomFloat(2, 0.10, 0.50),
            'sent_at' => fake()->optional()->dateTimeBetween('-1 week', 'now'),
        ];
    }
}
