<?php

namespace Tests\Feature\Api\V1;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\CollectionEngine;
use Tests\TestCase;

class SearchControllerTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Use collection engine for testing to avoid Meilisearch dependency
        $this->app->make(EngineManager::class)->extend('collection', function () {
            return new CollectionEngine;
        });
        config(['scout.driver' => 'collection']);

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id]);
        app(StoreContext::class)->setCurrentStore($this->store);
        Passport::actingAs($this->user);
    }

    public function test_search_requires_authentication(): void
    {
        // Create a fresh request without acting as a user
        $this->app->make('auth')->forgetGuards();

        $response = $this->getJson('/api/v1/search?q=test');

        $response->assertUnauthorized();
    }

    public function test_search_requires_query_parameter(): void
    {
        $response = $this->getJson('/api/v1/search');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['q']);
    }

    public function test_search_query_must_not_be_empty(): void
    {
        $response = $this->getJson('/api/v1/search?q=');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['q']);
    }

    public function test_search_query_has_max_length(): void
    {
        $response = $this->getJson('/api/v1/search?q='.str_repeat('a', 101));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['q']);
    }

    public function test_search_returns_empty_results_structure(): void
    {
        $response = $this->getJson('/api/v1/search?q=nonexistent');

        $response->assertOk();
        $response->assertJsonStructure([
            'results' => [
                'products',
                'orders',
                'customers',
                'repairs',
                'memos',
                'transactions',
            ],
            'total',
        ]);
    }

    public function test_search_finds_products(): void
    {
        Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Diamond Ring',
        ]);

        $response = $this->getJson('/api/v1/search?q=Diamond');

        $response->assertOk();
        $response->assertJsonPath('results.products.0.title', 'Diamond Ring');
    }

    public function test_search_finds_customers(): void
    {
        Customer::factory()->create([
            'store_id' => $this->store->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $response = $this->getJson('/api/v1/search?q=John');

        $response->assertOk();
        $response->assertJsonPath('results.customers.0.title', 'John Doe');
    }

    public function test_search_finds_orders(): void
    {
        Order::factory()->create([
            'store_id' => $this->store->id,
            'invoice_number' => 'INV-12345',
        ]);

        $response = $this->getJson('/api/v1/search?q=INV-12345');

        $response->assertOk();
        $response->assertJsonPath('results.orders.0.title', 'INV-12345');
    }

    public function test_search_results_are_scoped_to_current_store(): void
    {
        // Product in current store
        Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Store Product',
        ]);

        // Product in different store
        $otherStore = Store::factory()->create();
        Product::factory()->create([
            'store_id' => $otherStore->id,
            'title' => 'Other Store Product',
        ]);

        $response = $this->getJson('/api/v1/search?q=Product');

        $response->assertOk();
        $this->assertCount(1, $response->json('results.products'));
        $response->assertJsonPath('results.products.0.title', 'Store Product');
    }

    public function test_search_respects_limit_parameter(): void
    {
        Product::factory()
            ->count(10)
            ->create([
                'store_id' => $this->store->id,
                'title' => 'Test Product',
            ]);

        $response = $this->getJson('/api/v1/search?q=Test&limit=3');

        $response->assertOk();
        $this->assertCount(3, $response->json('results.products'));
    }

    public function test_search_limit_has_maximum(): void
    {
        $response = $this->getJson('/api/v1/search?q=test&limit=100');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['limit']);
    }

    public function test_search_returns_correct_result_format(): void
    {
        Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Gold Necklace',
        ]);

        $response = $this->getJson('/api/v1/search?q=Gold');

        $response->assertOk();
        $response->assertJsonStructure([
            'results' => [
                'products' => [
                    '*' => ['id', 'title', 'subtitle', 'url'],
                ],
            ],
            'total',
        ]);
    }
}
