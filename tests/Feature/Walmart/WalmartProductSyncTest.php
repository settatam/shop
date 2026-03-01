<?php

namespace Tests\Feature\Walmart;

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

class WalmartProductSyncTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected StoreMarketplace $marketplace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->marketplace = StoreMarketplace::factory()->walmart()->create([
            'store_id' => $this->store->id,
            'credentials' => [
                'client_id' => 'wm_test_id',
                'client_secret' => encrypt('wm_test_secret'),
            ],
            'token_expires_at' => now()->addHours(1),
            'status' => 'active',
        ]);
    }

    public function test_item_updated_webhook_fetches_from_api_and_updates_product(): void
    {
        Http::fake([
            'marketplace.walmartapis.com/v3/items/WM-SKU-1' => Http::response([
                'sku' => 'WM-SKU-1',
                'productName' => 'Updated Walmart Product',
                'shortDescription' => 'Updated walmart desc',
                'publishedStatus' => 'PUBLISHED',
                'price' => ['amount' => 79.99, 'currency' => 'USD'],
                'availableQuantity' => ['amount' => 30, 'unit' => 'EACH'],
            ]),
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Old Walmart Product',
            'is_published' => false,
        ]);

        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => '49.99',
            'quantity' => 10,
        ]);

        $listing = PlatformListing::create([
            'store_marketplace_id' => $this->marketplace->id,
            'product_id' => $product->id,
            'external_listing_id' => 'WM-SKU-1',
            'status' => PlatformListing::STATUS_LISTED,
            'last_synced_at' => now()->subHour(),
        ]);

        PlatformListingVariant::create([
            'platform_listing_id' => $listing->id,
            'product_variant_id' => $variant->id,
            'external_variant_id' => 'WM-SKU-1',
        ]);

        $webhookPayload = [
            'eventType' => 'ITEM_UPDATED',
            'payload' => [
                'sku' => 'WM-SKU-1',
            ],
        ];

        $webhookLog = WebhookLog::create([
            'store_marketplace_id' => $this->marketplace->id,
            'store_id' => $this->store->id,
            'platform' => Platform::Walmart,
            'event_type' => 'ITEM_UPDATED',
            'status' => WebhookLog::STATUS_PENDING,
            'payload' => $webhookPayload,
        ]);

        $job = new ProcessWebhookJob($webhookLog);
        $job->handle(app(OrderImportService::class), app(ReturnSyncService::class));

        $product->refresh();
        $this->assertEquals('Updated Walmart Product', $product->title);
        $this->assertTrue($product->is_published);

        $variant->refresh();
        $this->assertEquals('79.99', $variant->price);
        $this->assertEquals(30, $variant->quantity);
    }

    public function test_item_updated_webhook_ignores_unknown_sku(): void
    {
        Http::fake();

        $webhookPayload = [
            'eventType' => 'ITEM_UPDATED',
            'payload' => [
                'sku' => 'UNKNOWN-SKU',
            ],
        ];

        $webhookLog = WebhookLog::create([
            'store_marketplace_id' => $this->marketplace->id,
            'store_id' => $this->store->id,
            'platform' => Platform::Walmart,
            'event_type' => 'ITEM_UPDATED',
            'status' => WebhookLog::STATUS_PENDING,
            'payload' => $webhookPayload,
        ]);

        $job = new ProcessWebhookJob($webhookLog);
        $job->handle(app(OrderImportService::class), app(ReturnSyncService::class));

        $webhookLog->refresh();
        $this->assertEquals(WebhookLog::STATUS_COMPLETED, $webhookLog->status);
    }

    public function test_item_updated_handles_api_failure_gracefully(): void
    {
        Http::fake([
            'marketplace.walmartapis.com/v3/items/WM-FAIL' => Http::response('Server Error', 500),
        ]);

        $product = Product::factory()->create(['store_id' => $this->store->id]);

        $listing = PlatformListing::create([
            'store_marketplace_id' => $this->marketplace->id,
            'product_id' => $product->id,
            'external_listing_id' => 'WM-FAIL',
            'status' => PlatformListing::STATUS_LISTED,
            'last_synced_at' => now()->subDay(),
        ]);

        $webhookPayload = [
            'eventType' => 'ITEM_UPDATED',
            'payload' => [
                'sku' => 'WM-FAIL',
            ],
        ];

        $webhookLog = WebhookLog::create([
            'store_marketplace_id' => $this->marketplace->id,
            'store_id' => $this->store->id,
            'platform' => Platform::Walmart,
            'event_type' => 'ITEM_UPDATED',
            'status' => WebhookLog::STATUS_PENDING,
            'payload' => $webhookPayload,
        ]);

        $job = new ProcessWebhookJob($webhookLog);
        $job->handle(app(OrderImportService::class), app(ReturnSyncService::class));

        $listing->refresh();
        $this->assertTrue($listing->last_synced_at->isToday());
    }
}
