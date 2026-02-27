<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CategoryPlatformMapping;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductTemplate;
use App\Models\ProductTemplateField;
use App\Models\ProductTemplateFieldOption;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\ShopifyMetafieldDefinition;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Models\StoreUser;
use App\Models\TemplatePlatformMapping;
use App\Models\User;
use App\Services\Platforms\FieldMappingService;
use App\Services\Platforms\ListingBuilderService;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttributeValueResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

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

        app(StoreContext::class)->setCurrentStore($this->store);
        $this->actingAs($this->user);
    }

    public function test_resolve_display_value_for_select_field(): void
    {
        $template = ProductTemplate::factory()->create(['store_id' => $this->store->id]);

        $field = ProductTemplateField::factory()->select()->create([
            'product_template_id' => $template->id,
            'name' => 'diamond_cut',
            'label' => 'Diamond Cut',
        ]);

        ProductTemplateFieldOption::factory()->create([
            'product_template_field_id' => $field->id,
            'label' => 'Excellent Cut',
            'value' => 'excellent-cut',
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'template_id' => $template->id,
        ]);

        $attrValue = ProductAttributeValue::create([
            'product_id' => $product->id,
            'product_template_field_id' => $field->id,
            'value' => 'excellent-cut',
        ]);

        $attrValue->load('field.options');

        $this->assertEquals('Excellent Cut', $attrValue->resolveDisplayValue());
    }

    public function test_resolve_display_value_for_brand_field(): void
    {
        $template = ProductTemplate::factory()->create(['store_id' => $this->store->id]);

        $field = ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'name' => 'brand',
            'label' => 'Brand',
            'type' => ProductTemplateField::TYPE_BRAND,
        ]);

        $brand = Brand::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Tiffany & Co',
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'template_id' => $template->id,
        ]);

        $attrValue = ProductAttributeValue::create([
            'product_id' => $product->id,
            'product_template_field_id' => $field->id,
            'value' => (string) $brand->id,
        ]);

        $attrValue->load('field.options');

        $this->assertEquals('Tiffany & Co', $attrValue->resolveDisplayValue());
    }

    public function test_resolve_display_value_for_text_field(): void
    {
        $template = ProductTemplate::factory()->create(['store_id' => $this->store->id]);

        $field = ProductTemplateField::factory()->text()->create([
            'product_template_id' => $template->id,
            'name' => 'engraving',
            'label' => 'Engraving',
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'template_id' => $template->id,
        ]);

        $attrValue = ProductAttributeValue::create([
            'product_id' => $product->id,
            'product_template_field_id' => $field->id,
            'value' => 'Forever Yours',
        ]);

        $attrValue->load('field.options');

        $this->assertEquals('Forever Yours', $attrValue->resolveDisplayValue());
    }

    public function test_resolve_display_value_returns_raw_when_no_option_match(): void
    {
        $template = ProductTemplate::factory()->create(['store_id' => $this->store->id]);

        $field = ProductTemplateField::factory()->select()->create([
            'product_template_id' => $template->id,
            'name' => 'gemstone',
            'label' => 'Gemstone',
        ]);

        ProductTemplateFieldOption::factory()->create([
            'product_template_field_id' => $field->id,
            'label' => 'Diamond',
            'value' => 'diamond',
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'template_id' => $template->id,
        ]);

        $attrValue = ProductAttributeValue::create([
            'product_id' => $product->id,
            'product_template_field_id' => $field->id,
            'value' => 'unknown-gem',
        ]);

        $attrValue->load('field.options');

        // Falls back to raw value when no option matches
        $this->assertEquals('unknown-gem', $attrValue->resolveDisplayValue());
    }

    public function test_listing_builder_uses_display_values(): void
    {
        $category = Category::factory()->create(['store_id' => $this->store->id]);

        $template = ProductTemplate::factory()->create(['store_id' => $this->store->id]);

        $field = ProductTemplateField::factory()->select()->create([
            'product_template_id' => $template->id,
            'name' => 'material',
            'label' => 'Material',
        ]);

        ProductTemplateFieldOption::factory()->create([
            'product_template_field_id' => $field->id,
            'label' => 'White Gold',
            'value' => 'white-gold',
        ]);

        $marketplace = StoreMarketplace::factory()->ebay()->create([
            'store_id' => $this->store->id,
        ]);

        CategoryPlatformMapping::create([
            'store_id' => $this->store->id,
            'category_id' => $category->id,
            'store_marketplace_id' => $marketplace->id,
            'platform' => 'ebay',
            'primary_category_id' => '261994',
            'primary_category_name' => 'Rings',
            'field_mappings' => [
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
            'product_template_field_id' => $field->id,
            'value' => 'white-gold',
        ]);

        $service = app(ListingBuilderService::class);
        $listing = $service->buildListing($product, $marketplace);

        // Should show label "White Gold" not raw value "white-gold"
        $this->assertEquals('White Gold', $listing['attributes']['Material']);
        $this->assertEquals(['White Gold'], $listing['aspects']['Material']);
    }

    public function test_field_mapping_service_uses_display_values(): void
    {
        $template = ProductTemplate::factory()->create(['store_id' => $this->store->id]);

        $field = ProductTemplateField::factory()->select()->create([
            'product_template_id' => $template->id,
            'name' => 'color',
            'label' => 'Color',
        ]);

        ProductTemplateFieldOption::factory()->create([
            'product_template_field_id' => $field->id,
            'label' => 'Rose Gold',
            'value' => 'rose-gold',
        ]);

        TemplatePlatformMapping::factory()->create([
            'product_template_id' => $template->id,
            'platform' => Platform::Ebay,
            'field_mappings' => [
                'color' => 'Color',
            ],
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'template_id' => $template->id,
        ]);

        ProductAttributeValue::create([
            'product_id' => $product->id,
            'product_template_field_id' => $field->id,
            'value' => 'rose-gold',
        ]);

        $service = app(FieldMappingService::class);
        $transformed = $service->transformAttributes($product, Platform::Ebay);

        $this->assertEquals('Rose Gold', $transformed['Color']);
    }

    public function test_set_template_attribute_value_rejects_zero_for_select(): void
    {
        $template = ProductTemplate::factory()->create(['store_id' => $this->store->id]);

        $field = ProductTemplateField::factory()->select()->create([
            'product_template_id' => $template->id,
            'name' => 'gemstone',
            'label' => 'Gemstone',
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'template_id' => $template->id,
        ]);

        // Setting "0" for a select field should delete/not create the attribute value
        $product->setTemplateAttributeValue($field->id, '0');

        $this->assertDatabaseMissing('product_attribute_values', [
            'product_id' => $product->id,
            'product_template_field_id' => $field->id,
        ]);
    }

    public function test_shopify_metafield_type_uses_definition(): void
    {
        $template = ProductTemplate::factory()->create(['store_id' => $this->store->id]);

        $field = ProductTemplateField::factory()->text()->create([
            'product_template_id' => $template->id,
            'name' => 'weight',
            'label' => 'Weight',
            'is_private' => false,
        ]);

        $marketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $this->store->id,
        ]);

        // Create a metafield definition that says "weight" is number_decimal
        ShopifyMetafieldDefinition::factory()->create([
            'store_marketplace_id' => $marketplace->id,
            'namespace' => 'custom',
            'key' => 'weight',
            'type' => 'number_decimal',
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'template_id' => $template->id,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 100,
        ]);

        ProductAttributeValue::create([
            'product_id' => $product->id,
            'product_template_field_id' => $field->id,
            'value' => '3.5',
        ]);

        $service = app(ListingBuilderService::class);
        $listing = $service->buildListing($product, $marketplace);

        $weightMetafield = collect($listing['metafields'])->firstWhere('key', 'weight');
        $this->assertNotNull($weightMetafield);
        // Should use the definition type, not the guessed type
        $this->assertEquals('number_decimal', $weightMetafield['type']);
    }
}
