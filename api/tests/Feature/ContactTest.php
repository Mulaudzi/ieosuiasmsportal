<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_user_can_list_contacts(): void
    {
        Contact::factory()->count(5)->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/contacts');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'data' => [
                        '*' => ['id', 'name', 'phone', 'email'],
                    ],
                ],
            ]);
    }

    public function test_user_can_create_contact(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/contacts', [
                'name' => 'John Doe',
                'phone' => '+27821234567',
                'email' => 'john@example.com',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => ['id', 'name', 'phone'],
            ]);

        $this->assertDatabaseHas('contacts', ['phone' => '+27821234567']);
    }

    public function test_user_can_update_contact(): void
    {
        $contact = Contact::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/contacts/{$contact->id}", [
                'name' => 'Updated Name',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('contacts', ['name' => 'Updated Name']);
    }

    public function test_user_can_delete_contact(): void
    {
        $contact = Contact::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/contacts/{$contact->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('contacts', ['id' => $contact->id]);
    }

    public function test_user_can_import_contacts_csv(): void
    {
        Storage::fake('local');

        $csv = "name,phone,email\nJohn Doe,+27821234567,john@test.com\nJane Smith,+27831234567,jane@test.com";
        $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/contacts/import', [
                'file' => $file,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => ['imported', 'failed', 'duplicates'],
            ]);
    }

    public function test_user_can_create_contact_group(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/contacts/groups', [
                'name' => 'VIP Customers',
                'description' => 'Premium customers group',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('contact_groups', ['name' => 'VIP Customers']);
    }

    public function test_user_can_add_contacts_to_group(): void
    {
        $group = ContactGroup::factory()->create(['user_id' => $this->user->id]);
        $contacts = Contact::factory()->count(3)->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/contacts/groups/{$group->id}/contacts", [
                'contact_ids' => $contacts->pluck('id')->toArray(),
            ]);

        $response->assertStatus(200);
    }

    public function test_phone_number_must_be_valid(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/contacts', [
                'name' => 'Test',
                'phone' => 'invalid',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }
}
