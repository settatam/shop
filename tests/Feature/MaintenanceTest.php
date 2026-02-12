<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create([
            'user_id' => $this->user->id,
            'step' => 2,
        ]);

        StoreUser::factory()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'is_owner' => true,
        ]);

        $this->user->update(['current_store_id' => $this->store->id]);
    }

    public function test_maintenance_page_is_displayed(): void
    {
        $response = $this->actingAs($this->user)->get('/settings/maintenance');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('settings/Maintenance')
            ->has('searchableModels')
        );
    }

    public function test_guest_cannot_access_maintenance_page(): void
    {
        $response = $this->get('/settings/maintenance');

        $response->assertRedirect('/login');
    }

    public function test_reindex_search_returns_success(): void
    {
        $response = $this->actingAs($this->user)->postJson('/settings/maintenance/reindex-search');

        $response->assertOk();
        $response->assertJsonStructure(['success', 'message']);
    }

    public function test_reindex_specific_model_returns_success(): void
    {
        $response = $this->actingAs($this->user)->postJson('/settings/maintenance/reindex-model/products');

        $response->assertOk();
        $response->assertJson(['success' => true]);
    }

    public function test_reindex_invalid_model_returns_error(): void
    {
        $response = $this->actingAs($this->user)->postJson('/settings/maintenance/reindex-model/invalid_model');

        $response->assertStatus(400);
        $response->assertJson(['error' => 'Invalid model specified.']);
    }
}
