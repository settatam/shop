<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadItem;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->onboarded()->create(['user_id' => $this->user->id]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
        $this->actingAs($this->user);
    }

    public function test_can_view_leads_index(): void
    {
        Lead::factory()->count(3)->create(['store_id' => $this->store->id]);

        $response = $this->get('/leads');

        $response->assertStatus(200);
    }

    public function test_can_create_lead(): void
    {
        $response = $this->get('/leads/create');

        $response->assertRedirect();

        $this->assertDatabaseHas('leads', [
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'status' => Lead::STATUS_PENDING_KIT_REQUEST,
        ]);
    }

    public function test_lead_number_is_auto_generated(): void
    {
        $lead = Lead::factory()->create(['store_id' => $this->store->id]);

        $this->assertNotNull($lead->lead_number);
        $this->assertNotEquals('LEAD-TEMP', $lead->lead_number);
        $this->assertStringContainsString((string) $lead->id, $lead->lead_number);
    }

    public function test_lead_number_uses_store_prefix(): void
    {
        $this->store->update(['lead_id_prefix' => 'BMG']);

        $lead = Lead::factory()->create(['store_id' => $this->store->id]);

        $this->assertStringStartsWith('BMG-', $lead->lead_number);
    }

    public function test_can_view_lead_show(): void
    {
        $lead = Lead::factory()->create(['store_id' => $this->store->id]);

        $response = $this->get("/leads/{$lead->id}");

        $response->assertStatus(200);
    }

    public function test_can_update_lead(): void
    {
        $lead = Lead::factory()->create(['store_id' => $this->store->id]);

        $response = $this->put("/leads/{$lead->id}", [
            'bin_location' => 'BIN-42A',
            'internal_notes' => 'Updated notes',
        ]);

        $response->assertRedirect();

        $lead->refresh();
        $this->assertEquals('BIN-42A', $lead->bin_location);
        $this->assertEquals('Updated notes', $lead->internal_notes);
    }

    public function test_can_update_lead_customer(): void
    {
        $lead = Lead::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => null,
        ]);
        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        $response = $this->put("/leads/{$lead->id}", [
            'customer_id' => $customer->id,
        ]);

        $response->assertRedirect();
        $lead->refresh();
        $this->assertEquals($customer->id, $lead->customer_id);
    }

    public function test_can_delete_lead(): void
    {
        $lead = Lead::factory()->create(['store_id' => $this->store->id]);

        $response = $this->delete("/leads/{$lead->id}");

        $response->assertRedirect();
        $this->assertSoftDeleted('leads', ['id' => $lead->id]);
    }

    public function test_cannot_view_other_store_leads(): void
    {
        $otherStore = Store::factory()->onboarded()->create();
        $lead = Lead::factory()->create(['store_id' => $otherStore->id]);

        $response = $this->get("/leads/{$lead->id}");

        $response->assertStatus(404);
    }

    public function test_can_bulk_delete_leads(): void
    {
        $leads = Lead::factory()->count(3)->create(['store_id' => $this->store->id]);

        $response = $this->post('/leads/bulk-action', [
            'action' => 'delete',
            'ids' => $leads->pluck('id')->toArray(),
        ]);

        $response->assertRedirect();

        foreach ($leads as $lead) {
            $this->assertSoftDeleted('leads', ['id' => $lead->id]);
        }
    }

    public function test_can_assign_lead(): void
    {
        $lead = Lead::factory()->create(['store_id' => $this->store->id]);
        $assignee = User::factory()->create();

        $response = $this->post("/leads/{$lead->id}/assign", [
            'assigned_to' => $assignee->id,
        ]);

        $response->assertRedirect();
        $lead->refresh();
        $this->assertEquals($assignee->id, $lead->assigned_to);
    }

    public function test_lead_has_items_relationship(): void
    {
        $lead = Lead::factory()->create(['store_id' => $this->store->id]);
        LeadItem::factory()->count(3)->create(['lead_id' => $lead->id]);

        $lead->load('items');

        $this->assertCount(3, $lead->items);
    }

    public function test_lead_total_calculations(): void
    {
        $lead = Lead::factory()->create(['store_id' => $this->store->id]);
        LeadItem::factory()->create([
            'lead_id' => $lead->id,
            'price' => 100.00,
            'buy_price' => 80.00,
            'dwt' => 5.0,
        ]);
        LeadItem::factory()->create([
            'lead_id' => $lead->id,
            'price' => 200.00,
            'buy_price' => 150.00,
            'dwt' => 3.5,
        ]);

        $lead->load('items');

        $this->assertEquals(2, $lead->item_count);
        $this->assertEquals(300.00, $lead->total_value);
        $this->assertEquals(230.00, $lead->total_buy_price);
        $this->assertEquals(8.5, $lead->total_dwt);
    }
}
