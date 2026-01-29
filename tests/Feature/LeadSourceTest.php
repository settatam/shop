<?php

namespace Tests\Feature;

use App\Models\LeadSource;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadSourceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id, 'step' => 2]);

        // Create default roles for the store
        Role::createDefaultRoles($this->store->id);

        // Get the owner role
        $ownerRole = Role::where('store_id', $this->store->id)
            ->where('slug', 'owner')
            ->first();

        // Create store user with owner role
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

        // Set current store on user
        $this->user->update(['current_store_id' => $this->store->id]);
    }

    protected function withStore()
    {
        return $this->withSession(['current_store_id' => $this->store->id]);
    }

    public function test_can_list_lead_sources(): void
    {
        $this->actingAs($this->user);

        LeadSource::factory()->count(3)->create(['store_id' => $this->store->id]);

        $response = $this->withStore()->getJson('/lead-sources');

        $response->assertStatus(200)
            ->assertJsonCount(3);
    }

    public function test_only_active_lead_sources_are_returned(): void
    {
        $this->actingAs($this->user);

        LeadSource::factory()->count(2)->create(['store_id' => $this->store->id, 'is_active' => true]);
        LeadSource::factory()->inactive()->create(['store_id' => $this->store->id]);

        $response = $this->withStore()->getJson('/lead-sources');

        $response->assertStatus(200)
            ->assertJsonCount(2);
    }

    public function test_lead_sources_are_sorted_by_order(): void
    {
        $this->actingAs($this->user);

        LeadSource::factory()->create(['store_id' => $this->store->id, 'name' => 'Third', 'sort_order' => 3]);
        LeadSource::factory()->create(['store_id' => $this->store->id, 'name' => 'First', 'sort_order' => 1]);
        LeadSource::factory()->create(['store_id' => $this->store->id, 'name' => 'Second', 'sort_order' => 2]);

        $response = $this->withStore()->getJson('/lead-sources');

        $response->assertStatus(200)
            ->assertJsonCount(3)
            ->assertJsonPath('0.name', 'First')
            ->assertJsonPath('1.name', 'Second')
            ->assertJsonPath('2.name', 'Third');
    }

    public function test_only_store_lead_sources_are_visible(): void
    {
        $this->actingAs($this->user);

        $otherStore = Store::factory()->create();
        LeadSource::factory()->count(2)->create(['store_id' => $this->store->id]);
        LeadSource::factory()->count(3)->create(['store_id' => $otherStore->id]);

        $response = $this->withStore()->getJson('/lead-sources');

        $response->assertStatus(200)
            ->assertJsonCount(2);
    }

    public function test_can_create_lead_source(): void
    {
        $this->actingAs($this->user);

        $response = $this->withStore()->postJson('/lead-sources', [
            'name' => 'New Lead Source',
            'description' => 'A custom lead source',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('name', 'New Lead Source')
            ->assertJsonPath('description', 'A custom lead source')
            ->assertJsonStructure(['id', 'name', 'slug', 'description']);

        $this->assertDatabaseHas('lead_sources', [
            'store_id' => $this->store->id,
            'name' => 'New Lead Source',
            'slug' => 'new-lead-source',
        ]);
    }

    public function test_lead_source_requires_name(): void
    {
        $this->actingAs($this->user);

        $response = $this->withStore()->postJson('/lead-sources', [
            'description' => 'A description without name',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_lead_source_name_max_length(): void
    {
        $this->actingAs($this->user);

        $response = $this->withStore()->postJson('/lead-sources', [
            'name' => str_repeat('a', 101),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_lead_source_gets_correct_sort_order(): void
    {
        $this->actingAs($this->user);

        LeadSource::factory()->create(['store_id' => $this->store->id, 'sort_order' => 0]);
        LeadSource::factory()->create(['store_id' => $this->store->id, 'sort_order' => 1]);

        $response = $this->withStore()->postJson('/lead-sources', [
            'name' => 'New Source',
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('lead_sources', [
            'store_id' => $this->store->id,
            'name' => 'New Source',
            'sort_order' => 2,
        ]);
    }

    public function test_lead_source_slug_is_generated(): void
    {
        $this->actingAs($this->user);

        $response = $this->withStore()->postJson('/lead-sources', [
            'name' => 'Trade Show Event',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('slug', 'trade-show-event');
    }
}
