<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PlatformListing;
use App\Models\PlatformOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StoreMarketplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncShopifyProductIdsTest extends TestCase
{
    use RefreshDatabase;

    protected StoreMarketplace $marketplace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marketplace = StoreMarketplace::factory()->shopify()->create([
            'shop_domain' => 'test-shop.myshopify.com',
            'access_token' => 'test-token',
        ]);
    }

    public function test_command_matches_shopify_products_by_sku(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->marketplace->store_id,
        ]);

        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'TEST-SKU-001',
        ]);

        Http::fake([
            '*/admin/api/*/products.json*' => Http::response([
                'products' => [
                    [
                        'id' => 777888,
                        'title' => 'Shopify Product',
                        'variants' => [
                            [
                                'id' => 999111,
                                'sku' => 'TEST-SKU-001',
                                'price' => '29.99',
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $this->artisan('shopify:sync-product-ids', ['--marketplace' => $this->marketplace->id])
            ->assertExitCode(0);

        $listing = PlatformListing::where('store_marketplace_id', $this->marketplace->id)
            ->where('product_id', $product->id)
            ->first();

        $this->assertNotNull($listing);
        $this->assertEquals('777888', $listing->external_listing_id);
        $this->assertEquals('999111', $listing->external_variant_id);
        $this->assertEquals($variant->id, $listing->product_variant_id);
    }

    public function test_command_updates_existing_listing(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->marketplace->store_id,
        ]);

        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'EXISTING-SKU',
        ]);

        $listing = PlatformListing::factory()->create([
            'store_marketplace_id' => $this->marketplace->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'external_listing_id' => null,
            'external_variant_id' => null,
        ]);

        Http::fake([
            '*/admin/api/*/products.json*' => Http::response([
                'products' => [
                    [
                        'id' => 111222,
                        'title' => 'Existing Product',
                        'variants' => [
                            [
                                'id' => 333444,
                                'sku' => 'EXISTING-SKU',
                                'price' => '19.99',
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $this->artisan('shopify:sync-product-ids', ['--marketplace' => $this->marketplace->id])
            ->assertExitCode(0);

        $listing->refresh();
        $this->assertEquals('111222', $listing->external_listing_id);
        $this->assertEquals('333444', $listing->external_variant_id);
    }

    public function test_command_skips_variants_without_sku(): void
    {
        Http::fake([
            '*/admin/api/*/products.json*' => Http::response([
                'products' => [
                    [
                        'id' => 555666,
                        'title' => 'No SKU Product',
                        'variants' => [
                            [
                                'id' => 777888,
                                'sku' => '',
                                'price' => '9.99',
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $this->artisan('shopify:sync-product-ids', ['--marketplace' => $this->marketplace->id])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('platform_listings', [
            'external_listing_id' => '555666',
        ]);
    }

    public function test_command_handles_no_shopify_marketplaces(): void
    {
        // Delete all marketplaces
        StoreMarketplace::query()->delete();

        $this->artisan('shopify:sync-product-ids')
            ->assertExitCode(1);
    }

    public function test_command_dry_run_does_not_create_listings(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->marketplace->store_id,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'DRY-RUN-SKU',
        ]);

        Http::fake([
            '*/admin/api/*/products.json*' => Http::response([
                'products' => [
                    [
                        'id' => 111,
                        'title' => 'Dry Run Product',
                        'variants' => [
                            [
                                'id' => 222,
                                'sku' => 'DRY-RUN-SKU',
                                'price' => '10.00',
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $this->artisan('shopify:sync-product-ids', [
            '--marketplace' => $this->marketplace->id,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseMissing('platform_listings', [
            'external_listing_id' => '111',
        ]);
    }

    public function test_backfill_order_items_populates_external_item_id(): void
    {
        $order = Order::factory()->create([
            'store_id' => $this->marketplace->store_id,
        ]);

        $orderItem = OrderItem::factory()->create([
            'order_id' => $order->id,
            'sku' => 'BACKFILL-SKU',
            'external_item_id' => null,
        ]);

        PlatformOrder::factory()->create([
            'store_marketplace_id' => $this->marketplace->id,
            'order_id' => $order->id,
            'external_order_id' => 'shopify-order-1',
            'line_items' => [
                [
                    'external_id' => '99887766',
                    'sku' => 'BACKFILL-SKU',
                    'title' => 'Test Item',
                    'quantity' => 1,
                    'price' => 29.99,
                ],
            ],
        ]);

        Http::fake([
            '*/admin/api/*/products.json*' => Http::response(['products' => []]),
        ]);

        $this->artisan('shopify:sync-product-ids', [
            '--marketplace' => $this->marketplace->id,
            '--backfill-order-items' => true,
        ])->assertExitCode(0);

        $orderItem->refresh();
        $this->assertEquals('99887766', $orderItem->external_item_id);
    }

    public function test_backfill_matches_by_title_when_no_sku(): void
    {
        $order = Order::factory()->create([
            'store_id' => $this->marketplace->store_id,
        ]);

        $orderItem = OrderItem::factory()->create([
            'order_id' => $order->id,
            'sku' => null,
            'title' => 'Unique Product Title',
            'external_item_id' => null,
        ]);

        PlatformOrder::factory()->create([
            'store_marketplace_id' => $this->marketplace->id,
            'order_id' => $order->id,
            'external_order_id' => 'shopify-order-2',
            'line_items' => [
                [
                    'external_id' => '55443322',
                    'sku' => null,
                    'title' => 'Unique Product Title',
                    'quantity' => 1,
                    'price' => 15.00,
                ],
            ],
        ]);

        Http::fake([
            '*/admin/api/*/products.json*' => Http::response(['products' => []]),
        ]);

        $this->artisan('shopify:sync-product-ids', [
            '--marketplace' => $this->marketplace->id,
            '--backfill-order-items' => true,
        ])->assertExitCode(0);

        $orderItem->refresh();
        $this->assertEquals('55443322', $orderItem->external_item_id);
    }

    public function test_backfill_does_not_overwrite_existing_external_item_id(): void
    {
        $order = Order::factory()->create([
            'store_id' => $this->marketplace->store_id,
        ]);

        $orderItem = OrderItem::factory()->create([
            'order_id' => $order->id,
            'sku' => 'EXISTING-EXT',
            'external_item_id' => 'already-set',
        ]);

        PlatformOrder::factory()->create([
            'store_marketplace_id' => $this->marketplace->id,
            'order_id' => $order->id,
            'external_order_id' => 'shopify-order-3',
            'line_items' => [
                [
                    'external_id' => 'new-value',
                    'sku' => 'EXISTING-EXT',
                    'title' => 'Item',
                    'quantity' => 1,
                    'price' => 10.00,
                ],
            ],
        ]);

        Http::fake([
            '*/admin/api/*/products.json*' => Http::response(['products' => []]),
        ]);

        $this->artisan('shopify:sync-product-ids', [
            '--marketplace' => $this->marketplace->id,
            '--backfill-order-items' => true,
        ])->assertExitCode(0);

        $orderItem->refresh();
        $this->assertEquals('already-set', $orderItem->external_item_id);
    }

    public function test_return_sync_matches_by_external_item_id(): void
    {
        $order = Order::factory()->create([
            'store_id' => $this->marketplace->store_id,
        ]);

        $orderItem = OrderItem::factory()->create([
            'order_id' => $order->id,
            'sku' => 'RETURN-SKU',
            'external_item_id' => '12345678',
        ]);

        $returnSyncService = app(\App\Services\Returns\ReturnSyncService::class);

        $reflection = new \ReflectionMethod($returnSyncService, 'findOrderItemByExternalLineItemId');
        $reflection->setAccessible(true);

        $result = $reflection->invoke($returnSyncService, $order, '12345678', null);

        $this->assertNotNull($result);
        $this->assertEquals($orderItem->id, $result->id);
    }

    public function test_return_sync_falls_back_to_line_items_when_no_external_item_id(): void
    {
        $order = Order::factory()->create([
            'store_id' => $this->marketplace->store_id,
        ]);

        $orderItem = OrderItem::factory()->create([
            'order_id' => $order->id,
            'sku' => 'FALLBACK-SKU',
            'external_item_id' => null,
        ]);

        PlatformOrder::factory()->create([
            'store_marketplace_id' => $this->marketplace->id,
            'order_id' => $order->id,
            'external_order_id' => 'shopify-order-fallback',
            'line_items' => [
                [
                    'external_id' => 'line-item-ext-id',
                    'sku' => 'FALLBACK-SKU',
                    'title' => 'Fallback Item',
                    'quantity' => 1,
                    'price' => 25.00,
                ],
            ],
        ]);

        $returnSyncService = app(\App\Services\Returns\ReturnSyncService::class);

        $reflection = new \ReflectionMethod($returnSyncService, 'findOrderItemByExternalLineItemId');
        $reflection->setAccessible(true);

        $result = $reflection->invoke($returnSyncService, $order, 'line-item-ext-id', 'FALLBACK-SKU');

        $this->assertNotNull($result);
        $this->assertEquals($orderItem->id, $result->id);
    }

    public function test_order_import_stores_external_item_id(): void
    {
        $marketplace = $this->marketplace;

        $product = Product::factory()->create([
            'store_id' => $marketplace->store_id,
        ]);

        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'IMPORT-SKU',
        ]);

        $platformOrder = PlatformOrder::factory()->create([
            'store_marketplace_id' => $marketplace->id,
            'order_id' => null,
            'external_order_id' => 'import-test',
            'status' => 'confirmed',
            'fulfillment_status' => 'unfulfilled',
            'payment_status' => 'paid',
            'total' => 50.00,
            'subtotal' => 50.00,
            'line_items' => [
                [
                    'external_id' => '11223344',
                    'sku' => 'IMPORT-SKU',
                    'title' => 'Import Test Item',
                    'quantity' => 1,
                    'price' => 50.00,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $importService = app(\App\Services\Webhooks\OrderImportService::class);

        \Illuminate\Support\Facades\Queue::fake();

        $order = $importService->importFromPlatformOrder($platformOrder);

        $item = $order->items->first();
        $this->assertNotNull($item);
        $this->assertEquals('11223344', $item->external_item_id);
    }
}
