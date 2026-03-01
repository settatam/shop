<?php

namespace Tests\Feature\BigCommerce;

use App\Enums\Platform;
use App\Jobs\ImportBigCommerceProductsJob;
use App\Jobs\ProcessWebhookJob;
use App\Models\PlatformListing;
use App\Models\PlatformListingVariant;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Models\WebhookLog;
use App\Services\Platforms\BigCommerce\BigCommerceService;
use App\Services\Returns\ReturnSyncService;
use App\Services\Webhooks\OrderImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BigCommerceProductSyncTest extends TestCase
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

    public function test_product_webhook_fetches_from_api_and_updates_product(): void
    {
        Http::fake([
            'https://api.bigcommerce.com/stores/abc123/v3/catalog/products/300*' => Http::response([
                'data' => [
                    'id' => 300,
                    'name' => 'Updated BC Product',
                    'description' => 'Updated description',
                    'is_visible' => true,
                    'variants' => [
                        [
                            'id' => 400,
                            'sku' => 'BC-UPDATED',
                            'price' => 59.99,
                            'inventory_level' => 25,
                        ],
                    ],
                ],
            ]),
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Old Title',
            'is_published' => false,
        ]);

        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'BC-SKU',
            'price' => '20.00',
            'quantity' => 5,
        ]);

        $listing = PlatformListing::create([
            'store_marketplace_id' => $this->marketplace->id,
            'product_id' => $product->id,
            'external_listing_id' => '300',
            'status' => PlatformListing::STATUS_LISTED,
            'last_synced_at' => now(),
        ]);

        PlatformListingVariant::create([
            'platform_listing_id' => $listing->id,
            'product_variant_id' => $variant->id,
            'external_variant_id' => '400',
            'sku' => 'BC-SKU',
        ]);

        $webhookLog = WebhookLog::create([
            'store_marketplace_id' => $this->marketplace->id,
            'store_id' => $this->store->id,
            'platform' => Platform::BigCommerce,
            'event_type' => 'store/product/updated',
            'status' => WebhookLog::STATUS_PENDING,
            'payload' => ['scope' => 'store/product/updated', 'data' => ['id' => 300]],
        ]);

        $job = new ProcessWebhookJob($webhookLog);
        $job->handle(app(OrderImportService::class), app(ReturnSyncService::class));

        $product->refresh();
        $this->assertEquals('Updated BC Product', $product->title);
        $this->assertEquals('Updated description', $product->description);
        $this->assertTrue($product->is_published);

        $variant->refresh();
        $this->assertEquals('59.99', $variant->price);
        $this->assertEquals(25, $variant->quantity);
        $this->assertEquals('BC-UPDATED', $variant->sku);
    }

    public function test_import_job_creates_platform_listing_variants(): void
    {
        Http::fake([
            'https://api.bigcommerce.com/stores/abc123/v3/catalog/products*' => Http::response([
                'data' => [
                    [
                        'id' => 600,
                        'name' => 'BC Imported Product',
                        'description' => 'Description',
                        'sku' => 'BC-IMP-001',
                        'price' => 49.99,
                        'inventory_level' => 10,
                        'is_visible' => true,
                        'images' => [],
                        'variants' => [
                            ['id' => 601, 'sku' => 'BC-IMP-S', 'price' => 49.99, 'inventory_level' => 5],
                            ['id' => 602, 'sku' => 'BC-IMP-M', 'price' => 54.99, 'inventory_level' => 5],
                        ],
                    ],
                ],
            ]),
        ]);

        $job = new ImportBigCommerceProductsJob($this->marketplace);
        $job->handle(app(BigCommerceService::class));

        $product = Product::where('store_id', $this->store->id)
            ->where('title', 'BC Imported Product')
            ->first();
        $this->assertNotNull($product);

        $listing = PlatformListing::where('store_marketplace_id', $this->marketplace->id)
            ->where('external_listing_id', '600')
            ->first();
        $this->assertNotNull($listing);

        $listingVariants = PlatformListingVariant::where('platform_listing_id', $listing->id)->get();
        $this->assertCount(2, $listingVariants);

        $v1 = $listingVariants->where('external_variant_id', '601')->first();
        $this->assertNotNull($v1);
        $this->assertEquals('BC-IMP-S', $v1->sku);

        $v2 = $listingVariants->where('external_variant_id', '602')->first();
        $this->assertNotNull($v2);
        $this->assertEquals('BC-IMP-M', $v2->sku);
    }

    public function test_product_webhook_ignores_unknown_listing(): void
    {
        Http::fake();

        $webhookLog = WebhookLog::create([
            'store_marketplace_id' => $this->marketplace->id,
            'store_id' => $this->store->id,
            'platform' => Platform::BigCommerce,
            'event_type' => 'store/product/updated',
            'status' => WebhookLog::STATUS_PENDING,
            'payload' => ['scope' => 'store/product/updated', 'data' => ['id' => 99999]],
        ]);

        $job = new ProcessWebhookJob($webhookLog);
        $job->handle(app(OrderImportService::class), app(ReturnSyncService::class));

        $webhookLog->refresh();
        $this->assertEquals(WebhookLog::STATUS_COMPLETED, $webhookLog->status);
    }

    public function test_product_deleted_webhook_sets_listing_status(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id]);

        PlatformListing::create([
            'store_marketplace_id' => $this->marketplace->id,
            'product_id' => $product->id,
            'external_listing_id' => '300',
            'status' => PlatformListing::STATUS_LISTED,
            'last_synced_at' => now(),
        ]);

        $webhookLog = WebhookLog::create([
            'store_marketplace_id' => $this->marketplace->id,
            'store_id' => $this->store->id,
            'platform' => Platform::BigCommerce,
            'event_type' => 'store/product/deleted',
            'status' => WebhookLog::STATUS_PENDING,
            'payload' => ['scope' => 'store/product/deleted', 'data' => ['id' => 300]],
        ]);

        $job = new ProcessWebhookJob($webhookLog);
        $job->handle(app(OrderImportService::class), app(ReturnSyncService::class));

        $this->assertDatabaseHas('platform_listings', [
            'external_listing_id' => '300',
            'status' => 'deleted',
        ]);
    }
}
