<?php

namespace Tests\Feature\Shopify;

use App\Enums\Platform;
use App\Jobs\ImportShopifyProductsJob;
use App\Jobs\ProcessWebhookJob;
use App\Models\PlatformListing;
use App\Models\PlatformListingVariant;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Models\WebhookLog;
use App\Services\Platforms\Shopify\ShopifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyProductSyncTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected StoreMarketplace $marketplace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->marketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $this->store->id,
            'shop_domain' => 'test-store.myshopify.com',
            'access_token' => 'shpat_test_token',
            'status' => 'active',
        ]);
    }

    public function test_pull_products_paginates_through_all_pages(): void
    {
        Http::fake([
            'test-store.myshopify.com/admin/api/*/products.json?limit=250' => Http::response(
                ['products' => [
                    [
                        'id' => 1,
                        'title' => 'Product A',
                        'body_html' => 'Desc A',
                        'handle' => 'product-a',
                        'vendor' => 'Vendor',
                        'product_type' => 'Type',
                        'variants' => [['id' => 10, 'sku' => 'SKU-A', 'price' => '10.00', 'inventory_quantity' => 5, 'barcode' => null, 'inventory_item_id' => 100]],
                        'images' => [],
                    ],
                ]],
                200,
                ['Link' => '<https://test-store.myshopify.com/admin/api/2024-01/products.json?page_info=cursor_page2&limit=250>; rel="next"']
            ),
            'test-store.myshopify.com/admin/api/*/products.json?limit=250&page_info=cursor_page2' => Http::response(
                ['products' => [
                    [
                        'id' => 2,
                        'title' => 'Product B',
                        'body_html' => 'Desc B',
                        'handle' => 'product-b',
                        'vendor' => 'Vendor',
                        'product_type' => 'Type',
                        'variants' => [['id' => 20, 'sku' => 'SKU-B', 'price' => '20.00', 'inventory_quantity' => 3, 'barcode' => null, 'inventory_item_id' => 200]],
                        'images' => [],
                    ],
                ]],
                200,
                // No Link header — last page
            ),
        ]);

        $service = app(ShopifyService::class);
        $products = $service->pullProducts($this->marketplace);

        $this->assertCount(2, $products);
        $this->assertEquals('Product A', $products[0]['title']);
        $this->assertEquals('Product B', $products[1]['title']);
    }

    public function test_map_shopify_product_includes_inventory_item_id(): void
    {
        Http::fake([
            'test-store.myshopify.com/admin/api/*/products.json*' => Http::response([
                'products' => [[
                    'id' => 1,
                    'title' => 'Test Product',
                    'body_html' => 'Desc',
                    'handle' => 'test-product',
                    'vendor' => 'Vendor',
                    'product_type' => 'Type',
                    'variants' => [[
                        'id' => 10,
                        'sku' => 'SKU-1',
                        'price' => '29.99',
                        'inventory_quantity' => 5,
                        'barcode' => '123',
                        'inventory_item_id' => 999,
                    ]],
                    'images' => [],
                ]],
            ]),
        ]);

        $products = app(ShopifyService::class)->pullProducts($this->marketplace);

        $this->assertCount(1, $products);
        $this->assertEquals(999, $products[0]['variants'][0]['inventory_item_id']);
    }

    public function test_import_job_creates_platform_listing_variants(): void
    {
        Http::fake([
            'test-store.myshopify.com/admin/api/*/products.json*' => Http::response([
                'products' => [[
                    'id' => 12345,
                    'title' => 'Diamond Ring',
                    'body_html' => '<p>Beautiful</p>',
                    'handle' => 'diamond-ring',
                    'vendor' => 'JewelryCo',
                    'product_type' => 'Ring',
                    'variants' => [
                        [
                            'id' => 111,
                            'sku' => 'DR-001',
                            'price' => '1299.99',
                            'inventory_quantity' => 5,
                            'barcode' => '1234567890',
                            'inventory_item_id' => 555,
                        ],
                        [
                            'id' => 112,
                            'sku' => 'DR-002',
                            'price' => '1499.99',
                            'inventory_quantity' => 3,
                            'barcode' => '1234567891',
                            'inventory_item_id' => 556,
                        ],
                    ],
                    'images' => [],
                ]],
            ]),
        ]);

        $job = new ImportShopifyProductsJob($this->marketplace);
        $job->handle(app(ShopifyService::class));

        $product = Product::where('store_id', $this->store->id)->where('title', 'Diamond Ring')->first();
        $this->assertNotNull($product);
        $this->assertTrue($product->has_variants);

        $listing = PlatformListing::where('store_marketplace_id', $this->marketplace->id)
            ->where('external_listing_id', '12345')
            ->first();
        $this->assertNotNull($listing);

        $listingVariants = PlatformListingVariant::where('platform_listing_id', $listing->id)->get();
        $this->assertCount(2, $listingVariants);

        $variant1 = $listingVariants->where('external_variant_id', '111')->first();
        $this->assertNotNull($variant1);
        $this->assertEquals('555', $variant1->external_inventory_item_id);
        $this->assertEquals('DR-001', $variant1->sku);

        $variant2 = $listingVariants->where('external_variant_id', '112')->first();
        $this->assertNotNull($variant2);
        $this->assertEquals('556', $variant2->external_inventory_item_id);
        $this->assertEquals('DR-002', $variant2->sku);
    }

    public function test_product_webhook_updates_product_and_variants(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Old Title',
            'description' => 'Old description',
            'is_published' => true,
        ]);

        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'SKU-1',
            'price' => '10.00',
            'quantity' => 5,
        ]);

        $listing = PlatformListing::create([
            'store_marketplace_id' => $this->marketplace->id,
            'product_id' => $product->id,
            'external_listing_id' => '12345',
            'status' => PlatformListing::STATUS_LISTED,
            'last_synced_at' => now(),
        ]);

        PlatformListingVariant::create([
            'platform_listing_id' => $listing->id,
            'product_variant_id' => $variant->id,
            'external_variant_id' => '111',
            'external_inventory_item_id' => '555',
            'sku' => 'SKU-1',
        ]);

        $webhookPayload = [
            'id' => 12345,
            'title' => 'Updated Title',
            'body_html' => '<p>Updated description</p>',
            'status' => 'active',
            'variants' => [
                [
                    'id' => 111,
                    'sku' => 'SKU-1-UPDATED',
                    'price' => '19.99',
                    'inventory_quantity' => 12,
                    'barcode' => 'NEW-BARCODE',
                ],
            ],
        ];

        $webhookLog = WebhookLog::create([
            'store_marketplace_id' => $this->marketplace->id,
            'store_id' => $this->store->id,
            'platform' => Platform::Shopify,
            'event_type' => 'products/update',
            'status' => WebhookLog::STATUS_PENDING,
            'payload' => $webhookPayload,
        ]);

        $job = new ProcessWebhookJob($webhookLog);
        $job->handle(app(\App\Services\Webhooks\OrderImportService::class), app(\App\Services\Returns\ReturnSyncService::class));

        $product->refresh();
        $this->assertEquals('Updated Title', $product->title);
        $this->assertEquals('<p>Updated description</p>', $product->description);
        $this->assertTrue($product->is_published);

        $variant->refresh();
        $this->assertEquals('19.99', $variant->price);
        $this->assertEquals(12, $variant->quantity);
        $this->assertEquals('SKU-1-UPDATED', $variant->sku);
        $this->assertEquals('NEW-BARCODE', $variant->barcode);
    }

    public function test_product_webhook_sets_unpublished_for_draft_status(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'is_published' => true,
        ]);

        $listing = PlatformListing::create([
            'store_marketplace_id' => $this->marketplace->id,
            'product_id' => $product->id,
            'external_listing_id' => '12345',
            'status' => PlatformListing::STATUS_LISTED,
            'last_synced_at' => now(),
        ]);

        $webhookPayload = [
            'id' => 12345,
            'title' => $product->title,
            'body_html' => $product->description,
            'status' => 'draft',
            'variants' => [],
        ];

        $webhookLog = WebhookLog::create([
            'store_marketplace_id' => $this->marketplace->id,
            'store_id' => $this->store->id,
            'platform' => Platform::Shopify,
            'event_type' => 'products/update',
            'status' => WebhookLog::STATUS_PENDING,
            'payload' => $webhookPayload,
        ]);

        $job = new ProcessWebhookJob($webhookLog);
        $job->handle(app(\App\Services\Webhooks\OrderImportService::class), app(\App\Services\Returns\ReturnSyncService::class));

        $product->refresh();
        $this->assertFalse($product->is_published);
    }

    public function test_inventory_webhook_updates_variant_quantity(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'quantity' => 5,
        ]);

        $listing = PlatformListing::create([
            'store_marketplace_id' => $this->marketplace->id,
            'product_id' => $product->id,
            'external_listing_id' => '12345',
            'status' => PlatformListing::STATUS_LISTED,
            'last_synced_at' => now(),
        ]);

        PlatformListingVariant::create([
            'platform_listing_id' => $listing->id,
            'product_variant_id' => $variant->id,
            'external_variant_id' => '111',
            'external_inventory_item_id' => '555',
        ]);

        $webhookPayload = [
            'inventory_item_id' => 555,
            'location_id' => 999,
            'available' => 25,
        ];

        $webhookLog = WebhookLog::create([
            'store_marketplace_id' => $this->marketplace->id,
            'store_id' => $this->store->id,
            'platform' => Platform::Shopify,
            'event_type' => 'inventory_levels/update',
            'status' => WebhookLog::STATUS_PENDING,
            'payload' => $webhookPayload,
        ]);

        $job = new ProcessWebhookJob($webhookLog);
        $job->handle(app(\App\Services\Webhooks\OrderImportService::class), app(\App\Services\Returns\ReturnSyncService::class));

        $variant->refresh();
        $this->assertEquals(25, $variant->quantity);
    }

    public function test_inventory_webhook_ignores_unknown_inventory_item(): void
    {
        $webhookPayload = [
            'inventory_item_id' => 999999,
            'location_id' => 999,
            'available' => 25,
        ];

        $webhookLog = WebhookLog::create([
            'store_marketplace_id' => $this->marketplace->id,
            'store_id' => $this->store->id,
            'platform' => Platform::Shopify,
            'event_type' => 'inventory_levels/update',
            'status' => WebhookLog::STATUS_PENDING,
            'payload' => $webhookPayload,
        ]);

        $job = new ProcessWebhookJob($webhookLog);
        $job->handle(app(\App\Services\Webhooks\OrderImportService::class), app(\App\Services\Returns\ReturnSyncService::class));

        // Should complete without errors
        $webhookLog->refresh();
        $this->assertEquals(WebhookLog::STATUS_COMPLETED, $webhookLog->status);
    }

    public function test_product_webhook_ignores_unknown_listing(): void
    {
        $webhookPayload = [
            'id' => 999999,
            'title' => 'Unknown Product',
            'body_html' => 'Desc',
            'status' => 'active',
            'variants' => [],
        ];

        $webhookLog = WebhookLog::create([
            'store_marketplace_id' => $this->marketplace->id,
            'store_id' => $this->store->id,
            'platform' => Platform::Shopify,
            'event_type' => 'products/update',
            'status' => WebhookLog::STATUS_PENDING,
            'payload' => $webhookPayload,
        ]);

        $job = new ProcessWebhookJob($webhookLog);
        $job->handle(app(\App\Services\Webhooks\OrderImportService::class), app(\App\Services\Returns\ReturnSyncService::class));

        // Should complete without errors
        $webhookLog->refresh();
        $this->assertEquals(WebhookLog::STATUS_COMPLETED, $webhookLog->status);
    }

    public function test_declarative_webhook_queues_product_update(): void
    {
        config(['services.shopify.webhook_secret' => 'test-secret']);

        $this->marketplace->update([
            'credentials' => ['webhook_secret' => 'test-secret'],
        ]);

        $payload = json_encode([
            'id' => 12345,
            'title' => 'Updated Product',
            'body_html' => 'Updated desc',
            'status' => 'active',
            'variants' => [],
        ]);

        $hmac = base64_encode(hash_hmac('sha256', $payload, 'test-secret', true));

        $response = $this->postJson(
            '/api/webhooks/shopify/app',
            json_decode($payload, true),
            [
                'X-Shopify-Topic' => 'products/update',
                'X-Shopify-Shop-Domain' => 'test-store.myshopify.com',
                'X-Shopify-Hmac-Sha256' => $hmac,
            ]
        );

        $response->assertOk();
        $response->assertJson(['status' => 'queued']);

        $this->assertDatabaseHas('webhook_logs', [
            'store_marketplace_id' => $this->marketplace->id,
            'event_type' => 'products/update',
        ]);
    }

    public function test_declarative_webhook_queues_inventory_update(): void
    {
        config(['services.shopify.webhook_secret' => 'test-secret']);

        $this->marketplace->update([
            'credentials' => ['webhook_secret' => 'test-secret'],
        ]);

        $payload = json_encode([
            'inventory_item_id' => 555,
            'location_id' => 999,
            'available' => 10,
        ]);

        $hmac = base64_encode(hash_hmac('sha256', $payload, 'test-secret', true));

        $response = $this->postJson(
            '/api/webhooks/shopify/app',
            json_decode($payload, true),
            [
                'X-Shopify-Topic' => 'inventory_levels/update',
                'X-Shopify-Shop-Domain' => 'test-store.myshopify.com',
                'X-Shopify-Hmac-Sha256' => $hmac,
            ]
        );

        $response->assertOk();
        $response->assertJson(['status' => 'queued']);

        $this->assertDatabaseHas('webhook_logs', [
            'store_marketplace_id' => $this->marketplace->id,
            'event_type' => 'inventory_levels/update',
        ]);
    }

    public function test_parse_link_header_extracts_cursor(): void
    {
        $service = app(ShopifyService::class);
        $method = new \ReflectionMethod($service, 'parseLinkHeader');

        $linkHeader = '<https://store.myshopify.com/admin/api/2024-01/products.json?page_info=abc123&limit=250>; rel="next"';
        $this->assertEquals('abc123', $method->invoke($service, $linkHeader));

        $linkHeader = '<https://store.myshopify.com/admin/api/2024-01/products.json?page_info=prev123&limit=250>; rel="previous", <https://store.myshopify.com/admin/api/2024-01/products.json?page_info=next456&limit=250>; rel="next"';
        $this->assertEquals('next456', $method->invoke($service, $linkHeader));

        $this->assertNull($method->invoke($service, null));
        $this->assertNull($method->invoke($service, ''));

        $linkHeader = '<https://store.myshopify.com/admin/api/2024-01/products.json?page_info=prev123&limit=250>; rel="previous"';
        $this->assertNull($method->invoke($service, $linkHeader));
    }
}
