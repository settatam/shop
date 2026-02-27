<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\Category;
use App\Models\CategoryPlatformMapping;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductTemplate;
use App\Models\ProductTemplateField;
use App\Models\ProductTemplateFieldOption;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Models\StoreUser;
use App\Models\TemplatePlatformMapping;
use App\Models\User;
use App\Services\Platforms\ListingBuilderService;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListingBuilderServiceTest extends TestCase
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
            'name' => 'Test Store',
            'step' => 2,
        ]);

        $this->user->update(['current_store_id' => $this->store->id]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        $this->storeUser = StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
        $this->actingAs($this->user);
    }

    public function test_build_listing_includes_base_product_data(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Test Product',
            'description' => 'Test Description',
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 99.99,
            'sku' => 'TEST-SKU',
        ]);

        $marketplace = StoreMarketplace::factory()->create([
            'store_id' => $this->store->id,
            'platform' => Platform::Shopify,
        ]);

        $service = app(ListingBuilderService::class);
        $listing = $service->buildListing($product, $marketplace);

        $this->assertEquals('Test Product', $listing['title']);
        $this->assertEquals('Test Description', $listing['description']);
        $this->assertEquals(99.99, $listing['price']);
        $this->assertEquals('TEST-SKU', $listing['sku']);
    }

    public function test_shopify_listing_builds_metafields_from_template_configuration(): void
    {
        $template = ProductTemplate::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Ring Template',
        ]);

        // Create template fields
        $materialField = ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'name' => 'material',
            'label' => 'Material',
            'type' => 'text',
        ]);

        $weightField = ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'name' => 'weight',
            'label' => 'Weight',
            'type' => 'number',
        ]);

        // Create platform mapping with metafield configuration
        TemplatePlatformMapping::factory()->create([
            'product_template_id' => $template->id,
            'platform' => Platform::Shopify,
            'field_mappings' => [
                'material' => 'material',
                'weight' => 'weight',
            ],
            'metafield_mappings' => [
                'material' => [
                    'namespace' => 'product_info',
                    'key' => 'metal_type',
                    'enabled' => true,
                ],
                'weight' => [
                    'namespace' => 'custom',
                    'key' => 'product_weight',
                    'enabled' => true,
                ],
            ],
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'template_id' => $template->id,
            'title' => 'Gold Ring',
        ]);

        // Create attribute values for the product
        $product->attributeValues()->createMany([
            [
                'product_template_field_id' => $materialField->id,
                'value' => 'Gold',
            ],
            [
                'product_template_field_id' => $weightField->id,
                'value' => '3.5',
            ],
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 299.99,
        ]);

        $marketplace = StoreMarketplace::factory()->create([
            'store_id' => $this->store->id,
            'platform' => Platform::Shopify,
        ]);

        $service = app(ListingBuilderService::class);
        $listing = $service->buildListing($product, $marketplace);

        $this->assertArrayHasKey('metafields', $listing);
        $this->assertCount(2, $listing['metafields']);

        // Check metafield for material uses custom namespace/key
        $materialMetafield = collect($listing['metafields'])->firstWhere('key', 'metal_type');
        $this->assertNotNull($materialMetafield);
        $this->assertEquals('product_info', $materialMetafield['namespace']);
        $this->assertEquals('Gold', $materialMetafield['value']);

        // Check metafield for weight uses custom key
        $weightMetafield = collect($listing['metafields'])->firstWhere('key', 'product_weight');
        $this->assertNotNull($weightMetafield);
        $this->assertEquals('custom', $weightMetafield['namespace']);
        $this->assertEquals('3.5', $weightMetafield['value']);
    }

    public function test_shopify_listing_only_includes_enabled_metafields(): void
    {
        $template = ProductTemplate::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $materialField = ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'name' => 'material',
        ]);

        $colorField = ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'name' => 'color',
        ]);

        // Only material is enabled as a metafield
        TemplatePlatformMapping::factory()->create([
            'product_template_id' => $template->id,
            'platform' => Platform::Shopify,
            'field_mappings' => [],
            'metafield_mappings' => [
                'material' => [
                    'namespace' => 'custom',
                    'key' => 'material',
                    'enabled' => true,
                ],
                'color' => [
                    'namespace' => 'custom',
                    'key' => 'color',
                    'enabled' => false, // Disabled
                ],
            ],
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'template_id' => $template->id,
        ]);

        $product->attributeValues()->createMany([
            ['product_template_field_id' => $materialField->id, 'value' => 'Silver'],
            ['product_template_field_id' => $colorField->id, 'value' => 'White'],
        ]);

        ProductVariant::factory()->create(['product_id' => $product->id, 'price' => 100]);

        $marketplace = StoreMarketplace::factory()->create([
            'store_id' => $this->store->id,
            'platform' => Platform::Shopify,
        ]);

        $service = app(ListingBuilderService::class);
        $listing = $service->buildListing($product, $marketplace);

        // Only material metafield should be included
        $this->assertCount(1, $listing['metafields']);
        $this->assertEquals('material', $listing['metafields'][0]['key']);
        $this->assertEquals('Silver', $listing['metafields'][0]['value']);
    }

    public function test_shopify_listing_without_template_has_empty_metafields(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'template_id' => null,
            'title' => 'Product Without Template',
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 50,
        ]);

        $marketplace = StoreMarketplace::factory()->create([
            'store_id' => $this->store->id,
            'platform' => Platform::Shopify,
        ]);

        $service = app(ListingBuilderService::class);
        $listing = $service->buildListing($product, $marketplace);

        $this->assertArrayHasKey('metafields', $listing);
        $this->assertEmpty($listing['metafields']);
    }

    public function test_ebay_listing_uses_item_specifics_not_metafields(): void
    {
        $template = ProductTemplate::factory()->create([
            'store_id' => $this->store->id,
        ]);

        TemplatePlatformMapping::factory()->create([
            'product_template_id' => $template->id,
            'platform' => Platform::Ebay,
            'field_mappings' => [
                'brand' => 'Brand',
            ],
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'template_id' => $template->id,
            'title' => 'eBay Product',
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 150,
        ]);

        $marketplace = StoreMarketplace::factory()->create([
            'store_id' => $this->store->id,
            'platform' => Platform::Ebay,
        ]);

        $service = app(ListingBuilderService::class);
        $listing = $service->buildListing($product, $marketplace);

        // eBay uses item_specifics instead of metafields
        $this->assertArrayHasKey('item_specifics', $listing);
        $this->assertArrayNotHasKey('metafields', $listing);
    }

    public function test_validate_listing_returns_errors_and_warnings(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => null, // Missing required title
        ]);

        $marketplace = StoreMarketplace::factory()->create([
            'store_id' => $this->store->id,
            'platform' => Platform::Shopify,
        ]);

        $service = app(ListingBuilderService::class);
        $validation = $service->validateListing($product, $marketplace);

        $this->assertFalse($validation['valid']);
        $this->assertContains('Product title is required', $validation['errors']);
    }

    public function test_preview_listing_returns_listing_and_validation(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Preview Product',
            'description' => 'Description',
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 75,
        ]);

        $marketplace = StoreMarketplace::factory()->create([
            'store_id' => $this->store->id,
            'platform' => Platform::Shopify,
        ]);

        $service = app(ListingBuilderService::class);
        $preview = $service->previewListing($product, $marketplace);

        $this->assertArrayHasKey('listing', $preview);
        $this->assertArrayHasKey('validation', $preview);
        $this->assertEquals('Preview Product', $preview['listing']['title']);
    }

    public function test_ebay_listing_includes_aspects_from_category_field_mappings(): void
    {
        $category = Category::factory()->create(['store_id' => $this->store->id]);

        $template = ProductTemplate::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $ringSizeField = ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'name' => 'ring_size',
            'label' => 'Ring Size',
            'type' => 'text',
        ]);

        // Material is a select field with options — should resolve to label
        $materialField = ProductTemplateField::factory()->select()->create([
            'product_template_id' => $template->id,
            'name' => 'material',
            'label' => 'Material',
        ]);

        ProductTemplateFieldOption::factory()->create([
            'product_template_field_id' => $materialField->id,
            'label' => 'White Gold',
            'value' => 'white-gold',
        ]);

        $marketplace = StoreMarketplace::factory()->create([
            'store_id' => $this->store->id,
            'platform' => Platform::Ebay,
        ]);

        // Category-level field_mappings: {platformFieldName: templateFieldName}
        CategoryPlatformMapping::create([
            'store_id' => $this->store->id,
            'category_id' => $category->id,
            'store_marketplace_id' => $marketplace->id,
            'platform' => 'ebay',
            'primary_category_id' => '261994',
            'primary_category_name' => 'Rings',
            'field_mappings' => [
                'Ring Size' => 'ring_size',
                'Material' => 'material',
            ],
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'template_id' => $template->id,
            'category_id' => $category->id,
            'title' => 'Gold Ring',
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 200,
        ]);

        ProductAttributeValue::create([
            'product_id' => $product->id,
            'product_template_field_id' => $ringSizeField->id,
            'value' => '75',
        ]);

        ProductAttributeValue::create([
            'product_id' => $product->id,
            'product_template_field_id' => $materialField->id,
            'value' => 'white-gold',
        ]);

        $service = app(ListingBuilderService::class);
        $listing = $service->buildListing($product, $marketplace);

        // Text field: raw value preserved
        $this->assertArrayHasKey('Ring Size', $listing['attributes']);
        $this->assertEquals('75', $listing['attributes']['Ring Size']);

        // Select field: resolved to display label
        $this->assertArrayHasKey('Material', $listing['attributes']);
        $this->assertEquals('White Gold', $listing['attributes']['Material']);

        // Aspects (eBay Inventory API format) should wrap values in arrays
        $this->assertArrayHasKey('aspects', $listing);
        $this->assertEquals(['75'], $listing['aspects']['Ring Size']);
        $this->assertEquals(['White Gold'], $listing['aspects']['Material']);
    }

    public function test_shopify_metafields_excludes_fields_without_values(): void
    {
        $template = ProductTemplate::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $materialField = ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'name' => 'material',
            'label' => 'Material',
            'type' => 'text',
        ]);

        // A field with no value set on the product
        ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'name' => 'color',
            'label' => 'Color',
            'type' => 'text',
        ]);

        // A field with an empty string value
        $emptyField = ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'name' => 'size',
            'label' => 'Size',
            'type' => 'text',
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'template_id' => $template->id,
            'title' => 'Test Product',
        ]);

        $product->attributeValues()->create([
            'product_template_field_id' => $materialField->id,
            'value' => 'Gold',
        ]);

        $product->attributeValues()->create([
            'product_template_field_id' => $emptyField->id,
            'value' => '',
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 100,
        ]);

        $marketplace = StoreMarketplace::factory()->create([
            'store_id' => $this->store->id,
            'platform' => Platform::Shopify,
        ]);

        $service = app(ListingBuilderService::class);
        $listing = $service->buildListing($product, $marketplace);

        $this->assertArrayHasKey('metafields', $listing);

        // Only the field with a value should be included
        $keys = collect($listing['metafields'])->pluck('key')->all();
        $this->assertContains('material', $keys);
        $this->assertNotContains('color', $keys);
        $this->assertNotContains('size', $keys);

        // Every metafield should have a non-empty value
        foreach ($listing['metafields'] as $mf) {
            $this->assertNotNull($mf['value']);
            $this->assertNotEquals('', $mf['value']);
        }
    }
}
