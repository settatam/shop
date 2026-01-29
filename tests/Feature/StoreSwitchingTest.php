<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class StoreSwitchingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store1;

    protected Store $store2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // Create first store
        $this->store1 = Store::factory()->create(['user_id' => $this->user->id]);
        $ownerRole1 = Role::factory()->owner()->create(['store_id' => $this->store1->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store1->id,
            'role_id' => $ownerRole1->id,
        ]);

        // Create second store
        $this->store2 = Store::factory()->create(['user_id' => $this->user->id]);
        $ownerRole2 = Role::factory()->owner()->create(['store_id' => $this->store2->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store2->id,
            'role_id' => $ownerRole2->id,
        ]);

        // Set initial store context
        $this->user->update(['current_store_id' => $this->store1->id]);
        app(StoreContext::class)->setCurrentStore($this->store1);
    }

    public function test_can_list_user_stores_via_api(): void
    {
        Passport::actingAs($this->user);

        $response = $this->getJson('/api/v1/stores');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json());

        $storeIds = collect($response->json())->pluck('id')->toArray();
        $this->assertContains($this->store1->id, $storeIds);
        $this->assertContains($this->store2->id, $storeIds);
    }

    public function test_can_switch_store_via_api(): void
    {
        Passport::actingAs($this->user);

        $response = $this->postJson("/api/v1/stores/{$this->store2->id}/switch");

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'Switched to '.$this->store2->name]);

        $this->user->refresh();
        $this->assertEquals($this->store2->id, $this->user->current_store_id);
    }

    public function test_can_switch_store_via_web(): void
    {
        $this->actingAs($this->user);

        $response = $this->post("/stores/{$this->store2->id}/switch");

        $response->assertRedirect(route('dashboard'));

        $this->user->refresh();
        $this->assertEquals($this->store2->id, $this->user->current_store_id);
    }

    public function test_cannot_switch_to_store_without_access(): void
    {
        $otherUser = User::factory()->create();
        $otherStore = Store::factory()->create(['user_id' => $otherUser->id]);

        Passport::actingAs($this->user);

        $response = $this->postJson("/api/v1/stores/{$otherStore->id}/switch");

        $response->assertStatus(403)
            ->assertJsonFragment(['message' => 'Access denied']);
    }

    public function test_can_create_new_store_via_api(): void
    {
        Passport::actingAs($this->user);

        $response = $this->postJson('/api/v1/stores', [
            'name' => 'My New Store',
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['name' => 'My New Store'])
            ->assertJsonFragment(['is_owner' => true]);

        $this->assertDatabaseHas('stores', [
            'name' => 'My New Store',
            'user_id' => $this->user->id,
        ]);

        // Verify owner store user was created
        $newStore = Store::where('name', 'My New Store')->first();
        $this->assertDatabaseHas('store_users', [
            'user_id' => $this->user->id,
            'store_id' => $newStore->id,
            'is_owner' => true,
        ]);
    }

    public function test_can_create_new_store_via_web(): void
    {
        $this->actingAs($this->user);

        $response = $this->post('/stores', [
            'name' => 'My Web Store',
        ]);

        $response->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('stores', [
            'name' => 'My Web Store',
            'user_id' => $this->user->id,
        ]);

        // Should have switched to the new store
        $this->user->refresh();
        $newStore = Store::where('name', 'My Web Store')->first();
        $this->assertEquals($newStore->id, $this->user->current_store_id);
    }

    public function test_store_creation_requires_name(): void
    {
        Passport::actingAs($this->user);

        $response = $this->postJson('/api/v1/stores', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_user_can_be_member_of_another_users_store(): void
    {
        $otherOwner = User::factory()->create();
        $otherStore = Store::factory()->create(['user_id' => $otherOwner->id]);
        $staffRole = Role::factory()->staff()->create(['store_id' => $otherStore->id]);

        // Add user as staff member to other store
        StoreUser::factory()->create([
            'user_id' => $this->user->id,
            'store_id' => $otherStore->id,
            'role_id' => $staffRole->id,
            'is_owner' => false,
        ]);

        Passport::actingAs($this->user);

        // Should be able to list all stores including the one they're a member of
        $response = $this->getJson('/api/v1/stores');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json());

        // Should be able to switch to that store
        $response = $this->postJson("/api/v1/stores/{$otherStore->id}/switch");

        $response->assertStatus(200);

        $this->user->refresh();
        $this->assertEquals($otherStore->id, $this->user->current_store_id);
    }

    public function test_middleware_resolves_store_from_user_current_store_id(): void
    {
        $this->user->update(['current_store_id' => $this->store2->id]);

        $this->actingAs($this->user);

        $response = $this->get('/dashboard');

        $response->assertStatus(200);

        // Store context should have been set to store2
        $this->assertEquals($this->store2->id, app(StoreContext::class)->getCurrentStoreId());
    }
}
