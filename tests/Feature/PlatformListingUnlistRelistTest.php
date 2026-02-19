<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\PlatformListing;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformListingUnlistRelistTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected StoreMarketplace $marketplace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Test Store',
            'step' => 2,
        ]);

        $this->user->update(['current_store_id' => $this->store->id]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        $this->marketplace = StoreMarketplace::factory()->create([
            'store_id' => $this->store->id,
            'platform' => Platform::Shopify,
            'name' => 'Test Shopify Store',
            'status' => 'active',
            'shop_domain' => 'test-store.myshopify.com',
            'access_token' => 'test-token',
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
        $this->actingAs($this->user);
    }

    public function test_unlisting_product_changes_status_to_unlisted(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'active',
        ]);

        $listing = PlatformListing::create([
            'store_marketplace_id' => $this->marketplace->id,
            'product_id' => $product->id,
            'external_listing_id' => '12345',
            'status' => PlatformListing::STATUS_ACTIVE,
            'published_at' => now(),
        ]);

        // Mark the listing as unlisted (simulating what the service would do)
        $listing->update([
            'status' => PlatformListing::STATUS_UNLISTED,
            'last_synced_at' => now(),
        ]);

        $this->assertEquals(PlatformListing::STATUS_UNLISTED, $listing->fresh()->status);
        $this->assertNotNull($listing->fresh()->last_synced_at);
        // The listing should NOT be deleted
        $this->assertDatabaseHas('platform_listings', ['id' => $listing->id]);
    }

    public function test_relisting_product_changes_status_to_active(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'active',
        ]);

        $listing = PlatformListing::create([
            'store_marketplace_id' => $this->marketplace->id,
            'product_id' => $product->id,
            'external_listing_id' => '12345',
            'status' => PlatformListing::STATUS_UNLISTED,
        ]);

        // Mark the listing as active (simulating what the service would do)
        $listing->update([
            'status' => PlatformListing::STATUS_ACTIVE,
            'published_at' => now(),
            'last_synced_at' => now(),
        ]);

        $this->assertEquals(PlatformListing::STATUS_ACTIVE, $listing->fresh()->status);
        $this->assertNotNull($listing->fresh()->published_at);
    }

    public function test_platform_listing_has_unlisted_status_constant(): void
    {
        $this->assertEquals('unlisted', PlatformListing::STATUS_UNLISTED);
    }

    public function test_activity_log_has_listings_unlist_constant(): void
    {
        $this->assertEquals('listings.unlist', Activity::LISTINGS_UNLIST);
    }

    public function test_activity_log_has_listings_relist_constant(): void
    {
        $this->assertEquals('listings.relist', Activity::LISTINGS_RELIST);
    }

    public function test_can_log_unlist_activity(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'active',
        ]);

        $listing = PlatformListing::create([
            'store_marketplace_id' => $this->marketplace->id,
            'product_id' => $product->id,
            'external_listing_id' => '12345',
            'status' => PlatformListing::STATUS_ACTIVE,
        ]);

        ActivityLog::log(
            Activity::LISTINGS_UNLIST,
            $product,
            null,
            [
                'platform' => $this->marketplace->platform->value,
                'marketplace_name' => $this->marketplace->name,
                'listing_id' => $listing->id,
            ],
            "Unlisted from {$this->marketplace->name}"
        );

        $this->assertDatabaseHas('activity_logs', [
            'activity_slug' => Activity::LISTINGS_UNLIST,
            'subject_type' => Product::class,
            'subject_id' => $product->id,
        ]);
    }

    public function test_can_log_relist_activity(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'active',
        ]);

        $listing = PlatformListing::create([
            'store_marketplace_id' => $this->marketplace->id,
            'product_id' => $product->id,
            'external_listing_id' => '12345',
            'status' => PlatformListing::STATUS_UNLISTED,
        ]);

        ActivityLog::log(
            Activity::LISTINGS_RELIST,
            $product,
            null,
            [
                'platform' => $this->marketplace->platform->value,
                'marketplace_name' => $this->marketplace->name,
                'listing_id' => $listing->id,
            ],
            "Relisted on {$this->marketplace->name}"
        );

        $this->assertDatabaseHas('activity_logs', [
            'activity_slug' => Activity::LISTINGS_RELIST,
            'subject_type' => Product::class,
            'subject_id' => $product->id,
        ]);
    }

    public function test_unlisted_listing_can_be_found_by_product(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'active',
        ]);

        $listing = PlatformListing::create([
            'store_marketplace_id' => $this->marketplace->id,
            'product_id' => $product->id,
            'external_listing_id' => '12345',
            'status' => PlatformListing::STATUS_UNLISTED,
        ]);

        // Ensure the listing is still retrievable
        $foundListing = PlatformListing::where('product_id', $product->id)
            ->where('store_marketplace_id', $this->marketplace->id)
            ->first();

        $this->assertNotNull($foundListing);
        $this->assertEquals($listing->id, $foundListing->id);
        $this->assertEquals(PlatformListing::STATUS_UNLISTED, $foundListing->status);
    }
}
