<?php

namespace Tests\Feature\StorefrontChat;

use App\Models\Role;
use App\Models\Store;
use App\Models\StoreKnowledgeBaseEntry;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeBaseTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id, 'step' => 2]);

        Role::createDefaultRoles($this->store->id);

        $ownerRole = Role::where('store_id', $this->store->id)
            ->where('slug', 'owner')
            ->first();

        StoreUser::create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $ownerRole->id,
            'is_owner' => true,
            'status' => 'active',
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $this->user->email,
        ]);

        $this->user->update(['current_store_id' => $this->store->id]);
    }

    public function test_index_shows_knowledge_base_page(): void
    {
        StoreKnowledgeBaseEntry::factory()->returnPolicy()->create([
            'store_id' => $this->store->id,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/settings/knowledge-base');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('settings/KnowledgeBase')
            ->has('entries', 1)
            ->has('types')
        );
    }

    public function test_create_knowledge_base_entry(): void
    {
        $response = $this->actingAs($this->user)
            ->post('/settings/knowledge-base', [
                'type' => 'return_policy',
                'title' => '30-Day Returns',
                'content' => 'We accept returns within 30 days of purchase.',
                'is_active' => true,
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('store_knowledge_base_entries', [
            'store_id' => $this->store->id,
            'type' => 'return_policy',
            'title' => '30-Day Returns',
        ]);
    }

    public function test_create_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user)
            ->post('/settings/knowledge-base', []);

        $response->assertSessionHasErrors(['type', 'title', 'content']);
    }

    public function test_create_validates_type(): void
    {
        $response = $this->actingAs($this->user)
            ->post('/settings/knowledge-base', [
                'type' => 'invalid_type',
                'title' => 'Test',
                'content' => 'Test content',
            ]);

        $response->assertSessionHasErrors(['type']);
    }

    public function test_update_knowledge_base_entry(): void
    {
        $entry = StoreKnowledgeBaseEntry::factory()->returnPolicy()->create([
            'store_id' => $this->store->id,
        ]);

        $response = $this->actingAs($this->user)
            ->put("/settings/knowledge-base/{$entry->id}", [
                'type' => 'shipping_info',
                'title' => 'Updated Title',
                'content' => 'Updated content here.',
            ]);

        $response->assertRedirect();

        $entry->refresh();
        $this->assertEquals('Updated Title', $entry->title);
        $this->assertEquals('shipping_info', $entry->type);
    }

    public function test_cannot_update_entry_from_other_store(): void
    {
        $otherStore = Store::factory()->create();
        $entry = StoreKnowledgeBaseEntry::factory()->create([
            'store_id' => $otherStore->id,
        ]);

        $response = $this->actingAs($this->user)
            ->put("/settings/knowledge-base/{$entry->id}", [
                'type' => 'faq',
                'title' => 'Hacked',
                'content' => 'Hacked content',
            ]);

        $response->assertNotFound();
    }

    public function test_delete_knowledge_base_entry(): void
    {
        $entry = StoreKnowledgeBaseEntry::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $response = $this->actingAs($this->user)
            ->delete("/settings/knowledge-base/{$entry->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('store_knowledge_base_entries', ['id' => $entry->id]);
    }

    public function test_cannot_delete_entry_from_other_store(): void
    {
        $otherStore = Store::factory()->create();
        $entry = StoreKnowledgeBaseEntry::factory()->create([
            'store_id' => $otherStore->id,
        ]);

        $response = $this->actingAs($this->user)
            ->delete("/settings/knowledge-base/{$entry->id}");

        $response->assertNotFound();
        $this->assertDatabaseHas('store_knowledge_base_entries', ['id' => $entry->id]);
    }

    public function test_entries_scoped_to_current_store(): void
    {
        StoreKnowledgeBaseEntry::factory()->returnPolicy()->create([
            'store_id' => $this->store->id,
        ]);

        $otherStore = Store::factory()->create();
        StoreKnowledgeBaseEntry::factory()->shippingInfo()->create([
            'store_id' => $otherStore->id,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/settings/knowledge-base');

        $response->assertInertia(fn ($page) => $page
            ->has('entries', 1)
        );
    }
}
