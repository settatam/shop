<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Jobs\ProcessWebhookJob;
use App\Models\PlatformListing;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesChannel;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Models\WebhookLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EbayWebhookHandlingTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected StoreMarketplace $marketplace;

    protected SalesChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();

        $this->marketplace = StoreMarketplace::factory()->ebay()->create([
            'store_id' => $this->store->id,
            'connected_successfully' => true,
            'status' => 'active',
            'settings' => ['marketplace_id' => 'EBAY_US'],
        ]);

        $this->channel = SalesChannel::factory()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
            'type' => 'ebay',
        ]);
    }

    protected function createListedListing(string $externalListingId, array $platformData = []): PlatformListing
    {
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        ProductVariant::factory()->create(['product_id' => $product->id]);

        $listing = PlatformListing::where('product_id', $product->id)
            ->where('store_marketplace_id', $this->marketplace->id)
            ->first();

        if (! $listing) {
            $listing = PlatformListing::create([
                'product_id' => $product->id,
                'store_marketplace_id' => $this->marketplace->id,
                'sales_channel_id' => $this->channel->id,
                'status' => PlatformListing::STATUS_LISTED,
                'external_listing_id' => $externalListingId,
                'platform_data' => $platformData,
            ]);
        } else {
            $listing->update([
                'status' => PlatformListing::STATUS_LISTED,
                'external_listing_id' => $externalListingId,
                'platform_data' => $platformData,
            ]);
        }

        return $listing->fresh();
    }

    public function test_item_closed_webhook_is_accepted(): void
    {
        $response = $this->postJson("/api/webhooks/ebay/{$this->marketplace->id}", [
            'metadata' => ['topic' => 'ITEM_CLOSED'],
            'resource' => ['listingId' => 'LISTING-123'],
        ]);

        $response->assertOk();
        $response->assertJson(['status' => 'queued']);

        $this->assertDatabaseHas('webhook_logs', [
            'store_marketplace_id' => $this->marketplace->id,
            'event_type' => 'ITEM_CLOSED',
        ]);
    }

    public function test_item_suspended_webhook_is_accepted(): void
    {
        $response = $this->postJson("/api/webhooks/ebay/{$this->marketplace->id}", [
            'metadata' => ['topic' => 'ITEM_SUSPENDED'],
            'resource' => ['listingId' => 'LISTING-456'],
        ]);

        $response->assertOk();
        $response->assertJson(['status' => 'queued']);
    }

    public function test_item_sold_webhook_is_accepted(): void
    {
        $response = $this->postJson("/api/webhooks/ebay/{$this->marketplace->id}", [
            'metadata' => ['topic' => 'ITEM_SOLD'],
            'resource' => ['orderId' => 'ORDER-789'],
        ]);

        $response->assertOk();
        $response->assertJson(['status' => 'queued']);
    }

    public function test_unknown_ebay_topic_is_skipped(): void
    {
        $response = $this->postJson("/api/webhooks/ebay/{$this->marketplace->id}", [
            'metadata' => ['topic' => 'SOME_UNKNOWN_TOPIC'],
        ]);

        $response->assertOk();
        $response->assertJson(['status' => 'skipped']);
    }

    public function test_extract_external_id_finds_listing_id(): void
    {
        $this->postJson("/api/webhooks/ebay/{$this->marketplace->id}", [
            'metadata' => ['topic' => 'ITEM_CLOSED'],
            'resource' => ['listingId' => 'EXTRACTED-LID'],
        ]);

        $this->assertDatabaseHas('webhook_logs', [
            'external_id' => 'EXTRACTED-LID',
        ]);
    }

    public function test_extract_external_id_finds_item_id(): void
    {
        $this->postJson("/api/webhooks/ebay/{$this->marketplace->id}", [
            'metadata' => ['topic' => 'ITEM_SUSPENDED'],
            'resource' => ['itemId' => 'ITEM-999'],
        ]);

        $this->assertDatabaseHas('webhook_logs', [
            'external_id' => 'ITEM-999',
        ]);
    }

    public function test_process_item_closed_updates_listing_to_ended(): void
    {
        $listing = $this->createListedListing('LID-CLOSE-1');

        $webhookLog = WebhookLog::create([
            'store_marketplace_id' => $this->marketplace->id,
            'store_id' => $this->store->id,
            'platform' => Platform::Ebay,
            'event_type' => 'ITEM_CLOSED',
            'external_id' => 'LID-CLOSE-1',
            'status' => WebhookLog::STATUS_PENDING,
            'payload' => [
                'metadata' => ['topic' => 'ITEM_CLOSED'],
                'resource' => ['listingId' => 'LID-CLOSE-1'],
            ],
        ]);

        $job = new ProcessWebhookJob($webhookLog);
        $job->handle(
            app(\App\Services\Webhooks\OrderImportService::class),
            app(\App\Services\Returns\ReturnSyncService::class)
        );

        $this->assertEquals(PlatformListing::STATUS_ENDED, $listing->fresh()->status);
        $this->assertEquals(WebhookLog::STATUS_COMPLETED, $webhookLog->fresh()->status);
    }

    public function test_process_item_suspended_updates_listing_to_error(): void
    {
        $listing = $this->createListedListing('LID-SUSP-1');

        $webhookLog = WebhookLog::create([
            'store_marketplace_id' => $this->marketplace->id,
            'store_id' => $this->store->id,
            'platform' => Platform::Ebay,
            'event_type' => 'ITEM_SUSPENDED',
            'external_id' => 'LID-SUSP-1',
            'status' => WebhookLog::STATUS_PENDING,
            'payload' => [
                'metadata' => ['topic' => 'ITEM_SUSPENDED'],
                'resource' => ['listingId' => 'LID-SUSP-1'],
            ],
        ]);

        $job = new ProcessWebhookJob($webhookLog);
        $job->handle(
            app(\App\Services\Webhooks\OrderImportService::class),
            app(\App\Services\Returns\ReturnSyncService::class)
        );

        $this->assertEquals(PlatformListing::STATUS_ERROR, $listing->fresh()->status);
        $this->assertEquals(WebhookLog::STATUS_COMPLETED, $webhookLog->fresh()->status);
    }

    public function test_process_item_sold_does_not_change_listing_status(): void
    {
        $listing = $this->createListedListing('LID-SOLD-1');

        $webhookLog = WebhookLog::create([
            'store_marketplace_id' => $this->marketplace->id,
            'store_id' => $this->store->id,
            'platform' => Platform::Ebay,
            'event_type' => 'ITEM_SOLD',
            'external_id' => 'LID-SOLD-1',
            'status' => WebhookLog::STATUS_PENDING,
            'payload' => [
                'metadata' => ['topic' => 'ITEM_SOLD'],
                'resource' => ['listingId' => 'LID-SOLD-1'],
            ],
        ]);

        $job = new ProcessWebhookJob($webhookLog);
        $job->handle(
            app(\App\Services\Webhooks\OrderImportService::class),
            app(\App\Services\Returns\ReturnSyncService::class)
        );

        // ITEM_SOLD doesn't change status — order sync handles the order
        $this->assertEquals(PlatformListing::STATUS_LISTED, $listing->fresh()->status);
        $this->assertEquals(WebhookLog::STATUS_COMPLETED, $webhookLog->fresh()->status);
    }

    public function test_process_listing_webhook_with_no_matching_listing_is_skipped(): void
    {
        $webhookLog = WebhookLog::create([
            'store_marketplace_id' => $this->marketplace->id,
            'store_id' => $this->store->id,
            'platform' => Platform::Ebay,
            'event_type' => 'ITEM_CLOSED',
            'external_id' => 'NONEXISTENT-LID',
            'status' => WebhookLog::STATUS_PENDING,
            'payload' => [
                'metadata' => ['topic' => 'ITEM_CLOSED'],
                'resource' => ['listingId' => 'NONEXISTENT-LID'],
            ],
        ]);

        $job = new ProcessWebhookJob($webhookLog);
        $job->handle(
            app(\App\Services\Webhooks\OrderImportService::class),
            app(\App\Services\Returns\ReturnSyncService::class)
        );

        $this->assertEquals(WebhookLog::STATUS_SKIPPED, $webhookLog->fresh()->status);
    }

    public function test_process_listing_webhook_with_no_listing_id_is_skipped(): void
    {
        $webhookLog = WebhookLog::create([
            'store_marketplace_id' => $this->marketplace->id,
            'store_id' => $this->store->id,
            'platform' => Platform::Ebay,
            'event_type' => 'ITEM_CLOSED',
            'status' => WebhookLog::STATUS_PENDING,
            'payload' => [
                'metadata' => ['topic' => 'ITEM_CLOSED'],
                'resource' => [],
            ],
        ]);

        $job = new ProcessWebhookJob($webhookLog);
        $job->handle(
            app(\App\Services\Webhooks\OrderImportService::class),
            app(\App\Services\Returns\ReturnSyncService::class)
        );

        $this->assertEquals(WebhookLog::STATUS_SKIPPED, $webhookLog->fresh()->status);
    }
}
