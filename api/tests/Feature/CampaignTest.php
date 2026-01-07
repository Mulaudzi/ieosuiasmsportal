<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Wallet::factory()->create([
            'user_id' => $this->user->id,
            'balance' => 1000.00,
        ]);
    }

    public function test_user_can_list_campaigns(): void
    {
        Campaign::factory()->count(3)->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/sms/campaigns');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'data' => [
                        '*' => ['id', 'name', 'status', 'type'],
                    ],
                ],
            ]);
    }

    public function test_user_can_create_sms_campaign(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/sms/campaigns', [
                'name' => 'Test Campaign',
                'message' => 'Hello, this is a test message!',
                'sender_id' => 'IEOSUIA',
                'recipients' => ['+27821234567', '+27831234567'],
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => ['id', 'name', 'status'],
            ]);

        $this->assertDatabaseHas('campaigns', ['name' => 'Test Campaign']);
    }

    public function test_user_cannot_create_campaign_without_name(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/sms/campaigns', [
                'message' => 'Hello!',
                'sender_id' => 'IEOSUIA',
                'recipients' => ['+27821234567'],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_user_can_view_single_campaign(): void
    {
        $campaign = Campaign::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/sms/campaigns/{$campaign->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => ['id', 'name', 'status'],
            ]);
    }

    public function test_user_cannot_view_other_users_campaign(): void
    {
        $otherUser = User::factory()->create();
        $campaign = Campaign::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/sms/campaigns/{$campaign->id}");

        $response->assertStatus(404);
    }

    public function test_user_can_delete_draft_campaign(): void
    {
        $campaign = Campaign::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'Draft',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/sms/campaigns/{$campaign->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('campaigns', ['id' => $campaign->id]);
    }

    public function test_campaign_requires_minimum_recipients(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/sms/campaigns', [
                'name' => 'Test Campaign',
                'message' => 'Hello!',
                'sender_id' => 'IEOSUIA',
                'recipients' => [],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['recipients']);
    }

    public function test_user_can_schedule_campaign(): void
    {
        $scheduledTime = now()->addHours(2)->format('Y-m-d H:i:s');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/sms/campaigns', [
                'name' => 'Scheduled Campaign',
                'message' => 'Hello!',
                'sender_id' => 'IEOSUIA',
                'recipients' => ['+27821234567'],
                'scheduled_at' => $scheduledTime,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('campaigns', [
            'name' => 'Scheduled Campaign',
            'status' => 'Scheduled',
        ]);
    }
}
