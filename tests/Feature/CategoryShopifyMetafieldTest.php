<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CategoryPlatformMapping;
use App\Models\ProductTemplate;
use App\Models\Role;
use App\Models\ShopifyMetafieldDefinition;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CategoryShopifyMetafieldTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected StoreMarketplace $marketplace;

    protected Category $category;

    protected CategoryPlatformMapping $mapping;

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

        $this->marketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $this->store->id,
            'is_app' => false,
            'connected_successfully' => true,
        ]);

        $this->category = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Watches',
        ]);

        $this->mapping = CategoryPlatformMapping::create([
            'store_id' => $this->store->id,
            'category_id' => $this->category->id,
            'store_marketplace_id' => $this->marketplace->id,
            'platform' => 'shopify',
            'primary_category_id' => 'watches',
            'primary_category_name' => 'Watches',
        ]);
    }

    protected function withStore()
    {
        return $this->withSession(['current_store_id' => $this->store->id]);
    }

    public function test_shopify_metafields_endpoint_returns_definitions(): void
    {
        ShopifyMetafieldDefinition::factory()->create([
            'store_marketplace_id' => $this->marketplace->id,
            'key' => 'brand',
            'namespace' => 'custom',
            'name' => 'Brand',
            'type' => 'single_line_text_field',
        ]);

        ShopifyMetafieldDefinition::factory()->create([
            'store_marketplace_id' => $this->marketplace->id,
            'key' => 'material',
            'namespace' => 'custom',
            'name' => 'Material',
            'type' => 'single_line_text_field',
        ]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->getJson("/categories/{$this->category->id}/platform-mappings/{$this->mapping->id}/shopify-metafields");

        $response->assertOk();
        $response->assertJsonStructure([
            'definitions' => [
                '*' => ['id', 'name', 'key', 'namespace', 'type', 'enabled', 'mapped_template_field'],
            ],
            'has_definitions',
        ]);
        $response->assertJsonCount(2, 'definitions');
        $response->assertJson(['has_definitions' => true]);
    }

    public function test_shopify_metafields_returns_existing_config(): void
    {
        ShopifyMetafieldDefinition::factory()->create([
            'store_marketplace_id' => $this->marketplace->id,
            'key' => 'brand',
            'namespace' => 'custom',
            'name' => 'Brand',
            'type' => 'single_line_text_field',
        ]);

        ShopifyMetafieldDefinition::factory()->create([
            'store_marketplace_id' => $this->marketplace->id,
            'key' => 'material',
            'namespace' => 'custom',
            'name' => 'Material',
            'type' => 'single_line_text_field',
        ]);

        $this->mapping->update([
            'metadata' => [
                'enabled_metafields' => ['custom.brand'],
                'metafield_mappings' => ['custom.brand' => 'brand_name'],
            ],
        ]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->getJson("/categories/{$this->category->id}/platform-mappings/{$this->mapping->id}/shopify-metafields");

        $response->assertOk();

        $definitions = $response->json('definitions');

        // Brand should be enabled with mapping
        $brand = collect($definitions)->firstWhere('key', 'brand');
        $this->assertTrue($brand['enabled']);
        $this->assertEquals('brand_name', $brand['mapped_template_field']);

        // Material should not be enabled
        $material = collect($definitions)->firstWhere('key', 'material');
        $this->assertFalse($material['enabled']);
        $this->assertNull($material['mapped_template_field']);
    }

    public function test_shopify_metafields_endpoint_rejects_non_shopify_mapping(): void
    {
        $ebayMarketplace = StoreMarketplace::factory()->ebay()->create([
            'store_id' => $this->store->id,
            'is_app' => false,
            'connected_successfully' => true,
        ]);

        $ebayMapping = CategoryPlatformMapping::create([
            'store_id' => $this->store->id,
            'category_id' => $this->category->id,
            'store_marketplace_id' => $ebayMarketplace->id,
            'platform' => 'ebay',
            'primary_category_id' => '31387',
            'primary_category_name' => 'Wristwatches',
        ]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->getJson("/categories/{$this->category->id}/platform-mappings/{$ebayMapping->id}/shopify-metafields");

        $response->assertNotFound();
    }

    public function test_can_save_metafield_config_via_update(): void
    {
        $this->actingAs($this->user);

        $response = $this->withStore()
            ->putJson("/categories/{$this->category->id}/platform-mappings/{$this->mapping->id}", [
                'metadata' => [
                    'enabled_metafields' => ['custom.brand', 'custom.material'],
                    'metafield_mappings' => ['custom.brand' => 'brand_name'],
                ],
            ]);

        $response->assertOk();
        $response->assertJson([
            'metadata' => [
                'enabled_metafields' => ['custom.brand', 'custom.material'],
                'metafield_mappings' => ['custom.brand' => 'brand_name'],
            ],
        ]);

        $this->mapping->refresh();
        $this->assertEquals(['custom.brand', 'custom.material'], $this->mapping->getEnabledMetafields());
        $this->assertEquals(['custom.brand' => 'brand_name'], $this->mapping->getMetafieldMappings());
    }

    public function test_category_platform_mapping_helper_methods(): void
    {
        $mapping = new CategoryPlatformMapping([
            'metadata' => [
                'enabled_metafields' => ['custom.brand', 'custom.color'],
                'metafield_mappings' => ['custom.brand' => 'brand_name'],
            ],
        ]);

        $this->assertTrue($mapping->hasMetafieldConfig());
        $this->assertEquals(['custom.brand', 'custom.color'], $mapping->getEnabledMetafields());
        $this->assertEquals(['custom.brand' => 'brand_name'], $mapping->getMetafieldMappings());
    }

    public function test_category_platform_mapping_helpers_with_no_config(): void
    {
        $mapping = new CategoryPlatformMapping(['metadata' => null]);

        $this->assertFalse($mapping->hasMetafieldConfig());
        $this->assertEquals([], $mapping->getEnabledMetafields());
        $this->assertEquals([], $mapping->getMetafieldMappings());
    }

    public function test_settings_page_includes_metadata_in_platform_mappings(): void
    {
        $template = ProductTemplate::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $this->category->update(['template_id' => $template->id]);

        $this->mapping->update([
            'metadata' => [
                'enabled_metafields' => ['custom.brand'],
                'metafield_mappings' => ['custom.brand' => 'brand_name'],
            ],
        ]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->get("/categories/{$this->category->id}/settings");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('categories/Settings')
            ->has('platformMappings')
            ->where('platformMappings.0.metadata.enabled_metafields', ['custom.brand'])
        );
    }
}
