<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Status;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
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
        $this->store = Store::factory()->withOnlineBuysWorkflow()->create([
            'user_id' => $this->user->id,
            'step' => 2, // Mark onboarding as complete
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

    public function test_can_view_leads_dashboard(): void
    {
        // Create some statuses for the store
        $status1 = Status::factory()->create([
            'store_id' => $this->store->id,
            'entity_type' => 'transaction',
            'name' => 'Pending Kit Request',
            'slug' => 'pending_kit_request',
        ]);

        $status2 = Status::factory()->create([
            'store_id' => $this->store->id,
            'entity_type' => 'transaction',
            'name' => 'Items Received',
            'slug' => 'items_received',
        ]);

        // Create some mail-in transactions
        Transaction::factory()->count(3)->create([
            'store_id' => $this->store->id,
            'type' => Transaction::TYPE_MAIL_IN,
            'status' => 'pending_kit_request',
            'status_id' => $status1->id,
        ]);

        Transaction::factory()->count(2)->create([
            'store_id' => $this->store->id,
            'type' => Transaction::TYPE_MAIL_IN,
            'status' => 'items_received',
            'status_id' => $status2->id,
        ]);

        $response = $this->actingAs($this->user)->get('/leads');

        $response->assertStatus(200);
        $response->assertInertia(
            fn ($page) => $page
                ->component('leads/Dashboard')
                ->has('statusCounts')
                ->has('summary')
                ->where('summary.active_leads', 5)
        );
    }

    public function test_leads_dashboard_requires_online_buys_workflow(): void
    {
        // Create a store without online buys workflow
        $regularStore = Store::factory()->create([
            'user_id' => $this->user->id,
            'step' => 2,
        ]);

        $role = Role::factory()->owner()->create(['store_id' => $regularStore->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $regularStore->id,
            'role_id' => $role->id,
        ]);

        $this->user->update(['current_store_id' => $regularStore->id]);
        app(StoreContext::class)->setCurrentStore($regularStore);

        $response = $this->actingAs($this->user)->get('/leads');

        $response->assertStatus(403);
    }

    public function test_can_view_leads_by_status(): void
    {
        $status = Status::factory()->create([
            'store_id' => $this->store->id,
            'entity_type' => 'transaction',
            'name' => 'Pending Kit Request',
            'slug' => 'pending_kit_request',
        ]);

        Transaction::factory()->count(3)->create([
            'store_id' => $this->store->id,
            'type' => Transaction::TYPE_MAIL_IN,
            'status' => 'pending_kit_request',
            'status_id' => $status->id,
        ]);

        $response = $this->actingAs($this->user)->get('/leads/status/pending_kit_request');

        $response->assertStatus(200);
        $response->assertInertia(
            fn ($page) => $page
                ->component('leads/Index')
                ->has('leads.data', 3)
                ->has('currentStatus')
                ->where('currentStatus.slug', 'pending_kit_request')
        );
    }

    public function test_can_view_lead_detail(): void
    {
        $status = Status::factory()->create([
            'store_id' => $this->store->id,
            'entity_type' => 'transaction',
            'name' => 'Items Received',
            'slug' => 'items_received',
        ]);

        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'type' => Transaction::TYPE_MAIL_IN,
            'status' => 'items_received',
            'status_id' => $status->id,
        ]);

        $response = $this->actingAs($this->user)->get("/leads/{$transaction->id}");

        $response->assertStatus(200);
        $response->assertInertia(
            fn ($page) => $page
                ->component('leads/Show')
                ->has('lead')
                ->has('availableTransitions')
                ->has('statusHistory')
        );
    }

    public function test_cannot_view_lead_from_another_store(): void
    {
        $otherStore = Store::factory()->withOnlineBuysWorkflow()->create();

        $transaction = Transaction::factory()->create([
            'store_id' => $otherStore->id,
            'type' => Transaction::TYPE_MAIL_IN,
            'status' => 'pending_kit_request',
        ]);

        $response = $this->actingAs($this->user)->get("/leads/{$transaction->id}");

        $response->assertStatus(404);
    }

    public function test_dashboard_summary_counts_converted_leads(): void
    {
        // Create statuses
        Status::factory()->create([
            'store_id' => $this->store->id,
            'entity_type' => 'transaction',
            'name' => 'Payment Processed',
            'slug' => 'payment_processed',
            'is_final' => true,
        ]);

        Status::factory()->create([
            'store_id' => $this->store->id,
            'entity_type' => 'transaction',
            'name' => 'Pending Kit Request',
            'slug' => 'pending_kit_request',
        ]);

        // Create converted (payment_processed) transactions
        Transaction::factory()->count(2)->create([
            'store_id' => $this->store->id,
            'type' => Transaction::TYPE_MAIL_IN,
            'status' => 'payment_processed',
            'final_offer' => 500.00,
        ]);

        // Create active transactions
        Transaction::factory()->count(3)->create([
            'store_id' => $this->store->id,
            'type' => Transaction::TYPE_MAIL_IN,
            'status' => 'pending_kit_request',
            'estimated_value' => 200.00,
        ]);

        $response = $this->actingAs($this->user)->get('/leads');

        $response->assertStatus(200);
        $response->assertInertia(
            fn ($page) => $page
                ->component('leads/Dashboard')
                ->where('summary.active_leads', 3)
                ->where('summary.total_converted', 2)
                ->where('summary.total_converted_value', 1000)
                ->where('summary.potential_value', 600)
        );
    }

    public function test_only_mail_in_transactions_are_shown(): void
    {
        $status = Status::factory()->create([
            'store_id' => $this->store->id,
            'entity_type' => 'transaction',
            'name' => 'Pending Kit Request',
            'slug' => 'pending_kit_request',
        ]);

        // Create mail-in transactions (should be shown)
        Transaction::factory()->count(2)->create([
            'store_id' => $this->store->id,
            'type' => Transaction::TYPE_MAIL_IN,
            'status' => 'pending_kit_request',
            'status_id' => $status->id,
        ]);

        // Create regular buy transactions (should not be shown)
        Transaction::factory()->count(3)->create([
            'store_id' => $this->store->id,
            'type' => Transaction::TYPE_BUY,
            'status' => 'pending_kit_request',
            'status_id' => $status->id,
        ]);

        $response = $this->actingAs($this->user)->get('/leads/status/pending_kit_request');

        $response->assertStatus(200);
        $response->assertInertia(
            fn ($page) => $page
                ->component('leads/Index')
                ->has('leads.data', 2) // Only mail-in transactions
        );
    }
}
