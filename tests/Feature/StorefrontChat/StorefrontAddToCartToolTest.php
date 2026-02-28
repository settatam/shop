<?php

namespace Tests\Feature\StorefrontChat;

use App\Models\PlatformListing;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesChannel;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Services\StorefrontChat\Tools\StorefrontAddToCartTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontAddToCartToolTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected StoreMarketplace $marketplace;

    protected SalesChannel $salesChannel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->marketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $this->store->id,
            'status' => 'active',
        ]);
        $this->salesChannel = SalesChannel::factory()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
            'is_active' => true,
            'auto_list' => true,
        ]);
    }

    /**
     * Create a product with a variant and set up the Shopify external variant ID.
     *
     * @return array{product: Product, variant: ProductVariant}
     */
    protected function createProductWithShopifyListing(array $productOverrides = [], array $variantOverrides = [], string $externalVariantId = '44000000001'): array
    {
        $product = Product::factory()->create(array_merge([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
        ], $productOverrides));

        $variant = ProductVariant::factory()->create(array_merge([
            'product_id' => $product->id,
            'quantity' => 5,
        ], $variantOverrides));

        // Re-sync all listings to pick up the newly created variant
        $product->load('variants');
        $product->createListingsForAllActiveChannels();

        // Set the Shopify external_variant_id on the listing variant for the explicit channel
        $listing = $product->platformListings()
            ->where('sales_channel_id', $this->salesChannel->id)
            ->where('status', PlatformListing::STATUS_LISTED)
            ->first();

        if ($listing) {
            $listing->listingVariants()
                ->where('product_variant_id', $variant->id)
                ->update(['external_variant_id' => $externalVariantId]);
        }

        return ['product' => $product->fresh(), 'variant' => $variant];
    }

    public function test_returns_cart_data_for_valid_product(): void
    {
        ['product' => $product] = $this->createProductWithShopifyListing(
            ['title' => 'Gold Diamond Ring'],
            ['price' => 1299.99, 'quantity' => 5],
            '44000000001'
        );

        $tool = new StorefrontAddToCartTool;
        $result = $tool->execute(['product_id' => $product->id], $this->store->id);

        $this->assertTrue($result['success']);
        $this->assertEquals('44000000001', $result['shopify_variant_id']);
        $this->assertEquals('Gold Diamond Ring', $result['product_title']);
        $this->assertEquals(1, $result['quantity']);
        $this->assertStringContainsString('44000000001', $result['cart_url']);
    }

    public function test_returns_error_for_non_existent_product(): void
    {
        $tool = new StorefrontAddToCartTool;
        $result = $tool->execute(['product_id' => 99999], $this->store->id);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('not found', $result['error']);
    }

    public function test_returns_error_for_inactive_product(): void
    {
        $product = Product::factory()->draft()->create([
            'store_id' => $this->store->id,
        ]);

        $tool = new StorefrontAddToCartTool;
        $result = $tool->execute(['product_id' => $product->id], $this->store->id);

        $this->assertArrayHasKey('error', $result);
    }

    public function test_returns_error_for_product_from_other_store(): void
    {
        $otherStore = Store::factory()->create();
        $product = Product::factory()->create([
            'store_id' => $otherStore->id,
            'status' => Product::STATUS_ACTIVE,
        ]);

        $tool = new StorefrontAddToCartTool;
        $result = $tool->execute(['product_id' => $product->id], $this->store->id);

        $this->assertArrayHasKey('error', $result);
    }

    public function test_uses_correct_shopify_variant_id_from_platform_listing(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
        ]);

        $variant1 = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 500.00,
            'quantity' => 3,
        ]);

        $variant2 = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 800.00,
            'quantity' => 2,
        ]);

        // Re-sync listings to pick up both variants
        $product->load('variants');
        $product->createListingsForAllActiveChannels();

        $listing = $product->platformListings()
            ->where('sales_channel_id', $this->salesChannel->id)
            ->where('status', PlatformListing::STATUS_LISTED)
            ->first();

        $listing->listingVariants()
            ->where('product_variant_id', $variant1->id)
            ->update(['external_variant_id' => '11111111111']);

        $listing->listingVariants()
            ->where('product_variant_id', $variant2->id)
            ->update(['external_variant_id' => '22222222222']);

        $tool = new StorefrontAddToCartTool;
        $result = $tool->execute([
            'product_id' => $product->id,
            'variant_id' => $variant2->id,
        ], $this->store->id);

        $this->assertTrue($result['success']);
        $this->assertEquals('22222222222', $result['shopify_variant_id']);
    }

    public function test_handles_quantity_parameter(): void
    {
        ['product' => $product] = $this->createProductWithShopifyListing(
            [],
            ['quantity' => 10],
            '33333333333'
        );

        $tool = new StorefrontAddToCartTool;
        $result = $tool->execute([
            'product_id' => $product->id,
            'quantity' => 3,
        ], $this->store->id);

        $this->assertTrue($result['success']);
        $this->assertEquals(3, $result['quantity']);
        $this->assertStringContainsString('quantity=3', $result['cart_url']);
    }

    public function test_handles_missing_variant_id_uses_default(): void
    {
        ['product' => $product] = $this->createProductWithShopifyListing(
            [],
            ['quantity' => 5],
            '55555555555'
        );

        $tool = new StorefrontAddToCartTool;
        $result = $tool->execute(['product_id' => $product->id], $this->store->id);

        $this->assertTrue($result['success']);
        $this->assertEquals('55555555555', $result['shopify_variant_id']);
    }

    public function test_returns_error_for_out_of_stock_product(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'quantity' => 0,
        ]);

        $tool = new StorefrontAddToCartTool;
        $result = $tool->execute(['product_id' => $product->id], $this->store->id);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('out of stock', $result['error']);
    }

    public function test_returns_error_when_no_shopify_variant_id(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'quantity' => 5,
        ]);

        // Auto-created listing variants have no external_variant_id
        $tool = new StorefrontAddToCartTool;
        $result = $tool->execute(['product_id' => $product->id], $this->store->id);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not currently available', $result['error']);
    }
}
