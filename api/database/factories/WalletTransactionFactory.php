<?php

namespace Database\Factories;

use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

class WalletTransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'wallet_id' => Wallet::factory(),
            'amount' => fake()->randomFloat(2, 10, 500),
            'type' => fake()->randomElement(['credit', 'debit', 'refund']),
            'description' => fake()->sentence(),
            'reference' => 'TXN-' . fake()->unique()->numerify('######'),
            'status' => fake()->randomElement(['pending', 'completed', 'failed']),
            'payment_method' => fake()->randomElement(['card', 'eft', 'payfast']),
        ];
    }
}
