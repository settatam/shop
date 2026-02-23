<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\PlatformListing;
use App\Models\Product;
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
}
