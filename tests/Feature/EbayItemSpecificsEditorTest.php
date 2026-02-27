<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\Category;
use App\Models\CategoryPlatformMapping;
use App\Models\EbayItemSpecific;
use App\Models\EbayItemSpecificValue;
use App\Models\PlatformListing;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductTemplate;
use App\Models\ProductTemplateField;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\AI\AIManager;
use App\Services\AI\Contracts\AIResponse;
use App\Services\Platforms\ListingBuilderService;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class EbayItemSpecificsEditorTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected StoreMarketplace $marketplace;

    protected Product $product;

    protected Category $category;

    protected CategoryPlatformMapping $mapping;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Test Store',
            'step' => 2,
        ]);

        $this->user->update(['current_store_id' => $this->store->id]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        $this->marketplace = StoreMarketplace::factory()->ebay()->create([
            'store_id' => $this->store->id,
        ]);

        $this->category = Category::factory()->create(['store_id' => $this->store->id]);

        $this->product = Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $this->category->id,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $this->product->id,
            'price' => 99.99,
        ]);

        $this->mapping = CategoryPlatformMapping::create([
            'store_id' => $this->store->id,
            'category_id' => $this->category->id,
            'store_marketplace_id' => $this->marketplace->id,
            'platform' => Platform::Ebay,
            'primary_category_id' => '67890',
            'primary_category_name' => 'Rings',
            'item_specifics_synced_at' => now(),
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
        $this->actingAs($this->user);
    }

    public function test_show_page_includes_ebay_item_specifics_prop(): void
    {
        EbayItemSpecific::create([
            'ebay_category_id' => 67890,
            'name' => 'Ring Size',
            'type' => 'string',
            'is_required' => true,
            'is_recommended' => false,
            'aspect_mode' => 'FREE_TEXT',
        ]);

        EbayItemSpecific::create([
            'ebay_category_id' => 67890,
            'name' => 'Brand',
            'type' => 'string',
            'is_required' => false,
            'is_recommended' => true,
            'aspect_mode' => 'FREE_TEXT',
        ]);

        $response = $this->get("/products/{$this->product->id}/platforms/{$this->marketplace->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('products/platforms/Show')
            ->has('ebayItemSpecifics')
            ->has('ebayItemSpecifics.specifics', 2)
            ->where('ebayItemSpecifics.category_mapping_id', $this->mapping->id)
            ->where('ebayItemSpecifics.category_id', $this->category->id)
        );
    }

    public function test_show_page_excludes_ebay_item_specifics_for_non_ebay(): void
    {
        $shopifyMarketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $this->store->id,
        ]);

        $response = $this->get("/products/{$this->product->id}/platforms/{$shopifyMarketplace->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('products/platforms/Show')
            ->where('ebayItemSpecifics', null)
        );
    }

    public function test_resolved_value_comes_from_mapped_template_field(): void
    {
        $template = ProductTemplate::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $field = ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'name' => 'ring_size',
            'label' => 'Ring Size',
            'type' => 'text',
        ]);

        $this->product->update(['template_id' => $template->id]);

        ProductAttributeValue::create([
            'product_id' => $this->product->id,
            'product_template_field_id' => $field->id,
            'value' => '7.5',
        ]);

        // Map eBay's "Ring Size" to template's "ring_size"
        $this->mapping->update([
            'field_mappings' => ['Ring Size' => 'ring_size'],
        ]);

        EbayItemSpecific::create([
            'ebay_category_id' => 67890,
            'name' => 'Ring Size',
            'type' => 'string',
            'is_required' => true,
            'is_recommended' => false,
            'aspect_mode' => 'FREE_TEXT',
        ]);

        $response = $this->get("/products/{$this->product->id}/platforms/{$this->marketplace->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('products/platforms/Show')
            ->where('ebayItemSpecifics.specifics.0.name', 'Ring Size')
            ->where('ebayItemSpecifics.specifics.0.mapped_template_field', 'ring_size')
            ->where('ebayItemSpecifics.specifics.0.resolved_value', '7.5')
        );
    }

    public function test_listing_override_takes_precedence_over_mapped_value(): void
    {
        $template = ProductTemplate::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $field = ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'name' => 'ring_size',
            'label' => 'Ring Size',
            'type' => 'text',
        ]);

        $this->product->update(['template_id' => $template->id]);

        ProductAttributeValue::create([
            'product_id' => $this->product->id,
            'product_template_field_id' => $field->id,
            'value' => '7.5',
        ]);

        $this->mapping->update([
            'field_mappings' => ['Ring Size' => 'ring_size'],
        ]);

        // Find the auto-created listing (from SalesChannel created event) or create one
        $listing = PlatformListing::where('product_id', $this->product->id)
            ->where('store_marketplace_id', $this->marketplace->id)
            ->first();

        if ($listing) {
            $listing->update(['attributes' => ['Ring Size' => '8']]);
        } else {
            PlatformListing::create([
                'product_id' => $this->product->id,
                'store_marketplace_id' => $this->marketplace->id,
                'attributes' => ['Ring Size' => '8'],
                'status' => PlatformListing::STATUS_NOT_LISTED,
            ]);
        }

        EbayItemSpecific::create([
            'ebay_category_id' => 67890,
            'name' => 'Ring Size',
            'type' => 'string',
            'is_required' => true,
            'is_recommended' => false,
            'aspect_mode' => 'FREE_TEXT',
        ]);

        $response = $this->get("/products/{$this->product->id}/platforms/{$this->marketplace->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('products/platforms/Show')
            ->where('ebayItemSpecifics.specifics.0.resolved_value', '8')
            ->where('ebayItemSpecifics.specifics.0.is_listing_override', true)
        );
    }

    public function test_listing_attribute_overrides_win_in_build_listing(): void
    {
        $template = ProductTemplate::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $field = ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'name' => 'ring_size',
            'label' => 'Ring Size',
            'type' => 'text',
        ]);

        $this->product->update(['template_id' => $template->id]);

        ProductAttributeValue::create([
            'product_id' => $this->product->id,
            'product_template_field_id' => $field->id,
            'value' => '7.5',
        ]);

        $this->mapping->update([
            'field_mappings' => ['Ring Size' => 'ring_size'],
        ]);

        // Find the auto-created listing or create one with override attributes
        $listing = PlatformListing::where('product_id', $this->product->id)
            ->where('store_marketplace_id', $this->marketplace->id)
            ->first();

        if ($listing) {
            $listing->update(['attributes' => ['Ring Size' => '8']]);
        } else {
            PlatformListing::create([
                'product_id' => $this->product->id,
                'store_marketplace_id' => $this->marketplace->id,
                'attributes' => ['Ring Size' => '8'],
                'status' => PlatformListing::STATUS_NOT_LISTED,
            ]);
        }

        $service = app(ListingBuilderService::class);
        $listing = $service->buildListing($this->product, $this->marketplace);

        // The listing-level override ("8") should win over the mapped template value ("7.5")
        $this->assertEquals('8', $listing['attributes']['Ring Size']);
    }

    public function test_needs_sync_true_when_not_synced(): void
    {
        $this->mapping->update(['item_specifics_synced_at' => null]);

        $response = $this->get("/products/{$this->product->id}/platforms/{$this->marketplace->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('products/platforms/Show')
            ->where('ebayItemSpecifics.needs_sync', true)
        );
    }

    public function test_allowed_values_are_included_for_specifics(): void
    {
        $specific = EbayItemSpecific::create([
            'ebay_category_id' => 67890,
            'name' => 'Metal Purity',
            'type' => 'string',
            'is_required' => true,
            'is_recommended' => false,
            'aspect_mode' => 'SELECTION_ONLY',
        ]);

        EbayItemSpecificValue::create([
            'ebay_category_id' => '67890',
            'ebay_item_specific_id' => $specific->id,
            'value' => '10k',
        ]);

        EbayItemSpecificValue::create([
            'ebay_category_id' => '67890',
            'ebay_item_specific_id' => $specific->id,
            'value' => '14k',
        ]);

        $response = $this->get("/products/{$this->product->id}/platforms/{$this->marketplace->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('products/platforms/Show')
            ->has('ebayItemSpecifics.specifics.0.allowed_values', 2)
        );
    }

    public function test_ai_suggest_ebay_listing_returns_item_specifics(): void
    {
        EbayItemSpecific::create([
            'ebay_category_id' => 67890,
            'name' => 'Ring Size',
            'type' => 'string',
            'is_required' => true,
            'is_recommended' => false,
            'aspect_mode' => 'FREE_TEXT',
        ]);

        EbayItemSpecific::create([
            'ebay_category_id' => 67890,
            'name' => 'Brand',
            'type' => 'string',
            'is_required' => false,
            'is_recommended' => true,
            'aspect_mode' => 'FREE_TEXT',
        ]);

        $aiResponse = new AIResponse(
            content: json_encode([
                'title' => 'EFFY 14K White Gold Diamond Ring Size 7.5',
                'description' => '<p>Beautiful EFFY ring</p>',
                'item_specifics' => [
                    'Ring Size' => '7.5',
                    'Brand' => 'EFFY',
                ],
            ]),
            provider: 'openai',
            model: 'gpt-4',
            inputTokens: 100,
            outputTokens: 50,
        );

        $mockManager = Mockery::mock(AIManager::class);
        $mockManager->shouldReceive('generateJson')
            ->once()
            ->andReturn($aiResponse);
        $this->app->instance(AIManager::class, $mockManager);

        $response = $this->postJson(
            "/products/{$this->product->id}/platforms/{$this->marketplace->id}/ai-suggest",
            ['type' => 'ebay_listing', 'include_title' => true, 'include_description' => true],
        );

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('suggestions.item_specifics.Ring Size', '7.5');
        $response->assertJsonPath('suggestions.item_specifics.Brand', 'EFFY');
        $response->assertJsonPath('suggestions.title', 'EFFY 14K White Gold Diamond Ring Size 7.5');
        $response->assertJsonPath('suggestions.description', '<p>Beautiful EFFY ring</p>');
    }

    public function test_ai_suggest_ebay_listing_includes_title_when_requested(): void
    {
        EbayItemSpecific::create([
            'ebay_category_id' => 67890,
            'name' => 'Brand',
            'type' => 'string',
            'is_required' => false,
            'is_recommended' => true,
            'aspect_mode' => 'FREE_TEXT',
        ]);

        $aiResponse = new AIResponse(
            content: json_encode([
                'title' => 'AI Generated Title',
                'item_specifics' => ['Brand' => 'EFFY'],
            ]),
            provider: 'openai',
            model: 'gpt-4',
            inputTokens: 100,
            outputTokens: 50,
        );

        $mockManager = Mockery::mock(AIManager::class);
        $mockManager->shouldReceive('generateJson')
            ->once()
            ->andReturn($aiResponse);
        $this->app->instance(AIManager::class, $mockManager);

        $response = $this->postJson(
            "/products/{$this->product->id}/platforms/{$this->marketplace->id}/ai-suggest",
            ['type' => 'ebay_listing', 'include_title' => true, 'include_description' => false],
        );

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('suggestions.title', 'AI Generated Title');
    }

    public function test_ai_suggest_ebay_listing_excludes_title_when_not_requested(): void
    {
        EbayItemSpecific::create([
            'ebay_category_id' => 67890,
            'name' => 'Brand',
            'type' => 'string',
            'is_required' => false,
            'is_recommended' => true,
            'aspect_mode' => 'FREE_TEXT',
        ]);

        $aiResponse = new AIResponse(
            content: json_encode([
                'item_specifics' => ['Brand' => 'EFFY'],
            ]),
            provider: 'openai',
            model: 'gpt-4',
            inputTokens: 100,
            outputTokens: 50,
        );

        $mockManager = Mockery::mock(AIManager::class);
        $mockManager->shouldReceive('generateJson')
            ->once()
            ->withArgs(function (string $prompt, array $schema) {
                // Verify schema does NOT include title property
                return ! isset($schema['properties']['title']);
            })
            ->andReturn($aiResponse);
        $this->app->instance(AIManager::class, $mockManager);

        $response = $this->postJson(
            "/products/{$this->product->id}/platforms/{$this->marketplace->id}/ai-suggest",
            ['type' => 'ebay_listing', 'include_title' => false, 'include_description' => false],
        );

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('suggestions.item_specifics.Brand', 'EFFY');
        $this->assertArrayNotHasKey('title', $response->json('suggestions'));
    }

    public function test_private_fields_excluded_from_shopify_metafields(): void
    {
        $shopifyMarketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $this->store->id,
        ]);

        $template = ProductTemplate::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $publicField = ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'name' => 'brand',
            'label' => 'Brand',
            'type' => 'text',
            'is_private' => false,
        ]);

        $privateField = ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'name' => 'gender',
            'label' => 'Gender',
            'type' => 'text',
            'is_private' => true,
        ]);

        $this->product->update(['template_id' => $template->id]);

        ProductAttributeValue::create([
            'product_id' => $this->product->id,
            'product_template_field_id' => $publicField->id,
            'value' => 'EFFY',
        ]);

        ProductAttributeValue::create([
            'product_id' => $this->product->id,
            'product_template_field_id' => $privateField->id,
            'value' => 'Ladies',
        ]);

        $service = app(ListingBuilderService::class);
        $listing = $service->buildListing($this->product, $shopifyMarketplace);

        $metafieldKeys = array_column($listing['metafields'] ?? [], 'key');

        $this->assertContains('brand', $metafieldKeys);
        $this->assertNotContains('gender', $metafieldKeys);
    }
}
