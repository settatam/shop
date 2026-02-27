<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductTemplate;
use App\Models\ProductTemplateField;
use App\Models\ProductTemplateFieldOption;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\ShopifyMetafieldDefinition;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopifyMetafieldEditorTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected StoreMarketplace $marketplace;

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

        $this->marketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $this->store->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
        $this->actingAs($this->user);
    }

    public function test_shopify_listing_page_includes_metafield_definitions(): void
    {
        $template = ProductTemplate::factory()->create(['store_id' => $this->store->id]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'template_id' => $template->id,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
        ]);

        ShopifyMetafieldDefinition::factory()->create([
            'store_marketplace_id' => $this->marketplace->id,
            'key' => 'material',
            'namespace' => 'custom',
            'name' => 'Material',
            'type' => 'single_line_text_field',
        ]);

        ShopifyMetafieldDefinition::factory()->create([
            'store_marketplace_id' => $this->marketplace->id,
            'key' => 'weight',
            'namespace' => 'custom',
            'name' => 'Weight',
            'type' => 'number_decimal',
        ]);

        $response = $this->get("/products/{$product->id}/platforms/{$this->marketplace->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('products/platforms/Show')
            ->has('shopifyMetafields')
            ->where('shopifyMetafields.has_definitions', true)
            ->has('shopifyMetafields.definitions', 2)
            ->where('shopifyMetafields.definitions.0.key', 'material')
            ->where('shopifyMetafields.definitions.0.type', 'single_line_text_field')
        );
    }

    public function test_shopify_listing_page_returns_null_for_non_shopify(): void
    {
        $ebayMarketplace = StoreMarketplace::factory()->ebay()->create([
            'store_id' => $this->store->id,
        ]);

        $template = ProductTemplate::factory()->create(['store_id' => $this->store->id]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'template_id' => $template->id,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
        ]);

        $response = $this->get("/products/{$product->id}/platforms/{$ebayMarketplace->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('products/platforms/Show')
            ->where('shopifyMetafields', null)
        );
    }

    public function test_shopify_metafield_definitions_include_resolved_values_from_template_fields(): void
    {
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

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'template_id' => $template->id,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
        ]);

        // Set attribute value
        $product->attributeValues()->create([
            'product_template_field_id' => $field->id,
            'value' => 'white-gold',
        ]);

        // Create metafield definition
        ShopifyMetafieldDefinition::factory()->create([
            'store_marketplace_id' => $this->marketplace->id,
            'key' => 'material',
            'namespace' => 'custom',
            'name' => 'Material',
            'type' => 'single_line_text_field',
        ]);

        // Create listing with field mapping
        $product->platformListings()->create([
            'store_marketplace_id' => $this->marketplace->id,
            'status' => 'draft',
            'metafield_overrides' => [
                'field_mappings' => [
                    'custom.material' => 'material',
                ],
            ],
        ]);

        $response = $this->get("/products/{$product->id}/platforms/{$this->marketplace->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('products/platforms/Show')
            ->where('shopifyMetafields.definitions.0.mapped_template_field', 'material')
            ->where('shopifyMetafields.definitions.0.resolved_value', 'White Gold')
        );
    }

    public function test_shopify_metafields_empty_when_no_definitions_synced(): void
    {
        $template = ProductTemplate::factory()->create(['store_id' => $this->store->id]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'template_id' => $template->id,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
        ]);

        $response = $this->get("/products/{$product->id}/platforms/{$this->marketplace->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('products/platforms/Show')
            ->where('shopifyMetafields.has_definitions', false)
            ->has('shopifyMetafields.definitions', 0)
        );
    }

    public function test_ai_suggest_shopify_metafields_type_is_accepted(): void
    {
        $template = ProductTemplate::factory()->create(['store_id' => $this->store->id]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'template_id' => $template->id,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
        ]);

        // Without definitions, should return error but not 422 validation
        $response = $this->postJson(
            "/products/{$product->id}/platforms/{$this->marketplace->id}/ai-suggest",
            ['type' => 'shopify_metafields'],
        );

        $response->assertStatus(200);
        $response->assertJsonStructure(['success']);
    }
}
