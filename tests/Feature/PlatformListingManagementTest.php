<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\PlatformListing;
use App\Models\PlatformListingVariant;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformListingManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected SalesChannel $localChannel;

    protected SalesChannel $externalChannel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->onboarded()->create(['user_id' => $this->user->id]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        $this->user->update(['current_store_id' => $this->store->id]);

        // Use existing local channel or create one
        $this->localChannel = SalesChannel::where('store_id', $this->store->id)
            ->where('is_local', true)
            ->first();

        if (! $this->localChannel) {
            $this->localChannel = SalesChannel::factory()->local()->active()->create([
                'store_id' => $this->store->id,
                'code' => 'in_store_'.uniqid(),
            ]);
        }

        // Create external channel with unique code
        $this->externalChannel = SalesChannel::factory()->active()->create([
            'store_id' => $this->store->id,
            'name' => 'eBay',
            'code' => 'ebay_'.uniqid(),
            'type' => 'ebay',
            'is_local' => false,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_product_auto_creates_listings_for_all_active_channels(): void
    {
        $this->actingAs($this->user);

        // Create a product with active status
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
        ]);

        // Should have listings for both channels
        $this->assertDatabaseHas('platform_listings', [
            'product_id' => $product->id,
            'sales_channel_id' => $this->localChannel->id,
            'status' => PlatformListing::STATUS_LISTED,
        ]);

        $this->assertDatabaseHas('platform_listings', [
            'product_id' => $product->id,
            'sales_channel_id' => $this->externalChannel->id,
            'status' => PlatformListing::STATUS_NOT_LISTED,
        ]);
    }

    public function test_local_channel_listing_is_listed_when_product_is_active(): void
    {
        $this->actingAs($this->user);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
        ]);

        $listing = PlatformListing::where('product_id', $product->id)
            ->where('sales_channel_id', $this->localChannel->id)
            ->first();

        $this->assertNotNull($listing);
        $this->assertTrue($listing->isListed());
    }

    public function test_external_channel_listing_is_not_listed_initially(): void
    {
        $this->actingAs($this->user);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
        ]);

        $listing = PlatformListing::where('product_id', $product->id)
            ->where('sales_channel_id', $this->externalChannel->id)
            ->first();

        $this->assertNotNull($listing);
        $this->assertTrue($listing->isNotListed());
    }

    public function test_listing_cannot_be_deleted_only_archived(): void
    {
        $this->actingAs($this->user);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
        ]);

        $listing = PlatformListing::where('product_id', $product->id)
            ->where('sales_channel_id', $this->localChannel->id)
            ->first();

        $listingId = $listing->id;

        // Try to delete
        $listing->delete();

        // Should still exist but be archived
        $this->assertDatabaseHas('platform_listings', [
            'id' => $listingId,
            'status' => PlatformListing::STATUS_ARCHIVED,
        ]);
    }

    public function test_valid_status_transitions(): void
    {
        $listing = PlatformListing::factory()->notListed()->create([
            'sales_channel_id' => $this->localChannel->id,
        ]);

        // Not listed -> listed is valid
        $this->assertTrue($listing->canTransitionTo(PlatformListing::STATUS_LISTED));

        // Not listed -> ended is invalid
        $this->assertFalse($listing->canTransitionTo(PlatformListing::STATUS_ENDED));

        // Update to listed
        $listing->update(['status' => PlatformListing::STATUS_LISTED]);

        // Listed -> ended is valid
        $this->assertTrue($listing->canTransitionTo(PlatformListing::STATUS_ENDED));

        // Listed -> not_listed is invalid (must go through ended first)
        $this->assertFalse($listing->canTransitionTo(PlatformListing::STATUS_NOT_LISTED));
    }

    public function test_status_change_endpoint_works(): void
    {
        $this->actingAs($this->user);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
        ]);

        // Get the local channel listing (which should be listed)
        $listing = PlatformListing::where('product_id', $product->id)
            ->where('sales_channel_id', $this->localChannel->id)
            ->first();

        // Change to ended
        $response = $this->patchJson("/listings/{$listing->id}/status", [
            'status' => PlatformListing::STATUS_ENDED,
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $listing->refresh();
        $this->assertEquals(PlatformListing::STATUS_ENDED, $listing->status);
    }

    public function test_status_change_logs_activity(): void
    {
        $this->actingAs($this->user);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
        ]);

        $listing = PlatformListing::where('product_id', $product->id)
            ->where('sales_channel_id', $this->localChannel->id)
            ->first();

        // Change status
        $this->patchJson("/listings/{$listing->id}/status", [
            'status' => PlatformListing::STATUS_ENDED,
        ]);

        // Check activity log
        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => PlatformListing::class,
            'subject_id' => $listing->id,
            'activity_slug' => Activity::LISTINGS_STATUS_CHANGE,
        ]);
    }

    public function test_invalid_status_transition_returns_error(): void
    {
        $this->actingAs($this->user);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
        ]);

        // Get external channel listing (not listed)
        $listing = PlatformListing::where('product_id', $product->id)
            ->where('sales_channel_id', $this->externalChannel->id)
            ->first();

        // Try to change directly to ended (invalid - must be listed first)
        $response = $this->patchJson("/listings/{$listing->id}/status", [
            'status' => PlatformListing::STATUS_ENDED,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
    }

    public function test_listing_show_page_loads(): void
    {
        $this->actingAs($this->user);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
        ]);

        $listing = PlatformListing::where('product_id', $product->id)
            ->where('sales_channel_id', $this->localChannel->id)
            ->first();

        $response = $this->get("/listings/{$listing->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('listings/Show')
            ->has('listing')
            ->has('product')
            ->has('channel')
            ->has('statusOptions')
            ->has('activities')
        );
    }

    public function test_listing_activities_endpoint_returns_activities(): void
    {
        $this->actingAs($this->user);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
        ]);

        $listing = PlatformListing::where('product_id', $product->id)
            ->where('sales_channel_id', $this->localChannel->id)
            ->first();

        // Create some activity
        ActivityLog::log(
            Activity::LISTINGS_STATUS_CHANGE,
            $listing,
            null,
            ['old_status' => 'not_listed', 'new_status' => 'listed'],
            'Test activity'
        );

        $response = $this->getJson("/listings/{$listing->id}/activities");

        $response->assertOk();
        $response->assertJsonStructure([
            'activities' => [
                '*' => ['id', 'activity_slug', 'description', 'created_at'],
            ],
            'total',
            'has_more',
        ]);
    }

    public function test_product_status_change_ends_all_listings(): void
    {
        $this->actingAs($this->user);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
        ]);

        // Get the local listing and verify it's listed
        $localListing = PlatformListing::where('product_id', $product->id)
            ->where('sales_channel_id', $this->localChannel->id)
            ->first();
        $this->assertEquals(PlatformListing::STATUS_LISTED, $localListing->status);

        // Change product status to draft
        $product->update(['status' => Product::STATUS_DRAFT]);

        // Local listing should now be ended
        $localListing->refresh();
        $this->assertEquals(PlatformListing::STATUS_ENDED, $localListing->status);
    }

    public function test_normalize_status_handles_legacy_values(): void
    {
        $listing = PlatformListing::factory()->create([
            'status' => 'draft', // Legacy status
        ]);

        $this->assertEquals(PlatformListing::STATUS_NOT_LISTED, $listing->normalized_status);

        $listing->update(['status' => 'active']);
        $this->assertEquals(PlatformListing::STATUS_LISTED, $listing->normalized_status);

        $listing->update(['status' => 'unlisted']);
        $this->assertEquals(PlatformListing::STATUS_ENDED, $listing->normalized_status);
    }

    public function test_status_label_returns_correct_labels(): void
    {
        $listing = PlatformListing::factory()->notListed()->create();
        $this->assertEquals('Not Listed', $listing->status_label);

        $listing->update(['status' => PlatformListing::STATUS_LISTED]);
        $this->assertEquals('Listed', $listing->status_label);

        $listing->update(['status' => PlatformListing::STATUS_ENDED]);
        $this->assertEquals('Ended', $listing->status_label);

        $listing->update(['status' => PlatformListing::STATUS_ARCHIVED]);
        $this->assertEquals('Archived', $listing->status_label);
    }

    public function test_ensure_listing_exists_creates_listing_if_missing(): void
    {
        $this->actingAs($this->user);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_DRAFT, // Draft - won't auto-create
        ]);

        // Delete any auto-created listings
        PlatformListing::where('product_id', $product->id)->forceDelete();

        // Verify no listings
        $this->assertDatabaseMissing('platform_listings', [
            'product_id' => $product->id,
        ]);

        // Call ensureListingExists
        $listing = $product->ensureListingExists($this->localChannel);

        $this->assertNotNull($listing);
        $this->assertEquals($product->id, $listing->product_id);
        $this->assertEquals($this->localChannel->id, $listing->sales_channel_id);
    }

    public function test_channel_activation_creates_listings_for_all_products(): void
    {
        $this->actingAs($this->user);

        // Create some products
        $product1 = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
        ]);
        $product2 = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
        ]);

        // Create a new inactive channel
        $newChannel = SalesChannel::factory()->create([
            'store_id' => $this->store->id,
            'is_active' => false,
            'is_local' => false,
            'code' => 'new_channel_'.uniqid(),
        ]);

        // Delete any auto-created listings for this channel
        PlatformListing::where('sales_channel_id', $newChannel->id)->forceDelete();

        // Verify no listings exist for the new channel
        $this->assertEquals(0, PlatformListing::where('sales_channel_id', $newChannel->id)->count());

        // Activate the channel
        $newChannel->update(['is_active' => true]);

        // Verify listings were created for all products
        $this->assertEquals(2, PlatformListing::where('sales_channel_id', $newChannel->id)->count());
    }

    public function test_channel_deactivation_dispatches_job_to_end_listings(): void
    {
        $this->actingAs($this->user);

        // Create a product with a listed listing on the external channel
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
        ]);

        // Mark the external channel listing as listed
        $listing = PlatformListing::where('product_id', $product->id)
            ->where('sales_channel_id', $this->externalChannel->id)
            ->first();
        $listing->update(['status' => PlatformListing::STATUS_LISTED]);

        // Verify it's listed
        $this->assertEquals(PlatformListing::STATUS_LISTED, $listing->fresh()->status);

        // Fake the queue to capture the job
        \Illuminate\Support\Facades\Queue::fake();

        // Deactivate the channel
        $this->externalChannel->update(['is_active' => false]);

        // Verify the deactivation job was dispatched
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\DeactivateSalesChannelJob::class, function ($job) {
            return $job->channel->id === $this->externalChannel->id;
        });
    }

    public function test_deactivate_preflight_returns_listing_count(): void
    {
        $this->actingAs($this->user);

        // Create a product and mark its external listing as listed
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
        ]);

        $listing = PlatformListing::where('product_id', $product->id)
            ->where('sales_channel_id', $this->externalChannel->id)
            ->first();
        $listing->update(['status' => PlatformListing::STATUS_LISTED]);

        // Call the preflight endpoint
        $response = $this->getJson("/settings/channels/{$this->externalChannel->id}/deactivate-preflight");

        $response->assertOk();
        $response->assertJson([
            'channel_id' => $this->externalChannel->id,
            'active_listing_count' => 1,
            'is_external' => true,
        ]);
        $response->assertJsonStructure(['warning']);
    }

    public function test_sync_inventory_job_updates_listings(): void
    {
        $this->actingAs($this->user);

        // Create a product with quantity
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
            'quantity' => 10,
        ]);

        // Mark local listing as listed (local adapter always succeeds)
        $listing = PlatformListing::where('product_id', $product->id)
            ->where('sales_channel_id', $this->localChannel->id)
            ->first();
        $listing->update([
            'status' => PlatformListing::STATUS_LISTED,
            'platform_quantity' => 5,
        ]);

        // Dispatch the job synchronously
        \App\Jobs\SyncProductInventoryJob::dispatchSync($product, 'test');

        // Verify listing quantity was updated
        $listing->refresh();
        $this->assertEquals(10, $listing->platform_quantity);
    }

    public function test_sync_inventory_job_ends_listings_when_quantity_zero(): void
    {
        $this->actingAs($this->user);

        // Create a product with zero quantity
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
            'quantity' => 0,
        ]);

        // Mark local listing as listed (local adapter always succeeds)
        $listing = PlatformListing::where('product_id', $product->id)
            ->where('sales_channel_id', $this->localChannel->id)
            ->first();
        $listing->update(['status' => PlatformListing::STATUS_LISTED]);

        // Dispatch the job synchronously
        \App\Jobs\SyncProductInventoryJob::dispatchSync($product, 'test_zero_stock');

        // Verify listing was ended
        $listing->refresh();
        $this->assertEquals(PlatformListing::STATUS_ENDED, $listing->status);
    }

    public function test_product_sync_inventory_method_dispatches_job(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
        ]);

        $product->syncInventoryToAllPlatforms('manual_sync');

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SyncProductInventoryJob::class, function ($job) use ($product) {
            return $job->product->id === $product->id && $job->triggerReason === 'manual_sync';
        });
    }

    // ── Variant-aware tests ──────────────────────────────────────────

    public function test_listing_creates_variant_rows_for_each_product_variant(): void
    {
        $this->actingAs($this->user);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_DRAFT,
        ]);

        // Delete auto-created listings
        PlatformListing::where('product_id', $product->id)->forceDelete();

        // Create 3 variants
        $v1 = ProductVariant::factory()->create(['product_id' => $product->id, 'price' => 10.00, 'quantity' => 5]);
        $v2 = ProductVariant::factory()->create(['product_id' => $product->id, 'price' => 20.00, 'quantity' => 10]);
        $v3 = ProductVariant::factory()->create(['product_id' => $product->id, 'price' => 30.00, 'quantity' => 15]);

        $listing = $product->ensureListingExists($this->localChannel);

        $this->assertNotNull($listing);
        $this->assertEquals(3, $listing->listingVariants()->count());

        // Verify each variant row exists
        foreach ([$v1, $v2, $v3] as $variant) {
            $this->assertDatabaseHas('platform_listing_variants', [
                'platform_listing_id' => $listing->id,
                'product_variant_id' => $variant->id,
            ]);
        }
    }

    public function test_effective_title_returns_override_when_set(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Original Product Title',
        ]);

        // Use the auto-created listing for the external channel
        $listing = PlatformListing::where('product_id', $product->id)
            ->where('sales_channel_id', $this->externalChannel->id)
            ->first();

        // Without override, falls back to product title
        $this->assertEquals('Original Product Title', $listing->getEffectiveTitle());

        // With override
        $listing->update(['title' => 'Platform Override Title']);
        $this->assertEquals('Platform Override Title', $listing->getEffectiveTitle());
    }

    public function test_effective_description_returns_override_when_set(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'description' => 'Original Description',
        ]);

        // Use the auto-created listing for the external channel
        $listing = PlatformListing::where('product_id', $product->id)
            ->where('sales_channel_id', $this->externalChannel->id)
            ->first();

        $this->assertEquals('Original Description', $listing->getEffectiveDescription());

        $listing->update(['description' => 'Override Description']);
        $this->assertEquals('Override Description', $listing->getEffectiveDescription());
    }

    public function test_listing_variant_effective_price_returns_override(): void
    {
        $variant = ProductVariant::factory()->create(['price' => 25.00]);

        $listing = PlatformListing::factory()->create([
            'sales_channel_id' => $this->localChannel->id,
        ]);

        $listingVariant = PlatformListingVariant::factory()->create([
            'platform_listing_id' => $listing->id,
            'product_variant_id' => $variant->id,
            'price' => null,
        ]);

        // Falls back to product variant price
        $this->assertEquals(25.00, $listingVariant->getEffectivePrice());

        // With override
        $listingVariant->update(['price' => 35.00]);
        $this->assertEquals(35.00, $listingVariant->getEffectivePrice());
    }

    public function test_listing_variant_effective_sku_returns_override(): void
    {
        $variant = ProductVariant::factory()->create(['sku' => 'ORIG-SKU-001']);

        $listing = PlatformListing::factory()->create([
            'sales_channel_id' => $this->localChannel->id,
        ]);

        $listingVariant = PlatformListingVariant::factory()->create([
            'platform_listing_id' => $listing->id,
            'product_variant_id' => $variant->id,
            'sku' => null,
        ]);

        $this->assertEquals('ORIG-SKU-001', $listingVariant->getEffectiveSku());

        $listingVariant->update(['sku' => 'PLAT-SKU-001']);
        $this->assertEquals('PLAT-SKU-001', $listingVariant->getEffectiveSku());
    }

    public function test_listing_variant_effective_quantity_returns_override(): void
    {
        $variant = ProductVariant::factory()->create(['quantity' => 50]);

        $listing = PlatformListing::factory()->create([
            'sales_channel_id' => $this->localChannel->id,
        ]);

        $listingVariant = PlatformListingVariant::factory()->create([
            'platform_listing_id' => $listing->id,
            'product_variant_id' => $variant->id,
            'quantity' => null,
        ]);

        $this->assertEquals(50, $listingVariant->getEffectiveQuantity());

        $listingVariant->update(['quantity' => 75]);
        $this->assertEquals(75, $listingVariant->getEffectiveQuantity());
    }

    public function test_sync_listing_variants_creates_missing_variant_rows(): void
    {
        $this->actingAs($this->user);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_DRAFT,
        ]);

        // Delete auto-created listings
        PlatformListing::where('product_id', $product->id)->forceDelete();

        // Start with 1 variant
        $v1 = ProductVariant::factory()->create(['product_id' => $product->id]);

        $listing = $product->ensureListingExists($this->localChannel);
        $this->assertEquals(1, $listing->listingVariants()->count());

        // Add 2 more variants to the product
        $v2 = ProductVariant::factory()->create(['product_id' => $product->id]);
        $v3 = ProductVariant::factory()->create(['product_id' => $product->id]);

        // Sync should add the missing variants
        $product->syncListingVariants($listing);

        $this->assertEquals(3, $listing->listingVariants()->count());

        $this->assertDatabaseHas('platform_listing_variants', [
            'platform_listing_id' => $listing->id,
            'product_variant_id' => $v2->id,
        ]);
        $this->assertDatabaseHas('platform_listing_variants', [
            'platform_listing_id' => $listing->id,
            'product_variant_id' => $v3->id,
        ]);
    }

    public function test_unique_constraint_one_listing_per_product_per_channel(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
        ]);

        // Product auto-creates a listing for the external channel, so trying to create another should fail
        $this->assertDatabaseHas('platform_listings', [
            'product_id' => $product->id,
            'sales_channel_id' => $this->externalChannel->id,
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        PlatformListing::factory()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $this->externalChannel->id,
        ]);
    }

    public function test_listing_effective_price_uses_first_variant(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 99.99,
        ]);

        // Use the auto-created listing for the external channel
        $listing = PlatformListing::where('product_id', $product->id)
            ->where('sales_channel_id', $this->externalChannel->id)
            ->first();

        $listing->update(['platform_price' => null]);

        PlatformListingVariant::factory()->create([
            'platform_listing_id' => $listing->id,
            'product_variant_id' => $variant->id,
            'price' => 149.99,
        ]);

        $listing->load('listingVariants');
        $this->assertEquals(149.99, $listing->getEffectivePrice());
    }

    public function test_listing_with_overrides_factory_state(): void
    {
        $listing = PlatformListing::factory()->withOverrides([
            'title' => 'Custom Title',
            'description' => 'Custom Desc',
        ])->create([
            'sales_channel_id' => $this->localChannel->id,
        ]);

        $this->assertEquals('Custom Title', $listing->title);
        $this->assertEquals('Custom Desc', $listing->description);
    }

    public function test_listing_with_category_factory_state(): void
    {
        $listing = PlatformListing::factory()->withCategory('cat_999')->create([
            'sales_channel_id' => $this->localChannel->id,
        ]);

        $this->assertEquals('cat_999', $listing->platform_category_id);
    }

    public function test_listing_with_attributes_factory_state(): void
    {
        $listing = PlatformListing::factory()->withAttributes(['Color' => 'Red', 'Size' => 'Large'])->create([
            'sales_channel_id' => $this->localChannel->id,
        ]);

        $this->assertEquals(['Color' => 'Red', 'Size' => 'Large'], $listing->attributes);
    }

    public function test_platform_listing_variant_belongs_to_listing(): void
    {
        $listing = PlatformListing::factory()->create([
            'sales_channel_id' => $this->localChannel->id,
        ]);

        $variant = PlatformListingVariant::factory()->create([
            'platform_listing_id' => $listing->id,
        ]);

        $this->assertEquals($listing->id, $variant->listing->id);
    }

    public function test_platform_listing_variant_belongs_to_product_variant(): void
    {
        $productVariant = ProductVariant::factory()->create();

        $listingVariant = PlatformListingVariant::factory()->create([
            'product_variant_id' => $productVariant->id,
        ]);

        $this->assertEquals($productVariant->id, $listingVariant->productVariant->id);
    }

    public function test_listing_has_many_listing_variants(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $listing = PlatformListing::where('product_id', $product->id)
            ->where('sales_channel_id', $this->externalChannel->id)
            ->first();

        $v1 = ProductVariant::factory()->create(['product_id' => $product->id]);
        $v2 = ProductVariant::factory()->create(['product_id' => $product->id]);

        PlatformListingVariant::factory()->create([
            'platform_listing_id' => $listing->id,
            'product_variant_id' => $v1->id,
        ]);
        PlatformListingVariant::factory()->create([
            'platform_listing_id' => $listing->id,
            'product_variant_id' => $v2->id,
        ]);

        $listing->load('listingVariants');
        $this->assertEquals(2, $listing->listingVariants->count());
    }

    public function test_ensure_listing_exists_syncs_variants_on_existing_listing(): void
    {
        $this->actingAs($this->user);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_DRAFT,
        ]);

        // Delete auto-created listings
        PlatformListing::where('product_id', $product->id)->forceDelete();

        // Create 1 variant, then create listing
        $v1 = ProductVariant::factory()->create(['product_id' => $product->id]);
        $listing = $product->ensureListingExists($this->localChannel);
        $this->assertEquals(1, $listing->listingVariants()->count());

        // Add another variant
        $v2 = ProductVariant::factory()->create(['product_id' => $product->id]);

        // Call ensureListingExists again — should sync the new variant
        $listing = $product->ensureListingExists($this->localChannel);
        $this->assertEquals(2, $listing->listingVariants()->count());
    }
}
