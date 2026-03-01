<?php

namespace Tests\Feature\BigCommerce;

use App\Jobs\ImportBigCommerceProductsJob;
use App\Models\PlatformListing;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Services\Platforms\BigCommerce\BigCommerceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ImportBigCommerceProductsJobTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected StoreMarketplace $marketplace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->marketplace = StoreMarketplace::factory()->bigcommerce()->create([
            'store_id' => $this->store->id,
            'external_store_id' => 'abc123',
            'status' => 'active',
        ]);
    }

    public function test_imports_products_from_big_commerce(): void
    {
        $bcProducts = collect([
            [
                'external_id' => '101',
                'title' => 'Canvas Backpack',
                'description' => 'A durable canvas backpack',
                'sku' => 'CB-001',
                'price' => '49.99',
                'regular_price' => '49.99',
                'sale_price' => '',
                'quantity' => 30,
                'status' => 'active',
                'categories' => [],
                'images' => ['https://cdn.bigcommerce.com/backpack.jpg'],
                'variants' => [],
            ],
            [
                'external_id' => '102',
                'title' => 'Leather Wallet',
                'description' => 'Premium leather wallet',
                'sku' => 'LW-001',
                'price' => '29.99',
                'regular_price' => '29.99',
                'sale_price' => '',
                'quantity' => 50,
                'status' => 'active',
                'categories' => [],
                'images' => [],
                'variants' => [],
            ],
        ]);

        $mockService = Mockery::mock(BigCommerceService::class);
        $mockService->shouldReceive('pullProducts')
            ->with(Mockery::on(fn ($m) => $m->id === $this->marketplace->id))
            ->once()
            ->andReturn($bcProducts);

        $job = new ImportBigCommerceProductsJob($this->marketplace);
        $job->handle($mockService);

        $this->assertDatabaseHas('products', [
            'store_id' => $this->store->id,
            'title' => 'Canvas Backpack',
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('products', [
            'store_id' => $this->store->id,
            'title' => 'Leather Wallet',
        ]);

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
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        PlatformListing::create([
            'product_id' => $product->id,
            'store_marketplace_id' => $this->marketplace->id,
            'external_listing_id' => '101',
            'status' => PlatformListing::STATUS_LISTED,
        ]);

        $listingCountBefore = PlatformListing::where('store_marketplace_id', $this->marketplace->id)->count();

        $bcProducts = collect([
            [
                'external_id' => '101',
                'title' => 'Already Imported Product',
                'description' => 'Should be skipped',
                'sku' => 'AIP-001',
                'price' => '19.99',
                'regular_price' => '19.99',
                'sale_price' => '',
                'quantity' => 10,
                'status' => 'active',
                'categories' => [],
                'images' => [],
                'variants' => [],
            ],
        ]);

        $mockService = Mockery::mock(BigCommerceService::class);
        $mockService->shouldReceive('pullProducts')->andReturn($bcProducts);

        $job = new ImportBigCommerceProductsJob($this->marketplace);
        $job->handle($mockService);

        $this->assertEquals(
            $listingCountBefore,
            PlatformListing::where('store_marketplace_id', $this->marketplace->id)->count()
        );
    }

    public function test_creates_product_variants_when_provided(): void
    {
        $bcProducts = collect([
            [
                'external_id' => '201',
                'title' => 'Multi-Color T-Shirt',
                'description' => 'Available in Red, Blue, Green',
                'sku' => 'MCT-001',
                'price' => '24.99',
                'regular_price' => '24.99',
                'sale_price' => '',
                'quantity' => 90,
                'status' => 'active',
                'categories' => [],
                'images' => [],
                'variants' => [
                    ['sku' => 'MCT-001-R', 'price' => 24.99, 'quantity' => 30],
                    ['sku' => 'MCT-001-B', 'price' => 24.99, 'quantity' => 30],
                    ['sku' => 'MCT-001-G', 'price' => 24.99, 'quantity' => 30],
                ],
            ],
        ]);

        $mockService = Mockery::mock(BigCommerceService::class);
        $mockService->shouldReceive('pullProducts')->andReturn($bcProducts);

        $job = new ImportBigCommerceProductsJob($this->marketplace);
        $job->handle($mockService);

        $this->assertDatabaseHas('products', [
            'title' => 'Multi-Color T-Shirt',
            'has_variants' => true,
        ]);

        $product = Product::where('title', 'Multi-Color T-Shirt')->first();
        $this->assertEquals(3, $product->variants()->count());
        $this->assertDatabaseHas('product_variants', ['sku' => 'MCT-001-R', 'product_id' => $product->id]);
        $this->assertDatabaseHas('product_variants', ['sku' => 'MCT-001-B', 'product_id' => $product->id]);
        $this->assertDatabaseHas('product_variants', ['sku' => 'MCT-001-G', 'product_id' => $product->id]);
    }

    public function test_creates_default_variant_when_no_variants_provided(): void
    {
        $bcProducts = collect([
            [
                'external_id' => '301',
                'title' => 'Simple Widget',
                'description' => 'A simple widget',
                'sku' => 'SW-001',
                'price' => '14.99',
                'regular_price' => '14.99',
                'sale_price' => '',
                'quantity' => 20,
                'status' => 'active',
                'categories' => [],
                'images' => [],
                'variants' => [],
            ],
        ]);

        $mockService = Mockery::mock(BigCommerceService::class);
        $mockService->shouldReceive('pullProducts')->andReturn($bcProducts);

        $job = new ImportBigCommerceProductsJob($this->marketplace);
        $job->handle($mockService);

        $product = Product::where('title', 'Simple Widget')->first();
        $this->assertNotNull($product);
        $this->assertDatabaseHas('product_variants', [
            'product_id' => $product->id,
            'sku' => 'SW-001',
            'price' => '14.99',
            'quantity' => 20,
        ]);
    }

    public function test_handles_draft_products(): void
    {
        $bcProducts = collect([
            [
                'external_id' => '401',
                'title' => 'Draft Product',
                'description' => 'Not visible yet',
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

        $mockService = Mockery::mock(BigCommerceService::class);
        $mockService->shouldReceive('pullProducts')->andReturn($bcProducts);

        $job = new ImportBigCommerceProductsJob($this->marketplace);
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
