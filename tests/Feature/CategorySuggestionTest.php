<?php

namespace Tests\Feature;

use App\Models\EbayCategory;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\AI\AIManager;
use App\Services\AI\CategorySuggestionService;
use App\Services\AI\Contracts\AIResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CategorySuggestionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id, 'step' => 2]);

        Role::createDefaultRoles($this->store->id);

        $ownerRole = Role::where('store_id', $this->store->id)
            ->where('slug', 'owner')
            ->first();

        StoreUser::create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $ownerRole->id,
            'is_owner' => true,
            'status' => 'active',
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $this->user->email,
        ]);

        $this->user->update(['current_store_id' => $this->store->id]);
    }

    protected function withStore()
    {
        return $this->withSession(['current_store_id' => $this->store->id]);
    }

    public function test_suggest_endpoint_returns_valid_json_structure(): void
    {
        // Create leaf eBay categories (no children)
        $parent = EbayCategory::create([
            'name' => 'Jewelry & Watches',
            'level' => 1,
            'ebay_category_id' => 281,
            'parent_id' => null,
            'ebay_parent_id' => null,
        ]);

        $leaf = EbayCategory::create([
            'name' => 'Wristwatches',
            'level' => 2,
            'ebay_category_id' => 31387,
            'parent_id' => $parent->id,
            'ebay_parent_id' => 281,
        ]);

        $aiResponse = new AIResponse(
            content: json_encode([
                'suggestions' => [
                    [
                        'ebay_category_id' => 31387,
                        'name' => 'Wristwatches',
                        'path' => 'Jewelry & Watches > Wristwatches',
                        'confidence' => 92,
                        'reasoning' => 'Direct category match for watches.',
                    ],
                ],
            ]),
            provider: 'openai',
            model: 'gpt-4o',
            inputTokens: 100,
            outputTokens: 50,
        );

        $mockManager = Mockery::mock(AIManager::class);
        $mockManager->shouldReceive('generateJson')
            ->once()
            ->andReturn($aiResponse);

        $this->app->instance(AIManager::class, $mockManager);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->postJson('/api/taxonomy/ebay/suggest', [
                'category_name' => 'Watches',
            ]);

        $response->assertOk();
        $response->assertJsonStructure([
            '*' => ['ebay_category_id', 'name', 'path', 'confidence', 'reasoning'],
        ]);
        $response->assertJsonFragment([
            'ebay_category_id' => 31387,
            'confidence' => 92,
        ]);
    }

    public function test_suggest_endpoint_validates_category_name_required(): void
    {
        $this->actingAs($this->user);

        $response = $this->withStore()
            ->postJson('/api/taxonomy/ebay/suggest', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('category_name');
    }

    public function test_suggest_endpoint_accepts_optional_params(): void
    {
        $parent = EbayCategory::create([
            'name' => 'Jewelry',
            'level' => 1,
            'ebay_category_id' => 281,
            'parent_id' => null,
            'ebay_parent_id' => null,
        ]);

        $leaf = EbayCategory::create([
            'name' => 'Rings',
            'level' => 2,
            'ebay_category_id' => 67726,
            'parent_id' => $parent->id,
            'ebay_parent_id' => 281,
        ]);

        $aiResponse = new AIResponse(
            content: json_encode([
                'suggestions' => [
                    [
                        'ebay_category_id' => 67726,
                        'name' => 'Rings',
                        'path' => 'Jewelry > Rings',
                        'confidence' => 85,
                        'reasoning' => 'Good match for rings category.',
                    ],
                ],
            ]),
            provider: 'openai',
            model: 'gpt-4o',
            inputTokens: 100,
            outputTokens: 50,
        );

        $mockManager = Mockery::mock(AIManager::class);
        $mockManager->shouldReceive('generateJson')
            ->once()
            ->andReturn($aiResponse);

        $this->app->instance(AIManager::class, $mockManager);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->postJson('/api/taxonomy/ebay/suggest', [
                'category_name' => 'Rings',
                'template_name' => 'Jewelry Template',
                'category_path' => 'Jewelry > Rings',
            ]);

        $response->assertOk();
        $response->assertJsonCount(1);
    }

    public function test_suggest_returns_empty_when_no_candidates_found(): void
    {
        // No eBay categories in the database
        $this->actingAs($this->user);

        $response = $this->withStore()
            ->postJson('/api/taxonomy/ebay/suggest', [
                'category_name' => 'Watches',
            ]);

        $response->assertOk();
        $response->assertJson([]);
    }

    public function test_suggest_filters_out_invalid_category_ids(): void
    {
        $parent = EbayCategory::create([
            'name' => 'Electronics',
            'level' => 1,
            'ebay_category_id' => 293,
            'parent_id' => null,
            'ebay_parent_id' => null,
        ]);

        EbayCategory::create([
            'name' => 'Laptops',
            'level' => 2,
            'ebay_category_id' => 175672,
            'parent_id' => $parent->id,
            'ebay_parent_id' => 293,
        ]);

        // AI returns a mix of valid and invalid IDs
        $aiResponse = new AIResponse(
            content: json_encode([
                'suggestions' => [
                    [
                        'ebay_category_id' => 175672,
                        'name' => 'Laptops',
                        'path' => 'Electronics > Laptops',
                        'confidence' => 88,
                        'reasoning' => 'Match.',
                    ],
                    [
                        'ebay_category_id' => 999999,
                        'name' => 'Fake Category',
                        'path' => 'Fake > Path',
                        'confidence' => 50,
                        'reasoning' => 'Hallucinated.',
                    ],
                ],
            ]),
            provider: 'openai',
            model: 'gpt-4o',
            inputTokens: 100,
            outputTokens: 50,
        );

        $mockManager = Mockery::mock(AIManager::class);
        $mockManager->shouldReceive('generateJson')
            ->once()
            ->andReturn($aiResponse);

        $this->app->instance(AIManager::class, $mockManager);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->postJson('/api/taxonomy/ebay/suggest', [
                'category_name' => 'Laptops',
            ]);

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['ebay_category_id' => 175672]);
        $response->assertJsonMissing(['ebay_category_id' => 999999]);
    }

    public function test_service_extracts_keywords_and_queries_leaf_categories(): void
    {
        $parent = EbayCategory::create([
            'name' => 'Clothing',
            'level' => 1,
            'ebay_category_id' => 11450,
            'parent_id' => null,
            'ebay_parent_id' => null,
        ]);

        // This is a parent, should not be returned as candidate
        $parentWithChildren = EbayCategory::create([
            'name' => 'Men Shoes',
            'level' => 2,
            'ebay_category_id' => 93427,
            'parent_id' => $parent->id,
            'ebay_parent_id' => 11450,
        ]);

        // This is a leaf, should be returned
        $leaf = EbayCategory::create([
            'name' => 'Athletic Shoes',
            'level' => 3,
            'ebay_category_id' => 15709,
            'parent_id' => $parentWithChildren->id,
            'ebay_parent_id' => 93427,
        ]);

        $aiResponse = new AIResponse(
            content: json_encode([
                'suggestions' => [
                    [
                        'ebay_category_id' => 15709,
                        'name' => 'Athletic Shoes',
                        'path' => 'Clothing > Men Shoes > Athletic Shoes',
                        'confidence' => 90,
                        'reasoning' => 'Great match for athletic shoes.',
                    ],
                ],
            ]),
            provider: 'openai',
            model: 'gpt-4o',
            inputTokens: 100,
            outputTokens: 50,
        );

        $mockManager = Mockery::mock(AIManager::class);
        $mockManager->shouldReceive('generateJson')
            ->once()
            ->andReturn($aiResponse);

        $this->app->instance(AIManager::class, $mockManager);

        $service = app(CategorySuggestionService::class);
        $result = $service->suggestEbayCategories('Athletic Shoes');

        $this->assertCount(1, $result);
        $this->assertEquals(15709, $result[0]['ebay_category_id']);
    }
}
