<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\PlatformListing;
use App\Models\PlatformListingVariant;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductImage;
use App\Models\ProductTemplate;
use App\Models\ProductTemplateField;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MigrateLegacyProductsTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Test Store',
            'step' => 2,
        ]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);
    }

    public function test_product_attribute_values_preserve_timestamps(): void
    {
        // Create a template with fields
        $template = ProductTemplate::create([
            'store_id' => $this->store->id,
            'name' => 'Jewelry Template',
            'is_active' => true,
        ]);

        $metalTypeField = ProductTemplateField::create([
            'product_template_id' => $template->id,
            'name' => 'metal_type',
            'canonical_name' => 'Metal Type',
            'label' => 'Metal Type',
            'type' => 'select',
            'sort_order' => 0,
        ]);

        // Create a product with template
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'template_id' => $template->id,
            'title' => 'Test Ring',
        ]);

        $legacyCreatedAt = '2023-05-15 10:30:00';
        $legacyUpdatedAt = '2023-06-20 14:45:00';

        // Insert directly with DB to preserve timestamps
        $attributeValueId = DB::table('product_attribute_values')->insertGetId([
            'product_id' => $product->id,
            'product_template_field_id' => $metalTypeField->id,
            'value' => '14K Gold',
            'created_at' => $legacyCreatedAt,
            'updated_at' => $legacyUpdatedAt,
        ]);

        // Retrieve and verify timestamps are preserved
        $retrievedValue = ProductAttributeValue::find($attributeValueId);
        $this->assertEquals('14K Gold', $retrievedValue->value);
        $this->assertEquals($metalTypeField->id, $retrievedValue->product_template_field_id);
        $this->assertEquals($legacyCreatedAt, $retrievedValue->created_at->format('Y-m-d H:i:s'));
        $this->assertEquals($legacyUpdatedAt, $retrievedValue->updated_at->format('Y-m-d H:i:s'));
    }

    public function test_product_images_preserve_timestamps(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Test Product',
        ]);

        $legacyCreatedAt = '2023-03-10 08:00:00';
        $legacyUpdatedAt = '2023-04-15 12:30:00';

        // Insert directly with DB to preserve timestamps
        $imageId = DB::table('product_images')->insertGetId([
            'product_id' => $product->id,
            'path' => 'products/legacy-ring.jpg',
            'alt_text' => 'Beautiful gold ring',
            'sort_order' => 0,
            'is_primary' => true,
            'created_at' => $legacyCreatedAt,
            'updated_at' => $legacyUpdatedAt,
        ]);

        // Retrieve and verify
        $retrievedImage = ProductImage::find($imageId);
        $this->assertEquals('products/legacy-ring.jpg', $retrievedImage->path);
        $this->assertTrue((bool) $retrievedImage->is_primary);
        $this->assertEquals($legacyCreatedAt, $retrievedImage->created_at->format('Y-m-d H:i:s'));
        $this->assertEquals($legacyUpdatedAt, $retrievedImage->updated_at->format('Y-m-d H:i:s'));
    }

    public function test_products_preserve_timestamps(): void
    {
        $legacyCreatedAt = '2022-01-15 09:00:00';
        $legacyUpdatedAt = '2022-06-20 16:30:00';

        // Insert directly with DB to preserve timestamps
        $productId = DB::table('products')->insertGetId([
            'store_id' => $this->store->id,
            'title' => 'Vintage Ring',
            'description' => 'A beautiful vintage ring',
            'handle' => 'vintage-ring-123',
            'is_published' => true,
            'created_at' => $legacyCreatedAt,
            'updated_at' => $legacyUpdatedAt,
        ]);

        // Retrieve and verify
        $retrievedProduct = Product::find($productId);
        $this->assertEquals('Vintage Ring', $retrievedProduct->title);
        $this->assertEquals($legacyCreatedAt, $retrievedProduct->created_at->format('Y-m-d H:i:s'));
        $this->assertEquals($legacyUpdatedAt, $retrievedProduct->updated_at->format('Y-m-d H:i:s'));
    }

    public function test_product_variants_preserve_timestamps(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Test Product',
        ]);

        $legacyCreatedAt = '2021-11-20 14:00:00';
        $legacyUpdatedAt = '2022-02-10 11:15:00';

        // Insert directly with DB to preserve timestamps
        $variantId = DB::table('product_variants')->insertGetId([
            'product_id' => $product->id,
            'sku' => 'LEGACY-001',
            'price' => 199.99,
            'cost' => 100.00,
            'quantity' => 5,
            'is_active' => true,
            'created_at' => $legacyCreatedAt,
            'updated_at' => $legacyUpdatedAt,
        ]);

        // Retrieve and verify
        $retrievedVariant = ProductVariant::find($variantId);
        $this->assertEquals('LEGACY-001', $retrievedVariant->sku);
        $this->assertEquals($legacyCreatedAt, $retrievedVariant->created_at->format('Y-m-d H:i:s'));
        $this->assertEquals($legacyUpdatedAt, $retrievedVariant->updated_at->format('Y-m-d H:i:s'));
    }

    public function test_product_with_all_related_data(): void
    {
        // Create template
        $template = ProductTemplate::create([
            'store_id' => $this->store->id,
            'name' => 'Ring Template',
            'is_active' => true,
        ]);

        $field = ProductTemplateField::create([
            'product_template_id' => $template->id,
            'name' => 'carat_weight',
            'canonical_name' => 'Carat Weight',
            'label' => 'Carat Weight',
            'type' => 'number',
            'sort_order' => 0,
        ]);

        // Create category
        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Rings',
            'template_id' => $template->id,
        ]);

        // Create product with legacy timestamp using DB
        $productCreatedAt = '2021-06-15 10:00:00';
        $productId = DB::table('products')->insertGetId([
            'store_id' => $this->store->id,
            'category_id' => $category->id,
            'template_id' => $template->id,
            'title' => 'Diamond Engagement Ring',
            'description' => '1 carat diamond ring',
            'handle' => 'diamond-engagement-ring-legacy',
            'is_published' => true,
            'created_at' => $productCreatedAt,
            'updated_at' => $productCreatedAt,
        ]);

        // Create variant with legacy timestamp
        $variantCreatedAt = '2021-06-15 10:05:00';
        $variantId = DB::table('product_variants')->insertGetId([
            'product_id' => $productId,
            'sku' => 'RING-001',
            'price' => 2999.99,
            'cost' => 1500.00,
            'quantity' => 1,
            'is_active' => true,
            'created_at' => $variantCreatedAt,
            'updated_at' => $variantCreatedAt,
        ]);

        // Create attribute value with legacy timestamp
        $attrCreatedAt = '2021-06-15 10:10:00';
        $attributeValueId = DB::table('product_attribute_values')->insertGetId([
            'product_id' => $productId,
            'product_template_field_id' => $field->id,
            'value' => '1.25',
            'created_at' => $attrCreatedAt,
            'updated_at' => $attrCreatedAt,
        ]);

        // Create image with legacy timestamp
        $imageCreatedAt = '2021-06-15 10:15:00';
        $imageId = DB::table('product_images')->insertGetId([
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'path' => 'products/diamond-ring.jpg',
            'alt_text' => 'Diamond engagement ring',
            'sort_order' => 0,
            'is_primary' => true,
            'created_at' => $imageCreatedAt,
            'updated_at' => $imageCreatedAt,
        ]);

        // Retrieve all entities
        $product = Product::find($productId);
        $variant = ProductVariant::find($variantId);
        $attributeValue = ProductAttributeValue::find($attributeValueId);
        $image = ProductImage::find($imageId);

        // Verify all timestamps are preserved
        $this->assertEquals($productCreatedAt, $product->created_at->format('Y-m-d H:i:s'));
        $this->assertEquals($variantCreatedAt, $variant->created_at->format('Y-m-d H:i:s'));
        $this->assertEquals($attrCreatedAt, $attributeValue->created_at->format('Y-m-d H:i:s'));
        $this->assertEquals($imageCreatedAt, $image->created_at->format('Y-m-d H:i:s'));

        // Verify relationships
        $this->assertEquals($template->id, $product->template_id);
        $this->assertEquals($category->id, $product->category_id);
        $this->assertEquals($productId, $variant->product_id);
        $this->assertEquals($field->id, $attributeValue->product_template_field_id);
        $this->assertEquals($variantId, $image->product_variant_id);
    }

    public function test_attribute_value_unique_constraint(): void
    {
        $template = ProductTemplate::create([
            'store_id' => $this->store->id,
            'name' => 'Test Template',
            'is_active' => true,
        ]);

        $field = ProductTemplateField::create([
            'product_template_id' => $template->id,
            'name' => 'color',
            'canonical_name' => 'Color',
            'label' => 'Color',
            'type' => 'text',
            'sort_order' => 0,
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'template_id' => $template->id,
        ]);

        // Create first attribute value
        ProductAttributeValue::create([
            'product_id' => $product->id,
            'product_template_field_id' => $field->id,
            'value' => 'Gold',
        ]);

        // Attempting to create duplicate should fail due to unique constraint
        $this->expectException(\Illuminate\Database\QueryException::class);

        ProductAttributeValue::create([
            'product_id' => $product->id,
            'product_template_field_id' => $field->id,
            'value' => 'Silver',
        ]);
    }

    public function test_cascade_delete_removes_attribute_values_and_images(): void
    {
        $template = ProductTemplate::create([
            'store_id' => $this->store->id,
            'name' => 'Test Template',
            'is_active' => true,
        ]);

        $field = ProductTemplateField::create([
            'product_template_id' => $template->id,
            'name' => 'material',
            'label' => 'Material',
            'type' => 'text',
            'sort_order' => 0,
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'template_id' => $template->id,
        ]);

        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
        ]);

        $attributeValue = ProductAttributeValue::create([
            'product_id' => $product->id,
            'product_template_field_id' => $field->id,
            'value' => 'Platinum',
        ]);

        $image = ProductImage::create([
            'product_id' => $product->id,
            'path' => 'test.jpg',
            'sort_order' => 0,
            'is_primary' => true,
        ]);

        $productId = $product->id;
        $attributeValueId = $attributeValue->id;
        $imageId = $image->id;

        // Delete product
        $product->forceDelete();

        // Verify cascade delete
        $this->assertNull(ProductAttributeValue::find($attributeValueId));
        $this->assertNull(ProductImage::find($imageId));
    }

    public function test_migration_command_exists(): void
    {
        // Verify the command is registered
        $this->artisan('migrate:legacy-products', ['--help' => true])
            ->assertSuccessful();
    }

    public function test_map_listing_status_uses_new_constants(): void
    {
        $command = new \App\Console\Commands\MigrateLegacyProducts;

        $method = new \ReflectionMethod($command, 'mapListingStatus');

        $this->assertEquals(PlatformListing::STATUS_LISTED, $method->invoke($command, 'listed'));
        $this->assertEquals(PlatformListing::STATUS_NOT_LISTED, $method->invoke($command, 'not_listed'));
        $this->assertEquals(PlatformListing::STATUS_ARCHIVED, $method->invoke($command, 'archived'));
        $this->assertEquals(PlatformListing::STATUS_ENDED, $method->invoke($command, 'listing_expired'));
        $this->assertEquals(PlatformListing::STATUS_ENDED, $method->invoke($command, 'listing_ended'));
        $this->assertEquals(PlatformListing::STATUS_ERROR, $method->invoke($command, 'listing_error'));
        $this->assertEquals(PlatformListing::STATUS_NOT_LISTED, $method->invoke($command, null));
        $this->assertEquals(PlatformListing::STATUS_NOT_LISTED, $method->invoke($command, 'unknown_status'));
    }

    public function test_platform_listing_does_not_have_product_variant_id_column(): void
    {
        // Create product first (before channel exists, to avoid auto-listing)
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_DRAFT,
        ]);

        // Create channel after product — no auto-listing triggered
        $channel = SalesChannel::factory()->active()->create([
            'store_id' => $this->store->id,
            'code' => 'test_channel_'.uniqid(),
            'is_local' => false,
        ]);

        // Verify the listing can be created without product_variant_id
        $listing = PlatformListing::create([
            'sales_channel_id' => $channel->id,
            'product_id' => $product->id,
            'status' => PlatformListing::STATUS_NOT_LISTED,
        ]);

        $this->assertDatabaseHas('platform_listings', [
            'id' => $listing->id,
            'product_id' => $product->id,
            'status' => PlatformListing::STATUS_NOT_LISTED,
        ]);
    }

    public function test_platform_listing_variants_store_per_variant_data(): void
    {
        // Create product as draft to avoid auto-listing
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_DRAFT,
        ]);

        $variant1 = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'VAR-001',
            'price' => 199.99,
        ]);

        $variant2 = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'VAR-002',
            'price' => 249.99,
        ]);

        // Create channel after product
        $channel = SalesChannel::factory()->active()->create([
            'store_id' => $this->store->id,
            'code' => 'test_channel_'.uniqid(),
            'is_local' => false,
        ]);

        $listing = PlatformListing::create([
            'sales_channel_id' => $channel->id,
            'product_id' => $product->id,
            'status' => PlatformListing::STATUS_LISTED,
            'published_at' => now(),
        ]);

        // Simulate what the migration does: create listing variants
        PlatformListingVariant::create([
            'platform_listing_id' => $listing->id,
            'product_variant_id' => $variant1->id,
            'external_variant_id' => '12345678901',
            'price' => 219.99,
            'quantity' => 3,
            'sku' => 'VAR-001',
            'status' => 'active',
        ]);

        PlatformListingVariant::create([
            'platform_listing_id' => $listing->id,
            'product_variant_id' => $variant2->id,
            'external_variant_id' => '12345678902',
            'price' => 279.99,
            'quantity' => 0,
            'sku' => 'VAR-002',
            'status' => 'inactive',
        ]);

        $this->assertCount(2, $listing->listingVariants);

        $lv1 = PlatformListingVariant::where('platform_listing_id', $listing->id)
            ->where('product_variant_id', $variant1->id)
            ->first();
        $this->assertEquals('12345678901', $lv1->external_variant_id);
        $this->assertEquals('219.99', $lv1->price);
        $this->assertEquals('active', $lv1->status);

        $lv2 = PlatformListingVariant::where('platform_listing_id', $listing->id)
            ->where('product_variant_id', $variant2->id)
            ->first();
        $this->assertEquals('12345678902', $lv2->external_variant_id);
        $this->assertEquals('279.99', $lv2->price);
        $this->assertEquals('inactive', $lv2->status);
    }

    public function test_missing_listing_created_as_not_listed_with_all_variants(): void
    {
        // Create product as draft first
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_DRAFT,
        ]);

        $variant1 = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'MISSING-001',
            'price' => 100.00,
            'quantity' => 5,
        ]);

        $variant2 = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'MISSING-002',
            'price' => 150.00,
            'quantity' => 3,
        ]);

        // Create channel after product — no auto-listing
        $channel = SalesChannel::factory()->active()->create([
            'store_id' => $this->store->id,
            'code' => 'missing_channel_'.uniqid(),
            'is_local' => false,
        ]);

        // Simulate ensureMissingListings: create as not_listed
        $listing = PlatformListing::create([
            'sales_channel_id' => $channel->id,
            'product_id' => $product->id,
            'status' => PlatformListing::STATUS_NOT_LISTED,
        ]);

        foreach ($product->variants as $variant) {
            PlatformListingVariant::create([
                'platform_listing_id' => $listing->id,
                'product_variant_id' => $variant->id,
                'price' => $variant->price,
                'quantity' => $variant->quantity,
                'sku' => $variant->sku,
                'status' => 'active',
            ]);
        }

        $this->assertEquals(PlatformListing::STATUS_NOT_LISTED, $listing->status);
        $this->assertNull($listing->published_at);

        $listingVariants = PlatformListingVariant::where('platform_listing_id', $listing->id)->get();
        $this->assertCount(2, $listingVariants);
        $this->assertDatabaseHas('platform_listing_variants', [
            'platform_listing_id' => $listing->id,
            'product_variant_id' => $variant1->id,
            'sku' => 'MISSING-001',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('platform_listing_variants', [
            'platform_listing_id' => $listing->id,
            'product_variant_id' => $variant2->id,
            'sku' => 'MISSING-002',
            'status' => 'active',
        ]);
    }

    public function test_cleanup_removes_platform_listing_variants(): void
    {
        // Create product as draft to avoid auto-listing
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_DRAFT,
        ]);

        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
        ]);

        // Create channel after product
        $channel = SalesChannel::factory()->active()->create([
            'store_id' => $this->store->id,
            'code' => 'cleanup_channel_'.uniqid(),
            'is_local' => false,
        ]);

        $listing = PlatformListing::create([
            'sales_channel_id' => $channel->id,
            'product_id' => $product->id,
            'status' => PlatformListing::STATUS_NOT_LISTED,
        ]);

        $listingVariant = PlatformListingVariant::create([
            'platform_listing_id' => $listing->id,
            'product_variant_id' => $variant->id,
            'status' => 'active',
        ]);

        // Simulate cleanupExistingProducts behavior
        $productIds = Product::where('store_id', $this->store->id)->pluck('id');
        $listingIds = PlatformListing::whereIn('product_id', $productIds)->pluck('id');
        PlatformListingVariant::whereIn('platform_listing_id', $listingIds)->delete();
        PlatformListing::whereIn('product_id', $productIds)->forceDelete();

        $this->assertDatabaseMissing('platform_listing_variants', ['id' => $listingVariant->id]);
        $this->assertDatabaseMissing('platform_listings', ['id' => $listing->id]);
    }

    public function test_listing_without_channel_variants_gets_fallback_variant(): void
    {
        // Create product as draft first
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_DRAFT,
        ]);

        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'FALLBACK-001',
            'price' => 299.99,
            'quantity' => 2,
        ]);

        // Create channel after product
        $channel = SalesChannel::factory()->active()->create([
            'store_id' => $this->store->id,
            'code' => 'fallback_channel_'.uniqid(),
            'is_local' => false,
        ]);

        // Simulate the migration creating a listing via DB::table (bypasses booted)
        $listingId = DB::table('platform_listings')->insertGetId([
            'sales_channel_id' => $channel->id,
            'product_id' => $product->id,
            'status' => PlatformListing::STATUS_NOT_LISTED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // No channel variants — create fallback from first product variant
        $defaultVariant = ProductVariant::where('product_id', $product->id)->first();
        DB::table('platform_listing_variants')->insert([
            'platform_listing_id' => $listingId,
            'product_variant_id' => $defaultVariant->id,
            'price' => $defaultVariant->price,
            'quantity' => $defaultVariant->quantity,
            'sku' => $defaultVariant->sku,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('platform_listing_variants', [
            'platform_listing_id' => $listingId,
            'product_variant_id' => $variant->id,
            'sku' => 'FALLBACK-001',
            'status' => 'active',
        ]);
    }
}
