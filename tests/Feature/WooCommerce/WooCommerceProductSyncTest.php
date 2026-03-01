<?php

namespace Tests\Feature\WooCommerce;

use App\Enums\Platform;
use App\Jobs\ImportWooCommerceProductsJob;
use App\Jobs\ProcessWebhookJob;
use App\Models\PlatformListing;
use App\Models\PlatformListingVariant;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Models\WebhookLog;
use App\Services\Platforms\WooCommerce\WooCommerceService;
use App\Services\Returns\ReturnSyncService;
use App\Services\Webhooks\OrderImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WooCommerceProductSyncTest extends TestCase
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
            'shop_domain' => 'test-store.example.com',
            'credentials' => [
                'site_url' => 'https://test-store.example.com',
                'consumer_key' => 'ck_test',
                'consumer_secret' => encrypt('cs_test'),
            ],
            'status' => 'active',
        ]);
    }

    public function test_product_webhook_updates_product_and_variants(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Old Title',
            'description' => 'Old description',
            'is_published' => false,
        ]);

        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'WOO-SKU-1',
            'price' => '10.00',
            'quantity' => 5,
        ]);

        $listing = PlatformListing::create([
            'store_marketplace_id' => $this->marketplace->id,
            'product_id' => $product->id,
            'external_listing_id' => '100',
            'status' => PlatformListing::STATUS_LISTED,
            'last_synced_at' => now(),
        ]);

        PlatformListingVariant::create([
            'platform_listing_id' => $listing->id,
            'product_variant_id' => $variant->id,
            'external_variant_id' => '200',
            'sku' => 'WOO-SKU-1',
        ]);

        $webhookPayload = [
            'id' => 100,
            'name' => 'Updated WooCommerce Product',
            'description' => '<p>Updated desc</p>',
            'status' => 'publish',
            'price' => '29.99',
            'regular_price' => '29.99',
            'stock_quantity' => 15,
            'sku' => 'WOO-SKU-1',
            'variations' => [
                [
                    'id' => 200,
                    'price' => '29.99',
                    'regular_price' => '29.99',
                    'stock_quantity' => 15,
                    'sku' => 'WOO-SKU-UPDATED',
                ],
            ],
        ];

        $webhookLog = WebhookLog::create([
            'store_marketplace_id' => $this->marketplace->id,
            'store_id' => $this->store->id,
            'platform' => Platform::WooCommerce,
            'event_type' => 'product.updated',
            'status' => WebhookLog::STATUS_PENDING,
            'payload' => $webhookPayload,
        ]);

        $job = new ProcessWebhookJob($webhookLog);
        $job->handle(app(OrderImportService::class), app(ReturnSyncService::class));

        $product->refresh();
        $this->assertEquals('Updated WooCommerce Product', $product->title);
        $this->assertEquals('<p>Updated desc</p>', $product->description);
        $this->assertTrue($product->is_published);

        $variant->refresh();
        $this->assertEquals('29.99', $variant->price);
        $this->assertEquals(15, $variant->quantity);
        $this->assertEquals('WOO-SKU-UPDATED', $variant->sku);
    }

    public function test_product_webhook_syncs_simple_product_without_variations(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Simple Product',
            'is_published' => false,
        ]);

        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => '10.00',
            'quantity' => 3,
        ]);

        $listing = PlatformListing::create([
            'store_marketplace_id' => $this->marketplace->id,
            'product_id' => $product->id,
            'external_listing_id' => '101',
            'status' => PlatformListing::STATUS_LISTED,
            'last_synced_at' => now(),
        ]);

        PlatformListingVariant::create([
            'platform_listing_id' => $listing->id,
            'product_variant_id' => $variant->id,
            'external_variant_id' => '101',
        ]);

        $webhookPayload = [
            'id' => 101,
            'name' => 'Updated Simple',
            'description' => 'New desc',
            'status' => 'publish',
            'price' => '49.99',
            'regular_price' => '49.99',
            'stock_quantity' => 20,
            'sku' => 'SIMPLE-1',
            'variations' => [],
        ];

        $webhookLog = WebhookLog::create([
            'store_marketplace_id' => $this->marketplace->id,
            'store_id' => $this->store->id,
            'platform' => Platform::WooCommerce,
            'event_type' => 'product.updated',
            'status' => WebhookLog::STATUS_PENDING,
            'payload' => $webhookPayload,
        ]);

        $job = new ProcessWebhookJob($webhookLog);
        $job->handle(app(OrderImportService::class), app(ReturnSyncService::class));

        $variant->refresh();
        $this->assertEquals('49.99', $variant->price);
        $this->assertEquals(20, $variant->quantity);
    }

    public function test_import_job_creates_platform_listing_variants(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'variations/501')) {
                return Http::response([
                    'id' => 501,
                    'sku' => 'IMP-001-S',
                    'price' => '39.99',
                    'regular_price' => '39.99',
                    'stock_quantity' => 5,
                ]);
            }

            if (str_contains($url, 'variations/502')) {
                return Http::response([
                    'id' => 502,
                    'sku' => 'IMP-001-M',
                    'price' => '44.99',
                    'regular_price' => '44.99',
                    'stock_quantity' => 5,
                ]);
            }

            // Products list
            if (str_contains($url, '/products') && $request->method() === 'GET') {
                return Http::response([
                    [
                        'id' => 500,
                        'name' => 'Imported Product',
                        'description' => 'Product description',
                        'short_description' => 'Short desc',
                        'sku' => 'IMP-001',
                        'price' => '39.99',
                        'regular_price' => '39.99',
                        'sale_price' => '',
                        'stock_quantity' => 10,
                        'status' => 'publish',
                        'permalink' => 'https://test-store.example.com/product/imported-product',
                        'categories' => [],
                        'images' => [],
                        'variations' => [501, 502],
                    ],
                ]);
            }

            return Http::response([]);
        });

        $job = new ImportWooCommerceProductsJob($this->marketplace);
        $job->handle(app(WooCommerceService::class));

        $product = Product::where('store_id', $this->store->id)
            ->where('title', 'Imported Product')
            ->first();
        $this->assertNotNull($product);

        $listing = PlatformListing::where('store_marketplace_id', $this->marketplace->id)
            ->where('external_listing_id', '500')
            ->first();
        $this->assertNotNull($listing);
        $this->assertEquals('https://test-store.example.com/product/imported-product', $listing->listing_url);

        $listingVariants = PlatformListingVariant::where('platform_listing_id', $listing->id)->get();
        $this->assertCount(2, $listingVariants);

        $v1 = $listingVariants->where('external_variant_id', '501')->first();
        $this->assertNotNull($v1);
        $this->assertEquals('IMP-001-S', $v1->sku);

        $v2 = $listingVariants->where('external_variant_id', '502')->first();
        $this->assertNotNull($v2);
        $this->assertEquals('IMP-001-M', $v2->sku);
    }

    public function test_product_deleted_webhook_sets_listing_status(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id]);

        PlatformListing::create([
            'store_marketplace_id' => $this->marketplace->id,
            'product_id' => $product->id,
            'external_listing_id' => '100',
            'status' => PlatformListing::STATUS_LISTED,
            'last_synced_at' => now(),
        ]);

        $webhookPayload = ['id' => 100];

        $webhookLog = WebhookLog::create([
            'store_marketplace_id' => $this->marketplace->id,
            'store_id' => $this->store->id,
            'platform' => Platform::WooCommerce,
            'event_type' => 'product.deleted',
            'status' => WebhookLog::STATUS_PENDING,
            'payload' => $webhookPayload,
        ]);

        $job = new ProcessWebhookJob($webhookLog);
        $job->handle(app(OrderImportService::class), app(ReturnSyncService::class));

        $this->assertDatabaseHas('platform_listings', [
            'store_marketplace_id' => $this->marketplace->id,
            'external_listing_id' => '100',
            'status' => 'deleted',
        ]);
    }

    public function test_product_webhook_ignores_unknown_listing(): void
    {
        $webhookPayload = [
            'id' => 99999,
            'name' => 'Unknown',
            'description' => '',
            'status' => 'publish',
            'variations' => [],
        ];

        $webhookLog = WebhookLog::create([
            'store_marketplace_id' => $this->marketplace->id,
            'store_id' => $this->store->id,
            'platform' => Platform::WooCommerce,
            'event_type' => 'product.updated',
            'status' => WebhookLog::STATUS_PENDING,
            'payload' => $webhookPayload,
        ]);

        $job = new ProcessWebhookJob($webhookLog);
        $job->handle(app(OrderImportService::class), app(ReturnSyncService::class));

        $webhookLog->refresh();
        $this->assertEquals(WebhookLog::STATUS_COMPLETED, $webhookLog->status);
    }
}
