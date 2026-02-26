<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionCustomerAddressTest extends TestCase
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

        app(StoreContext::class)->setCurrentStore($this->store);
        $this->actingAs($this->user);
    }

    public function test_transaction_show_includes_primary_address_from_default_address(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $address = Address::factory()->default()->forCustomer($customer)->create([
            'address' => '123 Main St',
            'city' => 'Springfield',
            'zip' => '62704',
        ]);

        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->get("/transactions/{$transaction->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('transaction.customer.primary_address.address', '123 Main St')
            ->where('transaction.customer.primary_address.city', 'Springfield')
            ->where('transaction.customer.primary_address.zip', '62704')
            ->has('transaction.customer.addresses', 1)
        );
    }

    public function test_transaction_show_falls_back_to_first_address_when_no_default(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
        ]);

        Address::factory()->forCustomer($customer)->create([
            'is_default' => false,
            'address' => '456 Oak Ave',
            'city' => 'Portland',
            'zip' => '97201',
        ]);

        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->get("/transactions/{$transaction->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('transaction.customer.primary_address.address', '456 Oak Ave')
            ->where('transaction.customer.primary_address.city', 'Portland')
            ->where('transaction.customer.primary_address.zip', '97201')
        );
    }

    public function test_transaction_show_returns_null_primary_address_when_no_addresses(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->get("/transactions/{$transaction->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('transaction.customer.primary_address', null)
            ->has('transaction.customer.addresses', 0)
        );
    }

    public function test_transaction_show_prefers_default_address_over_first(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
        ]);

        Address::factory()->forCustomer($customer)->create([
            'is_default' => false,
            'address' => '111 First St',
        ]);

        Address::factory()->default()->forCustomer($customer)->create([
            'address' => '222 Default St',
        ]);

        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->get("/transactions/{$transaction->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('transaction.customer.primary_address.address', '222 Default St')
            ->has('transaction.customer.addresses', 2)
        );
    }

    public function test_transaction_show_includes_lead_source(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->get("/transactions/{$transaction->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('transaction.customer.lead_source_id')
            ->has('transaction.customer.company_name')
        );
    }
}
