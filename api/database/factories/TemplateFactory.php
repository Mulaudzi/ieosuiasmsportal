<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TemplateFactory extends Factory
{
    public function definition(): array
    {
        $channel = fake()->randomElement(['sms', 'email']);

        return [
            'user_id' => User::factory(),
            'name' => fake()->words(3, true),
            'channel' => $channel,
            'content' => $channel === 'sms' 
                ? fake()->sentence() . ' {name}' 
                : '<h1>Hello {name}</h1><p>' . fake()->paragraph() . '</p>',
            'subject' => $channel === 'email' ? fake()->sentence() : null,
            'variables' => ['name'],
            'category' => fake()->randomElement(['Marketing', 'Transactional', 'Reminders', 'General']),
            'is_default' => false,
        ];
    }

    public function sms(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => 'sms',
            'subject' => null,
            'content' => fake()->sentence() . ' {name}',
        ]);
    }

    public function email(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => 'email',
            'subject' => fake()->sentence(),
            'content' => '<h1>Hello {name}</h1><p>' . fake()->paragraph() . '</p>',
        ]);
    }
}
