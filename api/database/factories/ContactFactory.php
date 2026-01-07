<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContactFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'phone' => fake()->numerify('278########'),
            'email' => fake()->unique()->safeEmail(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'company' => fake()->optional(0.3)->company(),
            'custom_fields' => null,
            'opt_out' => fake()->boolean(5), // 5% opted out
            'source' => fake()->randomElement(['manual', 'import', 'api', 'form']),
        ];
    }

    public function optedOut(): static
    {
        return $this->state(fn (array $attributes) => [
            'opt_out' => true,
        ]);
    }

    public function withPhone(): static
    {
        return $this->state(fn (array $attributes) => [
            'phone' => fake()->numerify('278########'),
        ]);
    }

    public function withEmail(): static
    {
        return $this->state(fn (array $attributes) => [
            'email' => fake()->unique()->safeEmail(),
        ]);
    }
}
