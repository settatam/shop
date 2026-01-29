<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerAddressTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected Customer $customer;

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

        $this->customer = Customer::factory()->create([
            'store_id' => $this->store->id,
        ]);
    }

    public function test_can_view_customer_with_addresses(): void
    {
        // Create addresses for customer
        $this->customer->addresses()->create([
            'store_id' => $this->store->id,
            'address' => '123 Main St',
            'city' => 'Los Angeles',
            'zip' => '90001',
            'type' => Address::TYPE_HOME,
            'is_default' => true,
        ]);

        $response = $this->actingAs($this->user)->get("/customers/{$this->customer->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('customers/Show')
            ->has('customer.addresses', 1)
            ->has('addressTypes')
        );
    }

    public function test_can_add_address_to_customer(): void
    {
        $addressData = [
            'nickname' => 'Home Office',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'address' => '456 Oak Ave',
            'city' => 'San Francisco',
            'state' => 'CA',
            'zip' => '94102',
            'phone' => '555-1234',
            'type' => 'home',
            'is_default' => true,
        ];

        $response = $this->actingAs($this->user)
            ->post("/customers/{$this->customer->id}/addresses", $addressData);

        $response->assertRedirect();

        $this->assertDatabaseHas('addresses', [
            'addressable_id' => $this->customer->id,
            'addressable_type' => Customer::class,
            'address' => '456 Oak Ave',
            'city' => 'San Francisco',
            'zip' => '94102',
            'is_default' => true,
        ]);
    }

    public function test_can_update_customer_address(): void
    {
        $address = $this->customer->addresses()->create([
            'store_id' => $this->store->id,
            'address' => '123 Main St',
            'city' => 'Los Angeles',
            'zip' => '90001',
            'type' => Address::TYPE_HOME,
        ]);

        $response = $this->actingAs($this->user)
            ->put("/customers/{$this->customer->id}/addresses/{$address->id}", [
                'address' => '789 New St',
                'city' => 'San Diego',
                'zip' => '92101',
                'type' => 'work',
                'is_default' => false,
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('addresses', [
            'id' => $address->id,
            'address' => '789 New St',
            'city' => 'San Diego',
            'zip' => '92101',
            'type' => 'work',
        ]);
    }

    public function test_can_delete_customer_address(): void
    {
        $address = $this->customer->addresses()->create([
            'store_id' => $this->store->id,
            'address' => '123 Main St',
            'city' => 'Los Angeles',
            'zip' => '90001',
            'type' => Address::TYPE_HOME,
        ]);

        $response = $this->actingAs($this->user)
            ->delete("/customers/{$this->customer->id}/addresses/{$address->id}");

        $response->assertRedirect();

        $this->assertSoftDeleted('addresses', [
            'id' => $address->id,
        ]);
    }

    public function test_setting_default_address_unsets_previous_default(): void
    {
        // Create first default address
        $address1 = $this->customer->addresses()->create([
            'store_id' => $this->store->id,
            'address' => '123 First St',
            'city' => 'Los Angeles',
            'zip' => '90001',
            'type' => Address::TYPE_HOME,
            'is_default' => true,
        ]);

        // Create second address as default
        $response = $this->actingAs($this->user)
            ->post("/customers/{$this->customer->id}/addresses", [
                'address' => '456 Second St',
                'city' => 'San Francisco',
                'zip' => '94102',
                'type' => 'shipping',
                'is_default' => true,
            ]);

        $response->assertRedirect();

        // First address should no longer be default
        $this->assertDatabaseHas('addresses', [
            'id' => $address1->id,
            'is_default' => false,
        ]);

        // New address should be default
        $this->assertDatabaseHas('addresses', [
            'addressable_id' => $this->customer->id,
            'address' => '456 Second St',
            'is_default' => true,
        ]);
    }

    public function test_cannot_add_address_to_other_store_customer(): void
    {
        $otherStore = Store::factory()->create(['user_id' => $this->user->id]);
        $otherCustomer = Customer::factory()->create(['store_id' => $otherStore->id]);

        $response = $this->actingAs($this->user)
            ->post("/customers/{$otherCustomer->id}/addresses", [
                'address' => '123 Main St',
                'city' => 'Los Angeles',
                'zip' => '90001',
                'type' => 'home',
            ]);

        $response->assertStatus(404);
    }

    public function test_address_type_validation(): void
    {
        $response = $this->actingAs($this->user)
            ->post("/customers/{$this->customer->id}/addresses", [
                'address' => '123 Main St',
                'city' => 'Los Angeles',
                'zip' => '90001',
                'type' => 'invalid_type',
            ]);

        $response->assertSessionHasErrors('type');
    }

    public function test_required_fields_validation(): void
    {
        $response = $this->actingAs($this->user)
            ->post("/customers/{$this->customer->id}/addresses", [
                'nickname' => 'Test',
            ]);

        $response->assertSessionHasErrors(['address', 'city', 'zip', 'type']);
    }
}
