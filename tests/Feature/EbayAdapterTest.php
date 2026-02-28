<?php

namespace Tests\Feature;

use App\Models\PlatformListing;
use App\Models\PlatformListingVariant;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesChannel;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Services\Platforms\Adapters\EbayAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EbayAdapterTest extends TestCase
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
            'settings' => [
                'marketplace_id' => 'EBAY_US',
                'fulfillment_policy_id' => 'fp-1',
                'payment_policy_id' => 'pp-1',
                'return_policy_id' => 'rp-1',
                'location_key' => 'warehouse-1',
            ],
        ]);

        $this->channel = SalesChannel::factory()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
            'type' => 'ebay',
        ]);
    }

    protected function makeAdapter(): EbayAdapter
    {
        return new EbayAdapter($this->channel);
    }

    /**
     * Create a product with a single variant and return its auto-created listing.
     *
     * @return array{product: Product, listing: PlatformListing}
     */
    protected function createSingleVariantProduct(): array
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Test Widget',
            'handle' => 'test-widget',
            'description' => 'A test product',
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'WIDGET-001',
            'price' => 29.99,
            'quantity' => 10,
            'weight' => 1.5,
            'weight_unit' => 'lb',
        ]);

        $product->load('variants');

        // Use the auto-created listing from Product::created event
        $listing = PlatformListing::where('product_id', $product->id)
            ->where('store_marketplace_id', $this->marketplace->id)
            ->first();

        // If no auto-created listing (channel might not be linked), create one
        if (! $listing) {
            $listing = PlatformListing::create([
                'product_id' => $product->id,
                'store_marketplace_id' => $this->marketplace->id,
                'sales_channel_id' => $this->channel->id,
                'status' => PlatformListing::STATUS_NOT_LISTED,
            ]);
        }

        return ['product' => $product, 'listing' => $listing];
    }

    /**
     * Create a multi-variant product and return its auto-created listing.
     *
     * @return array{product: Product, listing: PlatformListing}
     */
    protected function createMultiVariantProduct(): array
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Multi Variant Shirt',
            'handle' => 'multi-variant-shirt',
            'description' => 'A shirt with multiple variants',
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'SHIRT-RED-S',
            'price' => 24.99,
            'quantity' => 5,
            'option1_name' => 'Color',
            'option1_value' => 'Red',
            'option2_name' => 'Size',
            'option2_value' => 'S',
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'SHIRT-RED-M',
            'price' => 24.99,
            'quantity' => 8,
            'option1_name' => 'Color',
            'option1_value' => 'Red',
            'option2_name' => 'Size',
            'option2_value' => 'M',
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'SHIRT-BLUE-S',
            'price' => 26.99,
            'quantity' => 3,
            'option1_name' => 'Color',
            'option1_value' => 'Blue',
            'option2_name' => 'Size',
            'option2_value' => 'S',
        ]);

        $product->load('variants');

        $listing = PlatformListing::where('product_id', $product->id)
            ->where('store_marketplace_id', $this->marketplace->id)
            ->first();

        if (! $listing) {
            $listing = PlatformListing::create([
                'product_id' => $product->id,
                'store_marketplace_id' => $this->marketplace->id,
                'sales_channel_id' => $this->channel->id,
                'status' => PlatformListing::STATUS_NOT_LISTED,
            ]);
        }

        return ['product' => $product, 'listing' => $listing];
    }

    protected function fakeEbayApiBaseUrl(): string
    {
        return config('services.ebay.sandbox')
            ? 'https://api.sandbox.ebay.com'
            : 'https://api.ebay.com';
    }

    // ──────────────────────────────────────────────────────────────
    //  Single-Variant Publish
    // ──────────────────────────────────────────────────────────────

    public function test_single_variant_publish_creates_inventory_item_and_offer_and_publishes(): void
    {
        $baseUrl = $this->fakeEbayApiBaseUrl();

        Http::fake([
            "{$baseUrl}/sell/inventory/v1/inventory_item/WIDGET-001" => Http::response([], 200),
            "{$baseUrl}/sell/inventory/v1/offer" => Http::response(['offerId' => 'offer-123'], 201),
            "{$baseUrl}/sell/inventory/v1/offer/offer-123/publish" => Http::response(['listingId' => '1234567890'], 200),
        ]);

        ['product' => $product, 'listing' => $listing] = $this->createSingleVariantProduct();

        $adapter = $this->makeAdapter();
        $result = $adapter->publish($listing);

        $this->assertTrue($result->success);
        $this->assertEquals('1234567890', $result->externalId);
        $this->assertStringContainsString('ebay.com/itm/1234567890', $result->externalUrl);

        Http::assertSentCount(3);
    }

    public function test_publish_returns_failure_when_not_connected(): void
    {
        $disconnectedMarketplace = StoreMarketplace::factory()->ebay()->create([
            'store_id' => $this->store->id,
            'access_token' => null,
        ]);

        $disconnectedChannel = SalesChannel::factory()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $disconnectedMarketplace->id,
            'type' => 'ebay',
        ]);

        ['listing' => $listing] = $this->createSingleVariantProduct();

        $adapter = new EbayAdapter($disconnectedChannel);
        $result = $adapter->publish($listing);

        $this->assertFalse($result->success);
        $this->assertEquals('eBay is not connected', $result->message);
    }

    // ──────────────────────────────────────────────────────────────
    //  Multi-Variant Publish
    // ──────────────────────────────────────────────────────────────

    public function test_multi_variant_publish_creates_inventory_items_group_offers_and_publishes(): void
    {
        $baseUrl = $this->fakeEbayApiBaseUrl();

        Http::fake([
            "{$baseUrl}/sell/inventory/v1/inventory_item/*" => Http::response([], 200),
            "{$baseUrl}/sell/inventory/v1/inventory_item_group/*" => Http::response([], 200),
            "{$baseUrl}/sell/inventory/v1/offer" => Http::sequence()
                ->push(['offerId' => 'offer-v1'], 201)
                ->push(['offerId' => 'offer-v2'], 201)
                ->push(['offerId' => 'offer-v3'], 201),
            "{$baseUrl}/sell/inventory/v1/offer/publish_by_inventory_item_group" => Http::response(['listingId' => '9876543210'], 200),
        ]);

        ['product' => $product, 'listing' => $listing] = $this->createMultiVariantProduct();

        $adapter = $this->makeAdapter();
        $result = $adapter->publish($listing);

        $this->assertTrue($result->success);
        $this->assertEquals('9876543210', $result->externalId);

        $listing->refresh();
        $this->assertTrue($listing->platform_data['multi_variant']);
        $this->assertEquals('multi-variant-shirt', $listing->platform_data['group_key']);
        $this->assertCount(3, $listing->platform_data['variant_skus']);
        $this->assertCount(3, $listing->platform_data['offer_ids']);
        $this->assertEquals(PlatformListing::STATUS_LISTED, $listing->status);
    }

    public function test_multi_variant_syncs_variant_external_ids_to_listing_variants(): void
    {
        $baseUrl = $this->fakeEbayApiBaseUrl();

        Http::fake([
            "{$baseUrl}/sell/inventory/v1/inventory_item/*" => Http::response([], 200),
            "{$baseUrl}/sell/inventory/v1/inventory_item_group/*" => Http::response([], 200),
            "{$baseUrl}/sell/inventory/v1/offer" => Http::sequence()
                ->push(['offerId' => 'offer-v1'], 201)
                ->push(['offerId' => 'offer-v2'], 201)
                ->push(['offerId' => 'offer-v3'], 201),
            "{$baseUrl}/sell/inventory/v1/offer/publish_by_inventory_item_group" => Http::response(['listingId' => '9876543210'], 200),
        ]);

        ['listing' => $listing] = $this->createMultiVariantProduct();

        $adapter = $this->makeAdapter();
        $result = $adapter->publish($listing);

        $this->assertTrue($result->success);

        $listing->refresh();
        $listingVariants = PlatformListingVariant::where('platform_listing_id', $listing->id)->get();

        $this->assertCount(3, $listingVariants);

        foreach ($listingVariants as $lv) {
            $this->assertNotNull($lv->external_variant_id);
            $this->assertNotNull($lv->external_inventory_item_id);
            $this->assertNotNull($lv->sku);
            $this->assertNotNull($lv->platform_data['offer_id']);
        }
    }

    public function test_multi_variant_correctly_maps_option_names_to_variation_aspects(): void
    {
        $baseUrl = $this->fakeEbayApiBaseUrl();
        $capturedGroupPayload = null;

        Http::fake(function ($request) use (&$capturedGroupPayload) {
            $url = $request->url();

            if (str_contains($url, '/inventory_item_group/')) {
                $capturedGroupPayload = $request->data();

                return Http::response([], 200);
            }

            if (str_contains($url, '/inventory_item/')) {
                return Http::response([], 200);
            }

            if (str_contains($url, '/offer/publish_by_inventory_item_group')) {
                return Http::response(['listingId' => '111222333'], 200);
            }

            if (str_contains($url, '/offer')) {
                return Http::response(['offerId' => 'offer-'.uniqid()], 201);
            }

            return Http::response([], 200);
        });

        ['listing' => $listing] = $this->createMultiVariantProduct();

        $adapter = $this->makeAdapter();
        $adapter->publish($listing);

        $this->assertNotNull($capturedGroupPayload);

        $specifications = collect($capturedGroupPayload['variesBy']['specifications']);
        $colorSpec = $specifications->firstWhere('name', 'Color');
        $sizeSpec = $specifications->firstWhere('name', 'Size');

        $this->assertNotNull($colorSpec);
        $this->assertNotNull($sizeSpec);
        $this->assertEqualsCanonicalizing(['Red', 'Blue'], $colorSpec['values']);
        $this->assertEqualsCanonicalizing(['S', 'M'], $sizeSpec['values']);

        $this->assertCount(3, $capturedGroupPayload['variantSKUs']);
        $this->assertContains('SHIRT-RED-S', $capturedGroupPayload['variantSKUs']);
        $this->assertContains('SHIRT-RED-M', $capturedGroupPayload['variantSKUs']);
        $this->assertContains('SHIRT-BLUE-S', $capturedGroupPayload['variantSKUs']);
    }

    // ──────────────────────────────────────────────────────────────
    //  Unpublish
    // ──────────────────────────────────────────────────────────────

    public function test_unpublish_withdraws_single_variant_offer(): void
    {
        $baseUrl = $this->fakeEbayApiBaseUrl();

        Http::fake([
            "{$baseUrl}/sell/inventory/v1/offer/offer-123/withdraw" => Http::response([], 200),
        ]);

        ['listing' => $listing] = $this->createSingleVariantProduct();
        $listing->update([
            'status' => PlatformListing::STATUS_LISTED,
            'published_at' => now(),
            'platform_data' => ['sku' => 'WIDGET-001', 'offer_id' => 'offer-123'],
        ]);

        $adapter = $this->makeAdapter();
        $result = $adapter->unpublish($listing);

        $this->assertTrue($result->success);
        $this->assertEquals('Listing withdrawn from eBay', $result->message);

        $listing->refresh();
        $this->assertEquals(PlatformListing::STATUS_ENDED, $listing->status);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/offer/offer-123/withdraw'));
    }

    public function test_unpublish_withdraws_all_multi_variant_offers(): void
    {
        $baseUrl = $this->fakeEbayApiBaseUrl();

        Http::fake([
            "{$baseUrl}/sell/inventory/v1/offer/*/withdraw" => Http::response([], 200),
        ]);

        ['listing' => $listing] = $this->createMultiVariantProduct();
        $listing->update([
            'status' => PlatformListing::STATUS_LISTED,
            'published_at' => now(),
            'platform_data' => [
                'multi_variant' => true,
                'group_key' => 'multi-variant-shirt',
                'offer_ids' => [
                    'SHIRT-RED-S' => 'offer-v1',
                    'SHIRT-RED-M' => 'offer-v2',
                    'SHIRT-BLUE-S' => 'offer-v3',
                ],
            ],
        ]);

        $adapter = $this->makeAdapter();
        $result = $adapter->unpublish($listing);

        $this->assertTrue($result->success);

        $listing->refresh();
        $this->assertEquals(PlatformListing::STATUS_ENDED, $listing->status);

        Http::assertSentCount(3);
    }

    // ──────────────────────────────────────────────────────────────
    //  End Listing
    // ──────────────────────────────────────────────────────────────

    public function test_end_listing_deletes_offer_and_inventory_item(): void
    {
        $baseUrl = $this->fakeEbayApiBaseUrl();

        Http::fake([
            "{$baseUrl}/sell/inventory/v1/offer/offer-123" => Http::response([], 200),
            "{$baseUrl}/sell/inventory/v1/inventory_item/WIDGET-001" => Http::response([], 200),
        ]);

        ['listing' => $listing] = $this->createSingleVariantProduct();
        $listing->update([
            'status' => PlatformListing::STATUS_LISTED,
            'published_at' => now(),
            'platform_data' => ['sku' => 'WIDGET-001', 'offer_id' => 'offer-123'],
        ]);

        $listingId = $listing->id;

        $adapter = $this->makeAdapter();
        $result = $adapter->end($listing);

        $this->assertTrue($result->success);
        $this->assertEquals('Listing ended and removed from eBay', $result->message);
        $this->assertNull(PlatformListing::find($listingId));
    }

    public function test_end_multi_variant_listing_deletes_all_resources(): void
    {
        $baseUrl = $this->fakeEbayApiBaseUrl();

        Http::fake([
            "{$baseUrl}/sell/inventory/v1/offer/*" => Http::response([], 200),
            "{$baseUrl}/sell/inventory/v1/inventory_item_group/*" => Http::response([], 200),
            "{$baseUrl}/sell/inventory/v1/inventory_item/*" => Http::response([], 200),
        ]);

        ['product' => $product, 'listing' => $listing] = $this->createMultiVariantProduct();
        $listing->update([
            'status' => PlatformListing::STATUS_LISTED,
            'published_at' => now(),
            'platform_data' => [
                'multi_variant' => true,
                'group_key' => 'multi-variant-shirt',
                'variant_skus' => ['SHIRT-RED-S', 'SHIRT-RED-M', 'SHIRT-BLUE-S'],
                'offer_ids' => [
                    'SHIRT-RED-S' => 'offer-v1',
                    'SHIRT-RED-M' => 'offer-v2',
                    'SHIRT-BLUE-S' => 'offer-v3',
                ],
            ],
        ]);

        foreach ($product->variants as $variant) {
            PlatformListingVariant::factory()->create([
                'platform_listing_id' => $listing->id,
                'product_variant_id' => $variant->id,
            ]);
        }

        $listingId = $listing->id;

        $adapter = $this->makeAdapter();
        $result = $adapter->end($listing);

        $this->assertTrue($result->success);
        $this->assertNull(PlatformListing::find($listingId));
        $this->assertEquals(0, PlatformListingVariant::where('platform_listing_id', $listingId)->count());
    }

    // ──────────────────────────────────────────────────────────────
    //  Update Price
    // ──────────────────────────────────────────────────────────────

    public function test_update_price_updates_single_variant_offer(): void
    {
        $baseUrl = $this->fakeEbayApiBaseUrl();

        Http::fake([
            "{$baseUrl}/sell/inventory/v1/offer/offer-123" => Http::response([], 200),
        ]);

        ['listing' => $listing] = $this->createSingleVariantProduct();
        $listing->update([
            'status' => PlatformListing::STATUS_LISTED,
            'platform_data' => ['sku' => 'WIDGET-001', 'offer_id' => 'offer-123'],
        ]);

        $adapter = $this->makeAdapter();
        $result = $adapter->updatePrice($listing, 39.99);

        $this->assertTrue($result->success);
        $this->assertEquals('Price updated', $result->message);
        $this->assertEquals(39.99, $result->data['price']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/offer/offer-123')
                && $request->method() === 'PUT'
                && $request->data()['pricingSummary']['price']['value'] === '39.99';
        });
    }

    public function test_update_price_updates_all_multi_variant_offers(): void
    {
        $baseUrl = $this->fakeEbayApiBaseUrl();

        Http::fake([
            "{$baseUrl}/sell/inventory/v1/offer/*" => Http::response([], 200),
        ]);

        ['listing' => $listing] = $this->createMultiVariantProduct();
        $listing->update([
            'status' => PlatformListing::STATUS_LISTED,
            'platform_data' => [
                'multi_variant' => true,
                'offer_ids' => [
                    'SHIRT-RED-S' => 'offer-v1',
                    'SHIRT-RED-M' => 'offer-v2',
                    'SHIRT-BLUE-S' => 'offer-v3',
                ],
            ],
        ]);

        $adapter = $this->makeAdapter();
        $result = $adapter->updatePrice($listing, 34.99);

        $this->assertTrue($result->success);
        Http::assertSentCount(3);
    }

    public function test_update_price_returns_failure_without_offer_id(): void
    {
        Http::fake();

        ['listing' => $listing] = $this->createSingleVariantProduct();
        $listing->update([
            'status' => PlatformListing::STATUS_LISTED,
            'platform_data' => ['sku' => 'WIDGET-001'],
        ]);

        $adapter = $this->makeAdapter();
        $result = $adapter->updatePrice($listing, 39.99);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('No offer ID', $result->message);
    }

    // ──────────────────────────────────────────────────────────────
    //  Update Inventory
    // ──────────────────────────────────────────────────────────────

    public function test_update_inventory_updates_single_variant_quantity(): void
    {
        $baseUrl = $this->fakeEbayApiBaseUrl();

        Http::fake([
            "{$baseUrl}/sell/inventory/v1/inventory_item/WIDGET-001" => Http::response([], 200),
        ]);

        ['listing' => $listing] = $this->createSingleVariantProduct();
        $listing->update([
            'status' => PlatformListing::STATUS_LISTED,
            'platform_data' => ['sku' => 'WIDGET-001', 'offer_id' => 'offer-123'],
        ]);

        $adapter = $this->makeAdapter();
        $result = $adapter->updateInventory($listing, 25);

        $this->assertTrue($result->success);
        $this->assertEquals(25, $result->data['quantity']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/inventory_item/WIDGET-001')
                && $request->data()['availability']['shipToLocationAvailability']['quantity'] === 25;
        });
    }

    public function test_update_inventory_updates_all_multi_variant_quantities(): void
    {
        $baseUrl = $this->fakeEbayApiBaseUrl();

        Http::fake([
            "{$baseUrl}/sell/inventory/v1/inventory_item/*" => Http::response([], 200),
        ]);

        ['listing' => $listing] = $this->createMultiVariantProduct();
        $listing->update([
            'status' => PlatformListing::STATUS_LISTED,
            'platform_data' => [
                'multi_variant' => true,
                'variant_skus' => ['SHIRT-RED-S', 'SHIRT-RED-M', 'SHIRT-BLUE-S'],
            ],
        ]);

        $adapter = $this->makeAdapter();
        $result = $adapter->updateInventory($listing, 15);

        $this->assertTrue($result->success);
        Http::assertSentCount(3);
    }

    // ──────────────────────────────────────────────────────────────
    //  Refresh
    // ──────────────────────────────────────────────────────────────

    public function test_refresh_retrieves_single_variant_listing_status(): void
    {
        $baseUrl = $this->fakeEbayApiBaseUrl();

        Http::fake([
            "{$baseUrl}/sell/inventory/v1/offer/offer-123" => Http::response([
                'status' => 'PUBLISHED',
                'availableQuantity' => 10,
                'pricingSummary' => [
                    'price' => ['value' => '29.99', 'currency' => 'USD'],
                ],
                'listing' => ['listingId' => '1234567890'],
            ], 200),
        ]);

        ['listing' => $listing] = $this->createSingleVariantProduct();
        $listing->update([
            'status' => PlatformListing::STATUS_LISTED,
            'platform_data' => ['sku' => 'WIDGET-001', 'offer_id' => 'offer-123'],
        ]);

        $adapter = $this->makeAdapter();
        $result = $adapter->refresh($listing);

        $this->assertTrue($result->success);
        $this->assertEquals('PUBLISHED', $result->data['status']);
        $this->assertEquals('29.99', $result->data['price']);
        $this->assertEquals(10, $result->data['quantity']);
        $this->assertEquals('1234567890', $result->data['listing_id']);
    }

    public function test_refresh_retrieves_multi_variant_group_data(): void
    {
        $baseUrl = $this->fakeEbayApiBaseUrl();

        Http::fake([
            "{$baseUrl}/sell/inventory/v1/inventory_item_group/multi-variant-shirt" => Http::response([
                'inventoryItemGroupKey' => 'multi-variant-shirt',
                'variantSKUs' => ['SHIRT-RED-S', 'SHIRT-RED-M', 'SHIRT-BLUE-S'],
                'title' => 'Multi Variant Shirt',
            ], 200),
        ]);

        ['listing' => $listing] = $this->createMultiVariantProduct();
        $listing->update([
            'status' => PlatformListing::STATUS_LISTED,
            'platform_data' => [
                'multi_variant' => true,
                'group_key' => 'multi-variant-shirt',
            ],
        ]);

        $adapter = $this->makeAdapter();
        $result = $adapter->refresh($listing);

        $this->assertTrue($result->success);
        $this->assertCount(3, $result->data['variant_skus']);
    }

    public function test_refresh_returns_failure_without_offer_id(): void
    {
        Http::fake();

        ['listing' => $listing] = $this->createSingleVariantProduct();
        $listing->update([
            'status' => PlatformListing::STATUS_LISTED,
            'platform_data' => ['sku' => 'WIDGET-001'],
        ]);

        $adapter = $this->makeAdapter();
        $result = $adapter->refresh($listing);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('No offer ID', $result->message);
    }

    // ──────────────────────────────────────────────────────────────
    //  Stale Offer Recovery
    // ──────────────────────────────────────────────────────────────

    public function test_single_variant_handles_stale_offer_id_gracefully(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/inventory_item/WIDGET-001') && $request->method() === 'PUT') {
                return Http::response([], 200);
            }

            // Old offer update fails (stale)
            if (str_contains($url, '/offer/stale-offer') && $request->method() === 'PUT') {
                return Http::response(['errors' => [['errorId' => 25001]]], 404);
            }

            // New offer creation succeeds
            if (str_contains($url, '/offer') && $request->method() === 'POST' && ! str_contains($url, '/publish')) {
                return Http::response(['offerId' => 'new-offer-456'], 201);
            }

            if (str_contains($url, '/offer/new-offer-456/publish')) {
                return Http::response(['listingId' => '5555555555'], 200);
            }

            return Http::response([], 200);
        });

        ['listing' => $listing] = $this->createSingleVariantProduct();
        $listing->update([
            'platform_data' => ['sku' => 'WIDGET-001', 'offer_id' => 'stale-offer'],
        ]);

        $adapter = $this->makeAdapter();
        $result = $adapter->publish($listing);

        $this->assertTrue($result->success);
        $this->assertEquals('5555555555', $result->externalId);

        $listing->refresh();
        $this->assertEquals('new-offer-456', $listing->platform_data['offer_id']);
    }

    // ──────────────────────────────────────────────────────────────
    //  Sync delegates to publish
    // ──────────────────────────────────────────────────────────────

    public function test_sync_delegates_to_publish(): void
    {
        $baseUrl = $this->fakeEbayApiBaseUrl();

        Http::fake([
            "{$baseUrl}/sell/inventory/v1/inventory_item/WIDGET-001" => Http::response([], 200),
            "{$baseUrl}/sell/inventory/v1/offer" => Http::response(['offerId' => 'offer-sync'], 201),
            "{$baseUrl}/sell/inventory/v1/offer/offer-sync/publish" => Http::response(['listingId' => '7777777777'], 200),
        ]);

        ['listing' => $listing] = $this->createSingleVariantProduct();

        $adapter = $this->makeAdapter();
        $result = $adapter->sync($listing);

        $this->assertTrue($result->success);
        $this->assertEquals('7777777777', $result->externalId);
    }

    // ──────────────────────────────────────────────────────────────
    //  Connection checks
    // ──────────────────────────────────────────────────────────────

    public function test_unpublish_returns_failure_when_not_connected(): void
    {
        $disconnectedMarketplace = StoreMarketplace::factory()->ebay()->create([
            'store_id' => $this->store->id,
            'access_token' => null,
        ]);

        $disconnectedChannel = SalesChannel::factory()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $disconnectedMarketplace->id,
        ]);

        ['listing' => $listing] = $this->createSingleVariantProduct();

        $adapter = new EbayAdapter($disconnectedChannel);
        $result = $adapter->unpublish($listing);

        $this->assertFalse($result->success);
        $this->assertEquals('eBay is not connected', $result->message);
    }

    public function test_end_returns_failure_when_not_connected(): void
    {
        $disconnectedMarketplace = StoreMarketplace::factory()->ebay()->create([
            'store_id' => $this->store->id,
            'access_token' => null,
        ]);

        $disconnectedChannel = SalesChannel::factory()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $disconnectedMarketplace->id,
        ]);

        ['listing' => $listing] = $this->createSingleVariantProduct();

        $adapter = new EbayAdapter($disconnectedChannel);
        $result = $adapter->end($listing);

        $this->assertFalse($result->success);
        $this->assertEquals('eBay is not connected', $result->message);
    }

    public function test_update_price_returns_failure_when_not_connected(): void
    {
        $disconnectedMarketplace = StoreMarketplace::factory()->ebay()->create([
            'store_id' => $this->store->id,
            'access_token' => null,
        ]);

        $disconnectedChannel = SalesChannel::factory()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $disconnectedMarketplace->id,
        ]);

        ['listing' => $listing] = $this->createSingleVariantProduct();

        $adapter = new EbayAdapter($disconnectedChannel);
        $result = $adapter->updatePrice($listing, 10.00);

        $this->assertFalse($result->success);
        $this->assertEquals('eBay is not connected', $result->message);
    }

    public function test_refresh_returns_failure_when_not_connected(): void
    {
        $disconnectedMarketplace = StoreMarketplace::factory()->ebay()->create([
            'store_id' => $this->store->id,
            'access_token' => null,
        ]);

        $disconnectedChannel = SalesChannel::factory()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $disconnectedMarketplace->id,
        ]);

        ['listing' => $listing] = $this->createSingleVariantProduct();

        $adapter = new EbayAdapter($disconnectedChannel);
        $result = $adapter->refresh($listing);

        $this->assertFalse($result->success);
        $this->assertEquals('eBay is not connected', $result->message);
    }
}
