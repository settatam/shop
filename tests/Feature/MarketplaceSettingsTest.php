<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Models\StoreUser;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MarketplaceSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected StoreMarketplace $marketplace;

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

        $this->marketplace = StoreMarketplace::factory()->ebay()->create([
            'store_id' => $this->store->id,
            'is_app' => false,
            'connected_successfully' => true,
            'settings' => [],
        ]);
    }

    protected function withStore()
    {
        return $this->withSession(['current_store_id' => $this->store->id]);
    }

    public function test_settings_page_renders_for_authenticated_user(): void
    {
        $this->actingAs($this->user);

        $response = $this->withStore()
            ->get("/settings/marketplaces/{$this->marketplace->id}/settings");

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/MarketplaceSettings')
            ->has('marketplace')
            ->has('warehouses')
            ->where('marketplace.id', $this->marketplace->id)
            ->where('marketplace.platform', 'ebay')
        );
    }

    public function test_unauthenticated_user_cannot_access_settings(): void
    {
        $response = $this->get("/settings/marketplaces/{$this->marketplace->id}/settings");

        $response->assertRedirect();
    }

    public function test_user_cannot_access_other_store_marketplace_settings(): void
    {
        $otherUser = User::factory()->create();
        $otherStore = Store::factory()->create(['user_id' => $otherUser->id]);
        $otherMarketplace = StoreMarketplace::factory()->ebay()->create([
            'store_id' => $otherStore->id,
        ]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->get("/settings/marketplaces/{$otherMarketplace->id}/settings");

        $response->assertStatus(403);
    }

    public function test_update_settings_saves_to_database(): void
    {
        $this->actingAs($this->user);

        $response = $this->withStore()
            ->put("/settings/marketplaces/{$this->marketplace->id}/settings", [
                'marketplace_id' => 'EBAY_GB',
                'default_condition' => 'USED_EXCELLENT',
                'listing_type' => 'FIXED_PRICE',
                'listing_duration_fixed' => 'GTC',
                'listing_duration_auction' => 'DAYS_7',
                'return_policy_id' => 'return-123',
                'payment_policy_id' => 'payment-456',
                'fulfillment_policy_id' => 'fulfillment-789',
                'auction_markup' => 15.5,
                'fixed_price_markup' => 10.0,
                'best_offer_enabled' => true,
                'use_ai_details' => false,
                'location_key' => 'warehouse-1',
            ]);

        $response->assertRedirect();

        $this->marketplace->refresh();
        $settings = $this->marketplace->settings;

        $this->assertEquals('EBAY_GB', $settings['marketplace_id']);
        $this->assertEquals('USED_EXCELLENT', $settings['default_condition']);
        $this->assertEquals('FIXED_PRICE', $settings['listing_type']);
        $this->assertEquals('GTC', $settings['listing_duration_fixed']);
        $this->assertEquals('return-123', $settings['return_policy_id']);
        $this->assertEquals('payment-456', $settings['payment_policy_id']);
        $this->assertEquals('fulfillment-789', $settings['fulfillment_policy_id']);
        $this->assertEquals(15.5, $settings['auction_markup']);
        $this->assertEquals(10.0, $settings['fixed_price_markup']);
        $this->assertTrue($settings['best_offer_enabled']);
        $this->assertFalse($settings['use_ai_details']);
        $this->assertEquals('warehouse-1', $settings['location_key']);
    }

    public function test_update_settings_validates_listing_type(): void
    {
        $this->actingAs($this->user);

        $response = $this->withStore()
            ->put("/settings/marketplaces/{$this->marketplace->id}/settings", [
                'listing_type' => 'INVALID_TYPE',
            ]);

        $response->assertSessionHasErrors('listing_type');
    }

    public function test_update_settings_validates_markup_range(): void
    {
        $this->actingAs($this->user);

        $response = $this->withStore()
            ->put("/settings/marketplaces/{$this->marketplace->id}/settings", [
                'auction_markup' => -200,
            ]);

        $response->assertSessionHasErrors('auction_markup');
    }

    public function test_update_preserves_existing_settings(): void
    {
        $this->marketplace->update([
            'settings' => [
                'marketplace_id' => 'EBAY_US',
                'custom_field' => 'should_stay',
            ],
        ]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->put("/settings/marketplaces/{$this->marketplace->id}/settings", [
                'listing_type' => 'AUCTION',
            ]);

        $response->assertRedirect();

        $this->marketplace->refresh();
        $settings = $this->marketplace->settings;

        $this->assertEquals('AUCTION', $settings['listing_type']);
        $this->assertEquals('should_stay', $settings['custom_field']);
    }

    public function test_settings_page_includes_warehouses(): void
    {
        Warehouse::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Main Warehouse',
            'is_active' => true,
        ]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->get("/settings/marketplaces/{$this->marketplace->id}/settings");

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->has('warehouses', 1)
        );
    }

    public function test_update_settings_with_location_mappings(): void
    {
        $warehouse = Warehouse::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Main Warehouse',
            'is_active' => true,
        ]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->put("/settings/marketplaces/{$this->marketplace->id}/settings", [
                'location_mappings' => [
                    ['warehouse_id' => $warehouse->id, 'location_key' => 'ebay-loc-1'],
                ],
            ]);

        $response->assertRedirect();

        $this->marketplace->refresh();
        $settings = $this->marketplace->settings;

        $this->assertCount(1, $settings['location_mappings']);
        $this->assertEquals($warehouse->id, $settings['location_mappings'][0]['warehouse_id']);
        $this->assertEquals('ebay-loc-1', $settings['location_mappings'][0]['location_key']);
    }

    // --- Amazon Settings Tests ---

    public function test_amazon_settings_page_renders(): void
    {
        $amazonMarketplace = StoreMarketplace::factory()->amazon()->create([
            'store_id' => $this->store->id,
            'is_app' => false,
            'connected_successfully' => true,
            'settings' => [],
        ]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->get("/settings/marketplaces/{$amazonMarketplace->id}/settings");

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/MarketplaceSettings')
            ->where('marketplace.platform', 'amazon')
        );
    }

    public function test_amazon_settings_save_to_database(): void
    {
        $amazonMarketplace = StoreMarketplace::factory()->amazon()->create([
            'store_id' => $this->store->id,
            'is_app' => false,
            'connected_successfully' => true,
            'settings' => [],
        ]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->put("/settings/marketplaces/{$amazonMarketplace->id}/settings", [
                'marketplace_id' => 'A1F83G8C2ARO7P',
                'fulfillment_channel' => 'AFN',
                'language_tag' => 'en_GB',
                'price_markup' => 12.5,
                'use_ai_details' => true,
            ]);

        $response->assertRedirect();

        $amazonMarketplace->refresh();
        $settings = $amazonMarketplace->settings;

        $this->assertEquals('A1F83G8C2ARO7P', $settings['marketplace_id']);
        $this->assertEquals('AFN', $settings['fulfillment_channel']);
        $this->assertEquals('en_GB', $settings['language_tag']);
        $this->assertEquals(12.5, $settings['price_markup']);
        $this->assertTrue($settings['use_ai_details']);
    }

    public function test_amazon_settings_validates_fulfillment_channel(): void
    {
        $amazonMarketplace = StoreMarketplace::factory()->amazon()->create([
            'store_id' => $this->store->id,
            'is_app' => false,
            'connected_successfully' => true,
            'settings' => [],
        ]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->put("/settings/marketplaces/{$amazonMarketplace->id}/settings", [
                'fulfillment_channel' => 'INVALID',
            ]);

        $response->assertSessionHasErrors('fulfillment_channel');
    }

    // --- Etsy Settings Tests ---

    public function test_etsy_settings_page_renders(): void
    {
        $etsyMarketplace = StoreMarketplace::factory()->etsy()->create([
            'store_id' => $this->store->id,
            'is_app' => false,
            'connected_successfully' => true,
            'settings' => [],
        ]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->get("/settings/marketplaces/{$etsyMarketplace->id}/settings");

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/MarketplaceSettings')
            ->where('marketplace.platform', 'etsy')
        );
    }

    public function test_etsy_settings_save_to_database(): void
    {
        $etsyMarketplace = StoreMarketplace::factory()->etsy()->create([
            'store_id' => $this->store->id,
            'is_app' => false,
            'connected_successfully' => true,
            'settings' => [],
        ]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->put("/settings/marketplaces/{$etsyMarketplace->id}/settings", [
                'currency' => 'GBP',
                'who_made' => 'someone_else',
                'when_made' => '2010_2019',
                'is_supply' => true,
                'shipping_profile_id' => 'sp-123',
                'return_policy_id' => 'rp-456',
                'auto_renew' => true,
                'price_markup' => 5.0,
            ]);

        $response->assertRedirect();

        $etsyMarketplace->refresh();
        $settings = $etsyMarketplace->settings;

        $this->assertEquals('GBP', $settings['currency']);
        $this->assertEquals('someone_else', $settings['who_made']);
        $this->assertEquals('2010_2019', $settings['when_made']);
        $this->assertTrue($settings['is_supply']);
        $this->assertEquals('sp-123', $settings['shipping_profile_id']);
        $this->assertEquals('rp-456', $settings['return_policy_id']);
        $this->assertTrue($settings['auto_renew']);
        $this->assertEquals(5.0, $settings['price_markup']);
    }

    public function test_etsy_settings_validates_who_made(): void
    {
        $etsyMarketplace = StoreMarketplace::factory()->etsy()->create([
            'store_id' => $this->store->id,
            'is_app' => false,
            'connected_successfully' => true,
            'settings' => [],
        ]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->put("/settings/marketplaces/{$etsyMarketplace->id}/settings", [
                'who_made' => 'invalid_option',
            ]);

        $response->assertSessionHasErrors('who_made');
    }

    // --- Walmart Settings Tests ---

    public function test_walmart_settings_page_renders(): void
    {
        $walmartMarketplace = StoreMarketplace::factory()->walmart()->create([
            'store_id' => $this->store->id,
            'is_app' => false,
            'connected_successfully' => true,
            'settings' => [],
        ]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->get("/settings/marketplaces/{$walmartMarketplace->id}/settings");

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/MarketplaceSettings')
            ->where('marketplace.platform', 'walmart')
        );
    }

    public function test_walmart_settings_save_to_database(): void
    {
        $walmartMarketplace = StoreMarketplace::factory()->walmart()->create([
            'store_id' => $this->store->id,
            'is_app' => false,
            'connected_successfully' => true,
            'settings' => [],
        ]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->put("/settings/marketplaces/{$walmartMarketplace->id}/settings", [
                'product_id_type' => 'GTIN',
                'fulfillment_type' => 'wfs',
                'shipping_method' => 'EXPEDITED',
                'weight_unit' => 'KG',
                'price_markup' => 8.0,
            ]);

        $response->assertRedirect();

        $walmartMarketplace->refresh();
        $settings = $walmartMarketplace->settings;

        $this->assertEquals('GTIN', $settings['product_id_type']);
        $this->assertEquals('wfs', $settings['fulfillment_type']);
        $this->assertEquals('EXPEDITED', $settings['shipping_method']);
        $this->assertEquals('KG', $settings['weight_unit']);
        $this->assertEquals(8.0, $settings['price_markup']);
    }

    public function test_walmart_settings_validates_product_id_type(): void
    {
        $walmartMarketplace = StoreMarketplace::factory()->walmart()->create([
            'store_id' => $this->store->id,
            'is_app' => false,
            'connected_successfully' => true,
            'settings' => [],
        ]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->put("/settings/marketplaces/{$walmartMarketplace->id}/settings", [
                'product_id_type' => 'INVALID',
            ]);

        $response->assertSessionHasErrors('product_id_type');
    }

    // --- Shopify Settings Tests ---

    public function test_shopify_settings_page_renders(): void
    {
        $shopifyMarketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $this->store->id,
            'is_app' => false,
            'connected_successfully' => true,
            'settings' => [],
        ]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->get("/settings/marketplaces/{$shopifyMarketplace->id}/settings");

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/MarketplaceSettings')
            ->where('marketplace.platform', 'shopify')
        );
    }

    public function test_shopify_settings_save_to_database(): void
    {
        $shopifyMarketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $this->store->id,
            'is_app' => false,
            'connected_successfully' => true,
            'settings' => [],
        ]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->put("/settings/marketplaces/{$shopifyMarketplace->id}/settings", [
                'default_product_status' => 'draft',
                'inventory_tracking' => 'not_managed',
                'price_markup' => 15.0,
                'use_ai_details' => true,
            ]);

        $response->assertRedirect();

        $shopifyMarketplace->refresh();
        $settings = $shopifyMarketplace->settings;

        $this->assertEquals('draft', $settings['default_product_status']);
        $this->assertEquals('not_managed', $settings['inventory_tracking']);
        $this->assertEquals(15.0, $settings['price_markup']);
        $this->assertTrue($settings['use_ai_details']);
    }

    public function test_shopify_settings_validates_product_status(): void
    {
        $shopifyMarketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $this->store->id,
            'is_app' => false,
            'connected_successfully' => true,
            'settings' => [],
        ]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->put("/settings/marketplaces/{$shopifyMarketplace->id}/settings", [
                'default_product_status' => 'archived',
            ]);

        $response->assertSessionHasErrors('default_product_status');
    }

    // --- Common field tests ---

    public function test_price_markup_validates_range(): void
    {
        $this->actingAs($this->user);

        $response = $this->withStore()
            ->put("/settings/marketplaces/{$this->marketplace->id}/settings", [
                'price_markup' => -200,
            ]);

        $response->assertSessionHasErrors('price_markup');
    }

    public function test_price_markup_saves_for_any_platform(): void
    {
        $shopifyMarketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $this->store->id,
            'is_app' => false,
            'connected_successfully' => true,
            'settings' => [],
        ]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->put("/settings/marketplaces/{$shopifyMarketplace->id}/settings", [
                'price_markup' => 25.5,
            ]);

        $response->assertRedirect();

        $shopifyMarketplace->refresh();
        $this->assertEquals(25.5, $shopifyMarketplace->settings['price_markup']);
    }
}
