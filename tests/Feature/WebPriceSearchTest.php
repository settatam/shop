<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\StoreIntegration;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\Search\Providers\SerpApiProvider;
use App\Services\Search\WebPriceSearchService;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebPriceSearchTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected Transaction $transaction;

    protected TransactionItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id, 'step' => 2]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        $this->user->update(['current_store_id' => $this->store->id]);
        app(StoreContext::class)->setCurrentStore($this->store);

        $this->transaction = Transaction::factory()->create(['store_id' => $this->store->id]);
        $this->item = TransactionItem::factory()->create([
            'transaction_id' => $this->transaction->id,
            'title' => '14K Gold Ring Diamond',
            'precious_metal' => TransactionItem::METAL_GOLD_14K,
        ]);
    }

    public function test_web_price_search_returns_error_when_serpapi_not_configured(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/transactions/{$this->transaction->id}/items/{$this->item->id}/web-search");

        $response->assertStatus(200);
        $response->assertJson([
            'error' => 'SerpAPI integration not configured. Please configure it in Settings > Integrations.',
        ]);
    }

    public function test_web_price_search_saves_results_to_item(): void
    {
        // Create SerpAPI integration
        StoreIntegration::factory()->create([
            'store_id' => $this->store->id,
            'provider' => StoreIntegration::PROVIDER_SERPAPI,
            'status' => StoreIntegration::STATUS_ACTIVE,
            'credentials' => ['api_key' => 'test-api-key'],
        ]);

        // Mock the SerpApiProvider to avoid actual API calls
        $mockProvider = $this->mock(SerpApiProvider::class);
        $mockProvider->shouldReceive('setIntegration')->andReturnSelf();
        $mockProvider->shouldReceive('isConfigured')->andReturn(true);
        $mockProvider->shouldReceive('searchGoogleShopping')->andReturn([
            'shopping_results' => [
                [
                    'title' => '14K Gold Diamond Ring',
                    'price' => '$299.00',
                    'link' => 'https://example.com/ring',
                    'thumbnail' => 'https://example.com/image.jpg',
                    'source' => 'Amazon',
                ],
            ],
        ]);
        $mockProvider->shouldReceive('searchEbaySold')->andReturn([
            'organic_results' => [
                [
                    'title' => '14K Gold Ring',
                    'price' => ['raw' => '$250.00'],
                    'link' => 'https://ebay.com/item',
                    'thumbnail' => 'https://ebay.com/thumb.jpg',
                ],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/transactions/{$this->transaction->id}/items/{$this->item->id}/web-search");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'listings',
            'summary' => ['min', 'max', 'avg', 'median', 'count'],
            'searched_at',
            'query',
        ]);

        $this->item->refresh();
        $this->assertNotNull($this->item->web_search_results);
        $this->assertNotNull($this->item->web_search_generated_at);
    }

    public function test_web_price_search_service_builds_correct_query(): void
    {
        $service = app(WebPriceSearchService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('buildSearchQuery');
        $method->setAccessible(true);

        $query = $method->invoke($service, [
            'title' => '14K Gold Ring',
            'precious_metal' => 'gold_14k',
            'category' => 'Rings',
        ]);

        $this->assertStringContainsString('14K Gold Ring', $query);
        $this->assertStringContainsString('14K Gold', $query);
        $this->assertStringContainsString('Rings', $query);
    }

    public function test_web_price_search_service_normalizes_results(): void
    {
        $service = app(WebPriceSearchService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('normalizeResults');
        $method->setAccessible(true);

        $results = $method->invoke($service, [
            'google_shopping' => [
                'shopping_results' => [
                    ['title' => 'Ring 1', 'price' => '$100.00', 'link' => 'https://example.com/1'],
                    ['title' => 'Ring 2', 'price' => '$200.00', 'link' => 'https://example.com/2'],
                ],
            ],
            'ebay_sold' => [
                'organic_results' => [
                    ['title' => 'Ring 3', 'price' => ['raw' => '$150.00'], 'link' => 'https://ebay.com/1'],
                ],
            ],
            'searched_at' => now()->toIso8601String(),
            'query' => 'gold ring',
        ]);

        $this->assertCount(3, $results['listings']);
        $this->assertEquals(100, $results['summary']['min']);
        $this->assertEquals(200, $results['summary']['max']);
        $this->assertEquals(150, $results['summary']['avg']);
        $this->assertEquals(3, $results['summary']['count']);
    }

    public function test_cannot_access_web_search_for_different_store_item(): void
    {
        $otherStore = Store::factory()->create();
        $otherTransaction = Transaction::factory()->create(['store_id' => $otherStore->id]);
        $otherItem = TransactionItem::factory()->create(['transaction_id' => $otherTransaction->id]);

        $response = $this->actingAs($this->user)
            ->postJson("/transactions/{$otherTransaction->id}/items/{$otherItem->id}/web-search");

        $response->assertStatus(404);
    }

    public function test_quick_evaluation_web_search_returns_error_when_not_configured(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/transactions/quick-evaluation/web-search', [
                'title' => '14K Gold Ring',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'error' => 'SerpAPI integration not configured. Please configure it in Settings > Integrations.',
        ]);
    }

    public function test_quick_evaluation_web_search_requires_title(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/transactions/quick-evaluation/web-search', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('title');
    }
}
