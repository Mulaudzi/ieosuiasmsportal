<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'phone' => fake()->numerify('278########'),
            'account_type' => fake()->randomElement(['individual', 'business', 'enterprise']),
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function business(): static
    {
        return $this->state(fn (array $attributes) => [
            'account_type' => 'business',
        ]);
    }

    public function enterprise(): static
    {
        return $this->state(fn (array $attributes) => [
            'account_type' => 'enterprise',
        ]);
    }
}
