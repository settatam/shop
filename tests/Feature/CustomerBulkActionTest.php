<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerBulkActionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected StoreUser $storeUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create([
            'user_id' => $this->user->id,
            'step' => 2,
        ]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        $this->storeUser = StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        $this->user->update(['current_store_id' => $this->store->id]);
        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_can_bulk_delete_customers(): void
    {
        $customers = Customer::factory()->count(3)->create([
            'store_id' => $this->store->id,
        ]);

        $ids = $customers->pluck('id')->toArray();

        $response = $this->actingAs($this->user)
            ->post('/customers/bulk-action', [
                'action' => 'delete',
                'ids' => $ids,
            ]);

        $response->assertRedirect(route('web.customers.index'));

        foreach ($ids as $id) {
            $this->assertSoftDeleted('customers', ['id' => $id]);
        }
    }

    public function test_bulk_delete_requires_authentication(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $response = $this->post('/customers/bulk-action', [
            'action' => 'delete',
            'ids' => [$customer->id],
        ]);

        $response->assertRedirect('/login');
    }

    public function test_bulk_delete_validates_ids(): void
    {
        $response = $this->actingAs($this->user)
            ->post('/customers/bulk-action', [
                'action' => 'delete',
                'ids' => [],
            ]);

        $response->assertSessionHasErrors('ids');
    }

    public function test_bulk_delete_validates_action(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $response = $this->actingAs($this->user)
            ->post('/customers/bulk-action', [
                'action' => 'invalid',
                'ids' => [$customer->id],
            ]);

        $response->assertSessionHasErrors('action');
    }

    public function test_can_export_customers_csv(): void
    {
        $customers = Customer::factory()->count(2)->create([
            'store_id' => $this->store->id,
        ]);

        $ids = $customers->pluck('id')->toArray();

        $response = $this->actingAs($this->user)
            ->post('/customers/export/csv', [
                'ids' => $ids,
            ]);

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=utf-8');
    }

    public function test_can_export_customers_csv_without_ids(): void
    {
        Customer::factory()->count(2)->create([
            'store_id' => $this->store->id,
        ]);

        $response = $this->actingAs($this->user)
            ->post('/customers/export/csv');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=utf-8');
    }

    public function test_can_export_customers_quickbooks_selected(): void
    {
        $customers = Customer::factory()->count(2)->create([
            'store_id' => $this->store->id,
        ]);

        $ids = $customers->pluck('id')->toArray();

        $response = $this->actingAs($this->user)
            ->post('/customers/export/quickbooks-selected', [
                'ids' => $ids,
            ]);

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=utf-8');
    }

    public function test_bulk_delete_only_deletes_own_store_customers(): void
    {
        $otherStore = Store::factory()->create();
        $otherCustomer = Customer::factory()->create([
            'store_id' => $otherStore->id,
        ]);

        $ownCustomer = Customer::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $response = $this->actingAs($this->user)
            ->post('/customers/bulk-action', [
                'action' => 'delete',
                'ids' => [$otherCustomer->id, $ownCustomer->id],
            ]);

        $response->assertRedirect(route('web.customers.index'));

        // Own customer should be soft-deleted
        $this->assertSoftDeleted('customers', ['id' => $ownCustomer->id]);

        // Other store's customer should NOT be deleted
        $this->assertDatabaseHas('customers', [
            'id' => $otherCustomer->id,
            'deleted_at' => null,
        ]);
    }
}
