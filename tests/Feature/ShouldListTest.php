<?php

namespace Tests\Feature;

use App\Models\PlatformListing;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShouldListTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id, 'step' => 2]);

        Role::createDefaultRoles($this->store->id);

        $ownerRole = Role::where('store_id', $this->store->id)
            ->where('slug', 'owner')
            ->first();

        StoreUser::create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $ownerRole->id,
            'is_owner' => true,
            'status' => 'active',
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $this->user->email,
        ]);

        $this->user->update(['current_store_id' => $this->store->id]);

        // Clean up any auto-created channels so they don't interfere
        SalesChannel::where('store_id', $this->store->id)->forceDelete();
    }

    protected function withStore()
    {
        return $this->withSession(['current_store_id' => $this->store->id]);
    }

    public function test_toggle_should_list_on_channel(): void
    {
        $this->actingAs($this->user);

        // Create channel first, then product (product auto-creates listing for active channels)
        $channel = SalesChannel::factory()->create([
            'store_id' => $this->store->id,
            'is_active' => true,
        ]);

        $product = Product::factory()->create(['store_id' => $this->store->id]);

        // Find the auto-created listing
        $listing = PlatformListing::where('product_id', $product->id)
            ->where('sales_channel_id', $channel->id)
            ->firstOrFail();

        $this->assertTrue($listing->should_list);

        // Toggle off
        $response = $this->withStore()
            ->postJson("/products/{$product->id}/channels/{$channel->id}/toggle-should-list");

        $response->assertOk();
        $response->assertJson(['success' => true, 'should_list' => false]);
        $this->assertFalse($listing->fresh()->should_list);

        // Toggle back on
        $response = $this->withStore()
            ->postJson("/products/{$product->id}/channels/{$channel->id}/toggle-should-list");

        $response->assertOk();
        $response->assertJson(['success' => true, 'should_list' => true]);
        $this->assertTrue($listing->fresh()->should_list);
    }

    public function test_toggle_should_list_on_marketplace(): void
    {
        $this->actingAs($this->user);

        $marketplace = StoreMarketplace::factory()->ebay()->create([
            'store_id' => $this->store->id,
            'connected_successfully' => true,
        ]);

        $product = Product::factory()->create(['store_id' => $this->store->id]);

        // No auto-created listing for marketplace routes — create one
        $listing = PlatformListing::factory()->create([
            'product_id' => $product->id,
            'store_marketplace_id' => $marketplace->id,
            'sales_channel_id' => null,
            'should_list' => true,
        ]);

        // Toggle off
        $response = $this->withStore()
            ->postJson("/products/{$product->id}/listings/{$marketplace->id}/toggle-should-list");

        $response->assertOk();
        $response->assertJson(['success' => true, 'should_list' => false]);
        $this->assertFalse($listing->fresh()->should_list);
    }

    public function test_publish_blocked_when_should_list_false_on_channel(): void
    {
        $this->actingAs($this->user);

        $channel = SalesChannel::factory()->create([
            'store_id' => $this->store->id,
            'is_active' => true,
        ]);

        $product = Product::factory()->create(['store_id' => $this->store->id]);

        // Find auto-created listing and set should_list=false
        $listing = PlatformListing::where('product_id', $product->id)
            ->where('sales_channel_id', $channel->id)
            ->firstOrFail();

        $listing->update(['should_list' => false]);

        $response = $this->withStore()
            ->postJson("/products/{$product->id}/channels/{$channel->id}/publish");

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
        $response->assertJsonFragment(['message' => "This product is excluded from {$channel->name}. Toggle 'Should List' to enable publishing."]);
    }

    public function test_publish_blocked_when_should_list_false_on_marketplace(): void
    {
        $this->actingAs($this->user);

        $marketplace = StoreMarketplace::factory()->ebay()->create([
            'store_id' => $this->store->id,
            'connected_successfully' => true,
        ]);

        $product = Product::factory()->create(['store_id' => $this->store->id]);

        PlatformListing::factory()->create([
            'product_id' => $product->id,
            'store_marketplace_id' => $marketplace->id,
            'sales_channel_id' => null,
            'should_list' => false,
        ]);

        $response = $this->withStore()
            ->postJson("/products/{$product->id}/listings/{$marketplace->id}/publish");

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
    }

    public function test_ensure_listing_exists_respects_should_list(): void
    {
        $channel = SalesChannel::factory()->create([
            'store_id' => $this->store->id,
            'is_active' => true,
            'auto_list' => true,
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
        ]);

        // Find auto-created listing and set should_list=false, status=not_listed
        $listing = PlatformListing::where('product_id', $product->id)
            ->where('sales_channel_id', $channel->id)
            ->firstOrFail();

        $listing->update([
            'should_list' => false,
            'status' => PlatformListing::STATUS_NOT_LISTED,
        ]);

        // ensureListingExists should NOT auto-list even though auto_list=true
        $result = $product->ensureListingExists($channel);

        $this->assertEquals(PlatformListing::STATUS_NOT_LISTED, $result->status);
        $this->assertFalse($result->should_list);
    }

    public function test_product_show_includes_should_list(): void
    {
        $this->actingAs($this->user);

        $channel = SalesChannel::factory()->create([
            'store_id' => $this->store->id,
            'is_active' => true,
        ]);

        $product = Product::factory()->create(['store_id' => $this->store->id]);

        // Find auto-created listing and set should_list=false
        PlatformListing::where('product_id', $product->id)
            ->where('sales_channel_id', $channel->id)
            ->update(['should_list' => false]);

        $response = $this->withStore()
            ->get("/products/{$product->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('platformListings', 1)
            ->where('platformListings.0.should_list', false)
        );
    }

    public function test_list_on_all_platforms_skips_excluded(): void
    {
        $includedChannel = SalesChannel::factory()->create([
            'store_id' => $this->store->id,
            'is_active' => true,
            'type' => 'marketplace',
        ]);

        $excludedChannel = SalesChannel::factory()->create([
            'store_id' => $this->store->id,
            'is_active' => true,
            'type' => 'marketplace',
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
        ]);

        // Mark the excluded channel's listing as should_list=false
        PlatformListing::where('product_id', $product->id)
            ->where('sales_channel_id', $excludedChannel->id)
            ->update(['should_list' => false]);

        // Reset all listings to not_listed so listOnAllPlatforms can list them
        PlatformListing::where('product_id', $product->id)
            ->update(['status' => PlatformListing::STATUS_NOT_LISTED]);

        $listings = $product->listOnAllPlatforms(respectShouldList: true);

        // Should only list on the included channel
        $listedChannelIds = collect($listings)->pluck('sales_channel_id')->toArray();
        $this->assertContains($includedChannel->id, $listedChannelIds);
        $this->assertNotContains($excludedChannel->id, $listedChannelIds);
    }
}
