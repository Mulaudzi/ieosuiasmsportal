<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->wallet = Wallet::factory()->create([
            'user_id' => $this->user->id,
            'balance' => 500.00,
        ]);
    }

    public function test_user_can_view_wallet_balance(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/wallet/balance');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => ['balance', 'reserved', 'available'],
            ]);
    }

    public function test_user_can_view_transaction_history(): void
    {
        WalletTransaction::factory()->count(5)->create([
            'wallet_id' => $this->wallet->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/wallet/transactions');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'data' => [
                        '*' => ['id', 'amount', 'type', 'description'],
                    ],
                ],
            ]);
    }

    public function test_user_can_buy_credits(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/wallet/buy', [
                'amount' => 100.00,
                'payment_method' => 'card',
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => ['payment_url'],
            ]);
    }

    public function test_wallet_balance_updates_on_credit(): void
    {
        $initialBalance = $this->wallet->balance;

        $this->wallet->credit(50.00, 'Test credit');
        $this->wallet->refresh();

        $this->assertEquals($initialBalance + 50.00, $this->wallet->balance);
    }

    public function test_wallet_balance_updates_on_debit(): void
    {
        $initialBalance = $this->wallet->balance;

        $this->wallet->debit(25.00, 'Test debit');
        $this->wallet->refresh();

        $this->assertEquals($initialBalance - 25.00, $this->wallet->balance);
    }

    public function test_wallet_can_reserve_funds(): void
    {
        $result = $this->wallet->reserve(100.00);

        $this->assertTrue($result);
        $this->assertEquals(100.00, $this->wallet->reserved);
        $this->assertEquals(400.00, $this->wallet->available_balance);
    }

    public function test_wallet_cannot_reserve_more_than_available(): void
    {
        $result = $this->wallet->reserve(600.00);

        $this->assertFalse($result);
        $this->assertEquals(0.00, $this->wallet->reserved);
    }

    public function test_wallet_can_release_reservation(): void
    {
        $this->wallet->reserve(100.00);
        $this->wallet->releaseReservation(50.00);

        $this->assertEquals(50.00, $this->wallet->reserved);
    }
}
