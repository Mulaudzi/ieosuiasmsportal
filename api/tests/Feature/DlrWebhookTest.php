<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DlrWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_dlr_webhook_updates_message_status(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create(['user_id' => $user->id]);
        $message = Message::factory()->create([
            'campaign_id' => $campaign->id,
            'external_id' => 'ext_12345',
            'status' => 'Sent',
        ]);

        $response = $this->postJson('/api/dlr/webhook', [
            'messageId' => 'ext_12345',
            'status' => 'DELIVERED',
            'timestamp' => now()->toIso8601String(),
        ]);

        $response->assertStatus(200);

        $message->refresh();
        $this->assertEquals('Delivered', $message->status);
    }

    public function test_dlr_webhook_handles_failed_status(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create(['user_id' => $user->id]);
        $message = Message::factory()->create([
            'campaign_id' => $campaign->id,
            'external_id' => 'ext_failed',
            'status' => 'Sent',
        ]);

        $response = $this->postJson('/api/dlr/webhook', [
            'messageId' => 'ext_failed',
            'status' => 'FAILED',
            'error' => 'Invalid number',
        ]);

        $response->assertStatus(200);

        $message->refresh();
        $this->assertEquals('Failed', $message->status);
    }

    public function test_dlr_webhook_ignores_unknown_message(): void
    {
        $response = $this->postJson('/api/dlr/webhook', [
            'messageId' => 'unknown_123',
            'status' => 'DELIVERED',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_dlr_webhook_handles_logicsms_format(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create(['user_id' => $user->id]);
        $message = Message::factory()->create([
            'campaign_id' => $campaign->id,
            'external_id' => 'logic_789',
            'status' => 'Awaiting DLR',
        ]);

        // LogicSMS format
        $response = $this->postJson('/api/dlr/webhook', [
            'id' => 'logic_789',
            'dlr_status' => '1', // 1 = Delivered in LogicSMS
            'dlr_time' => now()->format('Y-m-d H:i:s'),
        ]);

        $response->assertStatus(200);

        $message->refresh();
        $this->assertEquals('Delivered', $message->status);
    }
}
