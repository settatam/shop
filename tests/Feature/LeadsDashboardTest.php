<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadsDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->onboarded()->create([
            'user_id' => $this->user->id,
        ]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        $this->user->update(['current_store_id' => $this->store->id]);
        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_can_view_leads_index(): void
    {
        Lead::factory()->count(3)->create(['store_id' => $this->store->id]);

        $response = $this->actingAs($this->user)->get('/leads');

        $response->assertStatus(200);
        $response->assertInertia(
            fn ($page) => $page
                ->component('leads/Index')
                ->has('statuses')
        );
    }

    public function test_can_view_lead_detail(): void
    {
        $lead = Lead::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $response = $this->actingAs($this->user)->get("/leads/{$lead->id}");

        $response->assertStatus(200);
        $response->assertInertia(
            fn ($page) => $page
                ->component('leads/Show')
                ->has('lead')
                ->has('statuses')
                ->has('paymentMethods')
        );
    }

    public function test_cannot_view_lead_from_another_store(): void
    {
        $otherStore = Store::factory()->onboarded()->create();

        $lead = Lead::factory()->create([
            'store_id' => $otherStore->id,
        ]);

        $response = $this->actingAs($this->user)->get("/leads/{$lead->id}");

        $response->assertStatus(404);
    }

    public function test_leads_are_scoped_to_current_store(): void
    {
        $otherStore = Store::factory()->onboarded()->create();

        // Create leads for both stores
        Lead::factory()->count(3)->create(['store_id' => $this->store->id]);
        Lead::factory()->count(2)->create(['store_id' => $otherStore->id]);

        $response = $this->actingAs($this->user)->get('/leads');

        $response->assertStatus(200);
    }

    public function test_can_create_lead_from_index(): void
    {
        $response = $this->actingAs($this->user)->get('/leads/create');

        $response->assertRedirect();

        $this->assertDatabaseHas('leads', [
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'status' => Lead::STATUS_PENDING_KIT_REQUEST,
        ]);
    }

    public function test_lead_statuses_are_available(): void
    {
        $response = $this->actingAs($this->user)->get('/leads');

        $response->assertStatus(200);
        $response->assertInertia(
            fn ($page) => $page
                ->component('leads/Index')
                ->has('statuses')
        );
    }
}
