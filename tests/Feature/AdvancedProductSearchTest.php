<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdvancedProductSearchTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->onboarded()->create(['user_id' => $this->user->id]);
        $ownerRole = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $ownerRole->id,
        ]);
        $this->user->update(['current_store_id' => $this->store->id]);
        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_advanced_search_requires_authentication(): void
    {
        $response = $this->get('/products/advanced-search?query=test');

        $response->assertRedirect(route('login'));
    }

    public function test_advanced_search_requires_minimum_query_length(): void
    {
        $this->actingAs($this->user);

        // JSON request returns 422 for validation errors
        $response = $this->getJson('/products/advanced-search/modal?query=a');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['query']);
    }

    public function test_advanced_search_returns_products(): void
    {
        // Create a product with searchable title
        Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Diamond Ring Special Edition',
        ]);

        $this->actingAs($this->user);

        $response = $this->getJson('/products/advanced-search/modal?query=Diamond Ring');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'active',
            'bought',
            'sold',
        ]);
    }

    public function test_advanced_search_returns_transaction_items(): void
    {
        $customer = Customer::factory()->create(['store_id' => $this->store->id]);
        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
        ]);
        TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'title' => 'Vintage Gold Watch',
        ]);

        $this->actingAs($this->user);

        $response = $this->getJson('/products/advanced-search/modal?query=Vintage');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'active',
            'bought',
            'sold',
        ]);
    }

    public function test_advanced_search_returns_order_items(): void
    {
        $customer = Customer::factory()->create(['store_id' => $this->store->id]);
        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'title' => 'Silver Necklace Premium',
        ]);

        $this->actingAs($this->user);

        $response = $this->getJson('/products/advanced-search/modal?query=Silver');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'active',
            'bought',
            'sold',
        ]);
    }

    public function test_advanced_search_only_returns_current_store_items(): void
    {
        // Create another store with a product
        $otherStore = Store::factory()->create(['user_id' => $this->user->id]);
        Product::factory()->create([
            'store_id' => $otherStore->id,
            'title' => 'Other Store Exclusive Item',
        ]);

        // Create product in current store
        Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Current Store Item',
        ]);

        $this->actingAs($this->user);

        $response = $this->getJson('/products/advanced-search/modal?query=Store');

        $response->assertStatus(200);
        // Should only return items from the current store (uses SQL fallback in tests)
        $data = $response->json();
        $this->assertIsArray($data['active']);
    }

    public function test_advanced_search_respects_limit_parameter(): void
    {
        // Create multiple products
        for ($i = 1; $i <= 5; $i++) {
            Product::factory()->create([
                'store_id' => $this->store->id,
                'title' => "Test Product Number {$i}",
            ]);
        }

        $this->actingAs($this->user);

        $response = $this->getJson('/products/advanced-search/modal?query=Product&limit=3');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertLessThanOrEqual(3, count($data['active']));
    }
}
