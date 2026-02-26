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
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SalesChannelControllerTest extends TestCase
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
    }

    protected function withStore()
    {
        return $this->withSession(['current_store_id' => $this->store->id]);
    }

    public function test_index_returns_channels_with_connection_status(): void
    {
        $this->actingAs($this->user);

        // Delete any auto-created channels to start fresh
        SalesChannel::where('store_id', $this->store->id)->delete();

        // Create a connected marketplace (not an app)
        $connectedMarketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $this->store->id,
            'is_app' => false,
            'connected_successfully' => true,
        ]);

        // Create a disconnected marketplace (not an app)
        $disconnectedMarketplace = StoreMarketplace::factory()->ebay()->create([
            'store_id' => $this->store->id,
            'is_app' => false,
            'connected_successfully' => false,
        ]);

        // Create an app integration (should be filtered out)
        StoreMarketplace::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Some App',
            'is_app' => true,
            'connected_successfully' => true,
        ]);

        // Create sales channels
        SalesChannel::create([
            'store_id' => $this->store->id,
            'name' => 'Shopify Channel',
            'type' => 'shopify',
            'is_local' => false,
            'store_marketplace_id' => $connectedMarketplace->id,
            'is_active' => true,
        ]);

        SalesChannel::create([
            'store_id' => $this->store->id,
            'name' => 'eBay Channel',
            'type' => 'ebay',
            'is_local' => false,
            'store_marketplace_id' => $disconnectedMarketplace->id,
            'is_active' => true,
        ]);

        $response = $this->withStore()->get('/settings/channels');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/SalesChannels')
                ->has('channels', 2)
                ->where('channels.0.store_marketplace.connected_successfully', true)
                ->where('channels.1.store_marketplace.connected_successfully', false)
                // Marketplaces should only include non-app integrations
                ->has('marketplaces', 2)
            );
    }

    public function test_index_filters_out_app_integrations_from_marketplaces(): void
    {
        $this->actingAs($this->user);

        // Create a regular marketplace
        StoreMarketplace::factory()->shopify()->create([
            'store_id' => $this->store->id,
            'is_app' => false,
        ]);

        // Create an app integration (should be filtered out)
        StoreMarketplace::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Accounting App',
            'is_app' => true,
        ]);

        $response = $this->withStore()->get('/settings/channels');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/SalesChannels')
                // Only the non-app marketplace should appear
                ->has('marketplaces', 1)
                ->where('marketplaces.0.platform', 'shopify')
            );
    }

    public function test_index_includes_marketplace_connection_status(): void
    {
        $this->actingAs($this->user);

        StoreMarketplace::factory()->shopify()->create([
            'store_id' => $this->store->id,
            'is_app' => false,
            'connected_successfully' => true,
        ]);

        StoreMarketplace::factory()->ebay()->create([
            'store_id' => $this->store->id,
            'is_app' => false,
            'connected_successfully' => false,
        ]);

        $response = $this->withStore()->get('/settings/channels');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/SalesChannels')
                ->has('marketplaces', 2)
                ->has('marketplaces.0.connected_successfully')
                ->has('marketplaces.1.connected_successfully')
            );
    }

    public function test_index_returns_auto_list_for_channels(): void
    {
        $this->actingAs($this->user);

        // The default in-store channel should have auto_list = true
        $response = $this->withStore()->get('/settings/channels');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/SalesChannels')
                ->where('channels.0.auto_list', true)
            );
    }

    public function test_store_creates_channel_with_auto_list(): void
    {
        $this->actingAs($this->user);

        $this->withStore()->post('/settings/channels', [
            'name' => 'My eBay',
            'type' => 'ebay',
            'auto_list' => true,
        ]);

        $channel = SalesChannel::where('store_id', $this->store->id)
            ->where('name', 'My eBay')
            ->first();

        $this->assertNotNull($channel);
        $this->assertTrue($channel->auto_list);
    }

    public function test_store_creates_channel_without_auto_list(): void
    {
        $this->actingAs($this->user);

        $this->withStore()->post('/settings/channels', [
            'name' => 'My Shopify',
            'type' => 'shopify',
            'auto_list' => false,
        ]);

        $channel = SalesChannel::where('store_id', $this->store->id)
            ->where('name', 'My Shopify')
            ->first();

        $this->assertNotNull($channel);
        $this->assertFalse($channel->auto_list);
    }

    public function test_update_toggles_auto_list(): void
    {
        $this->actingAs($this->user);

        $channel = SalesChannel::create([
            'store_id' => $this->store->id,
            'name' => 'Local Store',
            'type' => 'local',
            'is_local' => true,
            'auto_list' => true,
            'is_active' => true,
        ]);

        $this->withStore()->put("/settings/channels/{$channel->id}", [
            'name' => 'Local Store',
            'auto_list' => false,
        ]);

        $channel->refresh();
        $this->assertFalse($channel->auto_list);
    }

    public function test_auto_list_channel_lists_products_on_creation(): void
    {
        $this->actingAs($this->user);

        // Create an active product
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
        ]);

        // Create a channel with auto_list enabled
        $channel = SalesChannel::create([
            'store_id' => $this->store->id,
            'name' => 'Auto List Channel',
            'type' => 'ebay',
            'is_local' => false,
            'auto_list' => true,
            'is_active' => true,
        ]);

        // Product should have a listed listing on this channel
        $listing = PlatformListing::where('product_id', $product->id)
            ->where('sales_channel_id', $channel->id)
            ->first();

        $this->assertNotNull($listing);
        $this->assertEquals(PlatformListing::STATUS_LISTED, $listing->status);
    }

    public function test_non_auto_list_channel_does_not_list_products_on_creation(): void
    {
        $this->actingAs($this->user);

        // Create an active product
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
        ]);

        // Create a channel without auto_list
        $channel = SalesChannel::create([
            'store_id' => $this->store->id,
            'name' => 'Manual Channel',
            'type' => 'ebay',
            'is_local' => false,
            'auto_list' => false,
            'is_active' => true,
        ]);

        // Product should NOT be auto-listed
        $listing = PlatformListing::where('product_id', $product->id)
            ->where('sales_channel_id', $channel->id)
            ->first();

        $this->assertNull($listing);
    }

    public function test_local_channel_defaults_auto_list_to_true(): void
    {
        $channel = SalesChannel::create([
            'store_id' => $this->store->id,
            'name' => 'New Local Store',
            'type' => 'local',
        ]);

        $this->assertTrue($channel->auto_list);
        $this->assertTrue($channel->is_local);
    }

    public function test_index_shows_local_channel_with_warehouse(): void
    {
        $this->actingAs($this->user);

        // Delete any auto-created channels to start fresh
        SalesChannel::where('store_id', $this->store->id)->delete();

        $warehouse = Warehouse::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Main Warehouse',
        ]);

        SalesChannel::create([
            'store_id' => $this->store->id,
            'name' => 'Local Store',
            'type' => 'local',
            'is_local' => true,
            'warehouse_id' => $warehouse->id,
            'is_active' => true,
        ]);

        $response = $this->withStore()->get('/settings/channels');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/SalesChannels')
                ->has('channels', 1)
                ->where('channels.0.is_local', true)
                ->where('channels.0.warehouse.name', 'Main Warehouse')
            );
    }
}
