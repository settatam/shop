<?php

namespace Tests\Feature\Amazon;

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
use Tests\TestCase;

class AmazonProductSyncTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected StoreMarketplace $marketplace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->marketplace = StoreMarketplace::factory()->amazon()->create([
            'store_id' => $this->store->id,
            'credentials' => [
                'region' => 'na',
                'seller_id' => 'TEST_SELLER',
            ],
            'status' => 'active',
        ]);
    }

    public function test_any_offer_changed_updates_product_price(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => '25.00',
        ]);

        $listing = PlatformListing::create([
            'store_marketplace_id' => $this->marketplace->id,
            'product_id' => $product->id,
            'external_listing_id' => 'B00TEST1234',
            'status' => PlatformListing::STATUS_LISTED,
            'platform_data' => ['asin' => 'B00TEST1234'],
            'last_synced_at' => now()->subHour(),
        ]);

        PlatformListingVariant::create([
            'platform_listing_id' => $listing->id,
            'product_variant_id' => $variant->id,
            'external_variant_id' => 'AMZ-SKU-1',
        ]);

        $webhookPayload = [
            'NotificationType' => 'ANY_OFFER_CHANGED',
            'Payload' => [
                'OfferChangeTrigger' => [
                    'ASIN' => 'B00TEST1234',
                    'ItemCondition' => 'New',
                    'MarketplaceId' => 'ATVPDKIKX0DER',
                ],
                'Offers' => [
                    [
                        'SellerId' => 'TEST_SELLER',
                        'ListingPrice' => ['Amount' => '34.99', 'CurrencyCode' => 'USD'],
                        'ShippingPrice' => ['Amount' => '0.00', 'CurrencyCode' => 'USD'],
                    ],
                ],
            ],
        ];

        $webhookLog = WebhookLog::create([
            'store_marketplace_id' => $this->marketplace->id,
            'store_id' => $this->store->id,
            'platform' => Platform::Amazon,
            'event_type' => 'ANY_OFFER_CHANGED',
            'status' => WebhookLog::STATUS_PENDING,
            'payload' => $webhookPayload,
        ]);

        $job = new ProcessWebhookJob($webhookLog);
        $job->handle(app(OrderImportService::class), app(ReturnSyncService::class));

        $variant->refresh();
        $this->assertEquals('34.99', $variant->price);

        $listing->refresh();
        $this->assertTrue($listing->last_synced_at->isToday());
    }

    public function test_listings_item_status_change_updates_listing_status(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id]);

        PlatformListing::create([
            'store_marketplace_id' => $this->marketplace->id,
            'product_id' => $product->id,
            'external_listing_id' => 'AMZ-SKU-123',
            'status' => PlatformListing::STATUS_LISTED,
            'last_synced_at' => now()->subHour(),
        ]);

        $webhookPayload = [
            'NotificationType' => 'LISTINGS_ITEM_STATUS_CHANGE',
            'Payload' => [
                'SellerSKU' => 'AMZ-SKU-123',
                'Status' => 'Inactive',
            ],
        ];

        $webhookLog = WebhookLog::create([
            'store_marketplace_id' => $this->marketplace->id,
            'store_id' => $this->store->id,
            'platform' => Platform::Amazon,
            'event_type' => 'LISTINGS_ITEM_STATUS_CHANGE',
            'status' => WebhookLog::STATUS_PENDING,
            'payload' => $webhookPayload,
        ]);

        $job = new ProcessWebhookJob($webhookLog);
        $job->handle(app(OrderImportService::class), app(ReturnSyncService::class));

        $this->assertDatabaseHas('platform_listings', [
            'external_listing_id' => 'AMZ-SKU-123',
            'status' => PlatformListing::STATUS_ENDED,
        ]);
    }

    public function test_any_offer_changed_ignores_unknown_asin(): void
    {
        $webhookPayload = [
            'NotificationType' => 'ANY_OFFER_CHANGED',
            'Payload' => [
                'OfferChangeTrigger' => [
                    'ASIN' => 'B00UNKNOWN99',
                    'ItemCondition' => 'New',
                ],
                'Offers' => [],
            ],
        ];

        $webhookLog = WebhookLog::create([
            'store_marketplace_id' => $this->marketplace->id,
            'store_id' => $this->store->id,
            'platform' => Platform::Amazon,
            'event_type' => 'ANY_OFFER_CHANGED',
            'status' => WebhookLog::STATUS_PENDING,
            'payload' => $webhookPayload,
        ]);

        $job = new ProcessWebhookJob($webhookLog);
        $job->handle(app(OrderImportService::class), app(ReturnSyncService::class));

        $webhookLog->refresh();
        $this->assertEquals(WebhookLog::STATUS_COMPLETED, $webhookLog->status);
    }
}
