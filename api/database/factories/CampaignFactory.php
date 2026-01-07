<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CampaignFactory extends Factory
{
    public function definition(): array
    {
        $channel = fake()->randomElement(['sms', 'email']);
        $totalRecipients = fake()->numberBetween(10, 500);
        $sentCount = fake()->numberBetween(0, $totalRecipients);
        $deliveredCount = (int) ($sentCount * fake()->randomFloat(2, 0.85, 0.98));
        $failedCount = $sentCount - $deliveredCount;

        return [
            'user_id' => User::factory(),
            'name' => fake()->sentence(3),
            'channel' => $channel,
            'status' => fake()->randomElement(['Draft', 'Completed', 'Sent']),
            'message' => fake()->paragraph(),
            'subject' => $channel === 'email' ? fake()->sentence() : null,
            'sender_id' => $channel === 'sms' ? 'IEOSUIA' : null,
            'from_email' => $channel === 'email' ? 'noreply@ieosuia.com' : null,
            'from_name' => $channel === 'email' ? 'IEOSUIA' : null,
            'total_recipients' => $totalRecipients,
            'sent_count' => $sentCount,
            'delivered_count' => $deliveredCount,
            'failed_count' => $failedCount,
            'estimated_cost' => $totalRecipients * 0.38,
            'actual_cost' => $deliveredCount * 0.38,
        ];
    }

    public function sms(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => 'sms',
            'sender_id' => 'IEOSUIA',
            'subject' => null,
            'from_email' => null,
            'from_name' => null,
        ]);
    }

    public function email(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => 'email',
            'sender_id' => null,
            'subject' => fake()->sentence(),
            'from_email' => 'noreply@ieosuia.com',
            'from_name' => 'IEOSUIA',
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'Draft',
            'sent_count' => 0,
            'delivered_count' => 0,
            'failed_count' => 0,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'Completed',
            'sent_at' => now()->subHours(fake()->numberBetween(1, 72)),
            'completed_at' => now()->subHours(fake()->numberBetween(0, 24)),
        ]);
    }
}
