<?php

namespace Tests\Feature\Ebay;

use App\Enums\Platform;
use App\Jobs\ProcessWebhookJob;
use App\Models\PlatformListing;
use App\Models\PlatformListingVariant;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Models\WebhookLog;
use App\Services\Returns\ReturnSyncService;
use App\Services\Webhooks\OrderImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EbayProductSyncTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected StoreMarketplace $marketplace;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ebay.sandbox' => true]);

        $this->store = Store::factory()->create();
        $this->marketplace = StoreMarketplace::factory()->ebay()->create([
            'store_id' => $this->store->id,
            'access_token' => 'ebay_test_token',
            'token_expires_at' => now()->addHours(2),
            'settings' => ['marketplace_id' => 'EBAY_US'],
            'status' => 'active',
        ]);
    }

    public function test_item_revised_webhook_updates_listing_and_product(): void
    {
        Http::fake([
            'api.sandbox.ebay.com/sell/inventory/v1/inventory_item/EBAY-SKU-1' => Http::response([
                'sku' => 'EBAY-SKU-1',
                'product' => [
                    'title' => 'Revised eBay Product',
                    'description' => 'Revised description',
                    'imageUrls' => [],
                ],
                'availability' => [
                    'shipToLocationAvailability' => [
                        'quantity' => 8,
                    ],
                ],
            ]),
            'api.sandbox.ebay.com/sell/inventory/v1/offer/OFFER-123' => Http::response([
                'offerId' => 'OFFER-123',
                'pricingSummary' => [
                    'price' => ['value' => '149.99', 'currency' => 'USD'],
                ],
            ]),
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Old eBay Product',
            'description' => 'Old description',
        ]);

        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'EBAY-SKU-1',
            'price' => '99.99',
            'quantity' => 3,
        ]);

        $listing = PlatformListing::create([
            'store_marketplace_id' => $this->marketplace->id,
            'product_id' => $product->id,
            'external_listing_id' => '110123456789',
            'status' => PlatformListing::STATUS_LISTED,
            'platform_data' => [
                'sku' => 'EBAY-SKU-1',
                'offer_id' => 'OFFER-123',
            ],
            'last_synced_at' => now()->subHour(),
        ]);

        PlatformListingVariant::create([
            'platform_listing_id' => $listing->id,
            'product_variant_id' => $variant->id,
            'external_variant_id' => 'EBAY-SKU-1',
            'sku' => 'EBAY-SKU-1',
        ]);

        $webhookPayload = [
            'metadata' => ['topic' => 'ITEM_REVISED'],
            'resource' => ['listingId' => '110123456789'],
        ];

        $webhookLog = WebhookLog::create([
            'store_marketplace_id' => $this->marketplace->id,
            'store_id' => $this->store->id,
            'platform' => Platform::Ebay,
            'event_type' => 'ITEM_REVISED',
            'status' => WebhookLog::STATUS_PENDING,
            'payload' => $webhookPayload,
        ]);

        $job = new ProcessWebhookJob($webhookLog);
        $job->handle(app(OrderImportService::class), app(ReturnSyncService::class));

        $product->refresh();
        $this->assertEquals('Revised eBay Product', $product->title);
        $this->assertEquals('Revised description', $product->description);

        $variant->refresh();
        $this->assertEquals('149.99', $variant->price);
        $this->assertEquals(8, $variant->quantity);

        $listing->refresh();
        $this->assertNotNull($listing->last_synced_at);
    }

    public function test_item_revised_webhook_ignores_unknown_listing(): void
    {
        Http::fake();

        $webhookPayload = [
            'metadata' => ['topic' => 'ITEM_REVISED'],
            'resource' => ['listingId' => '999999999999'],
        ];

        $webhookLog = WebhookLog::create([
            'store_marketplace_id' => $this->marketplace->id,
            'store_id' => $this->store->id,
            'platform' => Platform::Ebay,
            'event_type' => 'ITEM_REVISED',
            'status' => WebhookLog::STATUS_PENDING,
            'payload' => $webhookPayload,
        ]);

        $job = new ProcessWebhookJob($webhookLog);
        $job->handle(app(OrderImportService::class), app(ReturnSyncService::class));

        $webhookLog->refresh();
        $this->assertEquals(WebhookLog::STATUS_COMPLETED, $webhookLog->status);
    }

    public function test_item_revised_updates_timestamp_when_no_sku(): void
    {
        Http::fake();

        $product = Product::factory()->create(['store_id' => $this->store->id]);

        $listing = PlatformListing::create([
            'store_marketplace_id' => $this->marketplace->id,
            'product_id' => $product->id,
            'external_listing_id' => '110111222333',
            'status' => PlatformListing::STATUS_LISTED,
            'platform_data' => [],
            'last_synced_at' => now()->subDay(),
        ]);

        $webhookPayload = [
            'metadata' => ['topic' => 'ITEM_REVISED'],
            'resource' => ['listingId' => '110111222333'],
        ];

        $webhookLog = WebhookLog::create([
            'store_marketplace_id' => $this->marketplace->id,
            'store_id' => $this->store->id,
            'platform' => Platform::Ebay,
            'event_type' => 'ITEM_REVISED',
            'status' => WebhookLog::STATUS_PENDING,
            'payload' => $webhookPayload,
        ]);

        $job = new ProcessWebhookJob($webhookLog);
        $job->handle(app(OrderImportService::class), app(ReturnSyncService::class));

        $listing->refresh();
        $this->assertTrue($listing->last_synced_at->isToday());
    }
}
