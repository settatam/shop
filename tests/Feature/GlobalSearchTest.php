<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\Search\GlobalSearchService;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        // Use collection driver so Scout searches work without MeiliSearch
        config(['scout.driver' => 'collection']);

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_search_returns_correct_structure_with_has_more(): void
    {
        Passport::actingAs($this->user);

        // Create 7 products with "rolex" in the title
        for ($i = 1; $i <= 7; $i++) {
            Product::factory()->create([
                'store_id' => $this->store->id,
                'title' => "Rolex Watch Model $i",
            ]);
        }

        $response = $this->getJson('/api/v1/search?q=rolex&limit=5');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'results' => [
                'products' => [
                    'items',
                    'total',
                    'hasMore',
                    'view_all_url',
                ],
            ],
            'total',
        ]);

        // Check that we get 5 items and hasMore is true
        $data = $response->json();
        $this->assertCount(5, $data['results']['products']['items']);
        $this->assertTrue($data['results']['products']['hasMore']);
        $this->assertNotNull($data['results']['products']['view_all_url']);
        $this->assertStringContainsString('search=rolex', $data['results']['products']['view_all_url']);
    }

    public function test_search_returns_null_view_all_url_when_all_results_shown(): void
    {
        Passport::actingAs($this->user);

        // Create 3 products with "omega" in the title
        for ($i = 1; $i <= 3; $i++) {
            Product::factory()->create([
                'store_id' => $this->store->id,
                'title' => "Omega Watch Model $i",
            ]);
        }

        $response = $this->getJson('/api/v1/search?q=omega&limit=5');

        $response->assertStatus(200);

        $data = $response->json();
        $this->assertCount(3, $data['results']['products']['items']);
        $this->assertFalse($data['results']['products']['hasMore']);
        $this->assertNull($data['results']['products']['view_all_url']);
    }

    public function test_search_service_returns_view_all_url_for_each_type(): void
    {
        $service = new GlobalSearchService;

        $this->assertEquals(
            route('products.index', ['search' => 'test']),
            $service->getViewAllUrl('products', 'test')
        );

        $this->assertEquals(
            route('web.orders.index', ['search' => 'test']),
            $service->getViewAllUrl('orders', 'test')
        );

        $this->assertEquals(
            route('web.customers.index', ['search' => 'test']),
            $service->getViewAllUrl('customers', 'test')
        );

        $this->assertEquals(
            route('web.transactions.index', ['search' => 'test']),
            $service->getViewAllUrl('transactions', 'test')
        );

        $this->assertNull($service->getViewAllUrl('unknown_type', 'test'));
    }
}
