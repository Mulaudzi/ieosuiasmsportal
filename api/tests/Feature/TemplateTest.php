<?php

namespace Tests\Feature;

use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_user_can_list_templates(): void
    {
        Template::factory()->count(3)->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/templates');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'data' => [
                        '*' => ['id', 'name', 'content', 'type'],
                    ],
                ],
            ]);
    }

    public function test_user_can_create_template(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/templates', [
                'name' => 'Welcome Template',
                'content' => 'Hello {{name}}, welcome to our service!',
                'type' => 'sms',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => ['id', 'name', 'content'],
            ]);

        $this->assertDatabaseHas('templates', ['name' => 'Welcome Template']);
    }

    public function test_user_can_update_template(): void
    {
        $template = Template::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/templates/{$template->id}", [
                'name' => 'Updated Template',
                'content' => 'Updated content',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('templates', ['name' => 'Updated Template']);
    }

    public function test_user_can_delete_template(): void
    {
        $template = Template::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/templates/{$template->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('templates', ['id' => $template->id]);
    }

    public function test_template_requires_name_and_content(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/templates', [
                'type' => 'sms',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'content']);
    }

    public function test_user_cannot_access_other_users_templates(): void
    {
        $otherUser = User::factory()->create();
        $template = Template::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/templates/{$template->id}");

        $response->assertStatus(404);
    }
}
