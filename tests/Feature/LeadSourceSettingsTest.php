<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\LeadSource;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadSourceSettingsTest extends TestCase
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

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        $this->user->update(['current_store_id' => $this->store->id]);
        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_can_view_lead_sources_settings_page(): void
    {
        LeadSource::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Walk-in',
        ]);

        $response = $this->actingAs($this->user)->get('/settings/lead-sources');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('settings/LeadSources')
            ->has('leadSources', 1)
            ->where('leadSources.0.name', 'Walk-in')
        );
    }

    public function test_can_create_lead_source(): void
    {
        $response = $this->actingAs($this->user)->post('/settings/lead-sources', [
            'name' => 'Social Media',
            'description' => 'Customers from social media platforms',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('lead_sources', [
            'store_id' => $this->store->id,
            'name' => 'Social Media',
            'description' => 'Customers from social media platforms',
        ]);
    }

    public function test_can_update_lead_source(): void
    {
        $leadSource = LeadSource::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Walk-in',
        ]);

        $response = $this->actingAs($this->user)->put("/settings/lead-sources/{$leadSource->id}", [
            'name' => 'Walk-in Customer',
            'description' => 'Updated description',
            'is_active' => true,
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('lead_sources', [
            'id' => $leadSource->id,
            'name' => 'Walk-in Customer',
            'description' => 'Updated description',
        ]);
    }

    public function test_can_delete_lead_source_without_customers(): void
    {
        $leadSource = LeadSource::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Walk-in',
        ]);

        $response = $this->actingAs($this->user)->delete("/settings/lead-sources/{$leadSource->id}");

        $response->assertRedirect();

        $this->assertDatabaseMissing('lead_sources', [
            'id' => $leadSource->id,
        ]);
    }

    public function test_cannot_delete_lead_source_with_customers(): void
    {
        $leadSource = LeadSource::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Walk-in',
        ]);

        Customer::factory()->create([
            'store_id' => $this->store->id,
            'lead_source_id' => $leadSource->id,
        ]);

        $response = $this->actingAs($this->user)->delete("/settings/lead-sources/{$leadSource->id}");

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->assertDatabaseHas('lead_sources', [
            'id' => $leadSource->id,
        ]);
    }

    public function test_cannot_update_other_store_lead_source(): void
    {
        $otherStore = Store::factory()->create(['user_id' => $this->user->id]);
        $leadSource = LeadSource::factory()->create([
            'store_id' => $otherStore->id,
            'name' => 'Other Store Source',
        ]);

        $response = $this->actingAs($this->user)->put("/settings/lead-sources/{$leadSource->id}", [
            'name' => 'Hacked',
        ]);

        $response->assertStatus(404);
    }

    public function test_lead_sources_include_customer_count(): void
    {
        $leadSource = LeadSource::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Walk-in',
        ]);

        Customer::factory()->count(3)->create([
            'store_id' => $this->store->id,
            'lead_source_id' => $leadSource->id,
        ]);

        $response = $this->actingAs($this->user)->get('/settings/lead-sources');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('settings/LeadSources')
            ->where('leadSources.0.customers_count', 3)
        );
    }

    public function test_can_reorder_lead_sources(): void
    {
        $source1 = LeadSource::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'First',
            'sort_order' => 0,
        ]);

        $source2 = LeadSource::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Second',
            'sort_order' => 1,
        ]);

        $source3 = LeadSource::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Third',
            'sort_order' => 2,
        ]);

        $response = $this->actingAs($this->user)->post('/settings/lead-sources/reorder', [
            'order' => [$source3->id, $source1->id, $source2->id],
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('lead_sources', ['id' => $source3->id, 'sort_order' => 0]);
        $this->assertDatabaseHas('lead_sources', ['id' => $source1->id, 'sort_order' => 1]);
        $this->assertDatabaseHas('lead_sources', ['id' => $source2->id, 'sort_order' => 2]);
    }
}
