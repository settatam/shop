<?php

namespace Tests\Feature\WooCommerce;

use App\Jobs\ImportWooCommerceProductsJob;
use App\Models\PlatformListing;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Services\Platforms\WooCommerce\WooCommerceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ImportWooCommerceProductsJobTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected StoreMarketplace $marketplace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->marketplace = StoreMarketplace::factory()->woocommerce()->create([
            'store_id' => $this->store->id,
            'shop_domain' => 'https://test-store.com',
            'access_token' => 'ck_test_key',
            'status' => 'active',
            'credentials' => [
                'site_url' => 'https://test-store.com',
                'consumer_key' => 'ck_test_key',
                'consumer_secret' => encrypt('cs_test_secret'),
            ],
        ]);
    }

    public function test_imports_products_from_woo_commerce(): void
    {
        $wooProducts = collect([
            [
                'external_id' => 101,
                'title' => 'Blue T-Shirt',
                'description' => 'A nice blue t-shirt',
                'sku' => 'BTS-001',
                'price' => '29.99',
                'regular_price' => '29.99',
                'sale_price' => '',
                'quantity' => 50,
                'status' => 'publish',
                'categories' => ['Apparel'],
                'images' => ['https://test-store.com/img/tshirt.jpg'],
                'variants' => [],
            ],
            [
                'external_id' => 102,
                'title' => 'Red Hoodie',
                'description' => 'A warm red hoodie',
                'sku' => 'RH-001',
                'price' => '59.99',
                'regular_price' => '59.99',
                'sale_price' => '',
                'quantity' => 25,
                'status' => 'publish',
                'categories' => ['Apparel'],
                'images' => [],
                'variants' => [],
            ],
        ]);

        $mockService = Mockery::mock(WooCommerceService::class);
        $mockService->shouldReceive('pullProducts')
            ->with(Mockery::on(fn ($m) => $m->id === $this->marketplace->id))
            ->once()
            ->andReturn($wooProducts);

        $job = new ImportWooCommerceProductsJob($this->marketplace);
        $job->handle($mockService);

        $this->assertDatabaseHas('products', [
            'store_id' => $this->store->id,
            'title' => 'Blue T-Shirt',
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('products', [
            'store_id' => $this->store->id,
            'title' => 'Red Hoodie',
        ]);

        // Verify marketplace-linked listings were created
        $this->assertDatabaseHas('platform_listings', [
            'store_marketplace_id' => $this->marketplace->id,
            'external_listing_id' => '101',
            'status' => PlatformListing::STATUS_LISTED,
        ]);
        $this->assertDatabaseHas('platform_listings', [
            'store_marketplace_id' => $this->marketplace->id,
            'external_listing_id' => '102',
            'status' => PlatformListing::STATUS_LISTED,
        ]);
    }

    public function test_skips_already_imported_products(): void
    {
        // Create a product that appears already imported
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        PlatformListing::create([
            'product_id' => $product->id,
            'store_marketplace_id' => $this->marketplace->id,
            'external_listing_id' => '101',
            'status' => PlatformListing::STATUS_LISTED,
        ]);

        $listingCountBefore = PlatformListing::where('store_marketplace_id', $this->marketplace->id)->count();

        $wooProducts = collect([
            [
                'external_id' => 101,
                'title' => 'Already Imported Product',
                'description' => 'Should be skipped',
                'sku' => 'AIP-001',
                'price' => '19.99',
                'regular_price' => '19.99',
                'sale_price' => '',
                'quantity' => 10,
                'status' => 'publish',
                'categories' => [],
                'images' => [],
                'variants' => [],
            ],
        ]);

        $mockService = Mockery::mock(WooCommerceService::class);
        $mockService->shouldReceive('pullProducts')->andReturn($wooProducts);

        $job = new ImportWooCommerceProductsJob($this->marketplace);
        $job->handle($mockService);

        // No new marketplace listings should have been created
        $this->assertEquals(
            $listingCountBefore,
            PlatformListing::where('store_marketplace_id', $this->marketplace->id)->count()
        );
    }

    public function test_creates_product_variants_when_provided(): void
    {
        $wooProducts = collect([
            [
                'external_id' => 201,
                'title' => 'Multi-Size Shirt',
                'description' => 'Available in S, M, L',
                'sku' => 'MS-001',
                'price' => '34.99',
                'regular_price' => '34.99',
                'sale_price' => '',
                'quantity' => 100,
                'status' => 'publish',
                'categories' => [],
                'images' => [],
                'variants' => [
                    ['sku' => 'MS-001-S', 'price' => '34.99', 'quantity' => 30],
                    ['sku' => 'MS-001-M', 'price' => '34.99', 'quantity' => 40],
                    ['sku' => 'MS-001-L', 'price' => '34.99', 'quantity' => 30],
                ],
            ],
        ]);

        $mockService = Mockery::mock(WooCommerceService::class);
        $mockService->shouldReceive('pullProducts')->andReturn($wooProducts);

        $job = new ImportWooCommerceProductsJob($this->marketplace);
        $job->handle($mockService);

        $this->assertDatabaseHas('products', [
            'title' => 'Multi-Size Shirt',
            'has_variants' => true,
        ]);

        $product = Product::where('title', 'Multi-Size Shirt')->first();
        $this->assertEquals(3, $product->variants()->count());
        $this->assertDatabaseHas('product_variants', ['sku' => 'MS-001-S', 'product_id' => $product->id]);
        $this->assertDatabaseHas('product_variants', ['sku' => 'MS-001-M', 'product_id' => $product->id]);
        $this->assertDatabaseHas('product_variants', ['sku' => 'MS-001-L', 'product_id' => $product->id]);
    }

    public function test_creates_default_variant_when_no_variants_provided(): void
    {
        $wooProducts = collect([
            [
                'external_id' => 301,
                'title' => 'Simple Product',
                'description' => 'No variants',
                'sku' => 'SP-001',
                'price' => '19.99',
                'regular_price' => '19.99',
                'sale_price' => '',
                'quantity' => 5,
                'status' => 'publish',
                'categories' => [],
                'images' => [],
                'variants' => [],
            ],
        ]);

        $mockService = Mockery::mock(WooCommerceService::class);
        $mockService->shouldReceive('pullProducts')->andReturn($wooProducts);

        $job = new ImportWooCommerceProductsJob($this->marketplace);
        $job->handle($mockService);

        $product = Product::where('title', 'Simple Product')->first();
        $this->assertNotNull($product);
        $this->assertDatabaseHas('product_variants', [
            'product_id' => $product->id,
            'sku' => 'SP-001',
            'price' => '19.99',
            'quantity' => 5,
        ]);
    }

    public function test_handles_draft_products(): void
    {
        $wooProducts = collect([
            [
                'external_id' => 401,
                'title' => 'Draft Product',
                'description' => 'Not published yet',
                'sku' => 'DP-001',
                'price' => '9.99',
                'regular_price' => '9.99',
                'sale_price' => '',
                'quantity' => 0,
                'status' => 'draft',
                'categories' => [],
                'images' => [],
                'variants' => [],
            ],
        ]);

        $mockService = Mockery::mock(WooCommerceService::class);
        $mockService->shouldReceive('pullProducts')->andReturn($wooProducts);

        $job = new ImportWooCommerceProductsJob($this->marketplace);
        $job->handle($mockService);

        $this->assertDatabaseHas('products', [
            'title' => 'Draft Product',
            'is_published' => false,
        ]);

        $this->assertDatabaseHas('platform_listings', [
            'store_marketplace_id' => $this->marketplace->id,
            'external_listing_id' => '401',
            'published_at' => null,
        ]);
    }
}
