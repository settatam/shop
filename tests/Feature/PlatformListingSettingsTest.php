<?php

namespace Tests\Feature;

use App\Models\MarketplacePolicy;
use App\Models\PlatformListing;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformListingSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected StoreMarketplace $marketplace;

    protected Product $product;

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

        $this->marketplace = StoreMarketplace::factory()->ebay()->create([
            'store_id' => $this->store->id,
            'settings' => [
                'listing_type' => 'FIXED_PRICE',
                'fulfillment_policy_id' => 'mp_fulfill_123',
                'payment_policy_id' => 'mp_pay_123',
                'return_policy_id' => 'mp_return_123',
                'fixed_price_markup' => 15,
                'location_key' => 'warehouse_1',
                'best_offer_enabled' => false,
                'default_condition' => '3000',
            ],
        ]);

        $this->product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $this->product->id,
            'price' => 100.00,
            'quantity' => 10,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_get_effective_setting_returns_listing_override_when_set(): void
    {
        $listing = PlatformListing::factory()->create([
            'product_id' => $this->product->id,
            'store_marketplace_id' => $this->marketplace->id,
            'platform_settings' => [
                'listing_type' => 'AUCTION',
                'fulfillment_policy_id' => 'listing_fulfill_456',
            ],
        ]);

        $this->assertEquals('AUCTION', $listing->getEffectiveSetting('listing_type'));
        $this->assertEquals('listing_fulfill_456', $listing->getEffectiveSetting('fulfillment_policy_id'));
    }

    public function test_get_effective_setting_falls_back_to_marketplace_default(): void
    {
        $listing = PlatformListing::factory()->create([
            'product_id' => $this->product->id,
            'store_marketplace_id' => $this->marketplace->id,
            'platform_settings' => [],
        ]);

        $this->assertEquals('FIXED_PRICE', $listing->getEffectiveSetting('listing_type'));
        $this->assertEquals('mp_fulfill_123', $listing->getEffectiveSetting('fulfillment_policy_id'));
        $this->assertEquals(15, $listing->getEffectiveSetting('fixed_price_markup'));
    }

    public function test_get_effective_setting_returns_default_when_neither_set(): void
    {
        $marketplace = StoreMarketplace::factory()->ebay()->create([
            'store_id' => $this->store->id,
            'settings' => [],
        ]);

        $listing = PlatformListing::factory()->create([
            'product_id' => $this->product->id,
            'store_marketplace_id' => $marketplace->id,
            'platform_settings' => [],
        ]);

        $this->assertNull($listing->getEffectiveSetting('listing_type'));
        $this->assertEquals('FIXED_PRICE', $listing->getEffectiveSetting('listing_type', 'FIXED_PRICE'));
    }

    public function test_get_effective_settings_merges_correctly(): void
    {
        $listing = PlatformListing::factory()->create([
            'product_id' => $this->product->id,
            'store_marketplace_id' => $this->marketplace->id,
            'platform_settings' => [
                'listing_type' => 'AUCTION',
                'auction_markup' => 20,
            ],
        ]);

        $effective = $listing->getEffectiveSettings();

        // Listing override takes precedence
        $this->assertEquals('AUCTION', $effective['listing_type']);
        $this->assertEquals(20, $effective['auction_markup']);

        // Marketplace defaults preserved for non-overridden keys
        $this->assertEquals('mp_fulfill_123', $effective['fulfillment_policy_id']);
        $this->assertEquals('mp_pay_123', $effective['payment_policy_id']);
        $this->assertEquals(15, $effective['fixed_price_markup']);
    }

    public function test_is_setting_overridden_returns_correct_boolean(): void
    {
        $listing = PlatformListing::factory()->create([
            'product_id' => $this->product->id,
            'store_marketplace_id' => $this->marketplace->id,
            'platform_settings' => [
                'listing_type' => 'AUCTION',
                'best_offer_enabled' => null,
            ],
        ]);

        $this->assertTrue($listing->isSettingOverridden('listing_type'));
        $this->assertFalse($listing->isSettingOverridden('best_offer_enabled'));
        $this->assertFalse($listing->isSettingOverridden('fulfillment_policy_id'));
    }

    public function test_controller_sends_marketplace_settings_and_policies_for_ebay(): void
    {
        $this->actingAs($this->user);

        // Create policies
        MarketplacePolicy::factory()->return()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
            'external_id' => 'ret_1',
            'name' => 'Return Policy 1',
        ]);

        MarketplacePolicy::factory()->payment()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
            'external_id' => 'pay_1',
            'name' => 'Payment Policy 1',
        ]);

        MarketplacePolicy::factory()->fulfillment()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
            'external_id' => 'ful_1',
            'name' => 'Fulfillment Policy 1',
        ]);

        $response = $this->get("/products/{$this->product->id}/platforms/{$this->marketplace->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('products/platforms/Show')
            ->has('marketplaceSettings')
            ->where('marketplaceSettings.listing_type', 'FIXED_PRICE')
            ->where('marketplaceSettings.fixed_price_markup', 15)
            ->has('policies')
            ->has('policies.return', 1)
            ->has('policies.payment', 1)
            ->has('policies.fulfillment', 1)
            ->has('categoryMapping')
            ->has('calculatedPrice')
            ->has('warehouses')
        );
    }

    public function test_controller_sends_empty_settings_for_non_ebay(): void
    {
        $this->actingAs($this->user);

        $shopifyMarketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $this->store->id,
        ]);

        $response = $this->get("/products/{$this->product->id}/platforms/{$shopifyMarketplace->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('products/platforms/Show')
            ->where('marketplaceSettings', [])
            ->where('policies', [])
            ->where('categoryMapping', [])
            ->where('calculatedPrice', null)
            ->where('warehouses', [])
        );
    }

    public function test_saving_platform_settings_persists_listing_overrides(): void
    {
        $this->actingAs($this->user);

        // Ensure a listing exists
        $listing = PlatformListing::factory()->create([
            'product_id' => $this->product->id,
            'store_marketplace_id' => $this->marketplace->id,
            'platform_settings' => [],
        ]);

        $response = $this->putJson("/products/{$this->product->id}/platforms/{$this->marketplace->id}", [
            'platform_settings' => [
                'listing_type' => 'AUCTION',
                'fulfillment_policy_id' => 'override_ful_999',
                'best_offer_enabled' => true,
            ],
        ]);

        $response->assertOk();

        $listing->refresh();
        $this->assertEquals('AUCTION', $listing->platform_settings['listing_type']);
        $this->assertEquals('override_ful_999', $listing->platform_settings['fulfillment_policy_id']);
        $this->assertTrue($listing->platform_settings['best_offer_enabled']);
    }

    public function test_validation_passes_when_listing_has_policy_override(): void
    {
        $this->actingAs($this->user);

        // Create marketplace with NO policies
        $emptyMarketplace = StoreMarketplace::factory()->ebay()->create([
            'store_id' => $this->store->id,
            'settings' => [],
        ]);

        // Create listing with policy overrides
        PlatformListing::factory()->create([
            'product_id' => $this->product->id,
            'store_marketplace_id' => $emptyMarketplace->id,
            'platform_settings' => [
                'fulfillment_policy_id' => 'listing_ful_789',
                'payment_policy_id' => 'listing_pay_789',
                'return_policy_id' => 'listing_ret_789',
            ],
        ]);

        $response = $this->get("/products/{$this->product->id}/platforms/{$emptyMarketplace->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('preview.validation.warnings', fn ($warnings) => ! collect($warnings)->contains('eBay fulfillment policy not configured')
                && ! collect($warnings)->contains('eBay payment policy not configured')
                && ! collect($warnings)->contains('eBay return policy not configured')
            )
        );
    }

    public function test_validation_warns_when_no_policy_at_any_level(): void
    {
        $this->actingAs($this->user);

        // Marketplace with no policies and no listing overrides
        $emptyMarketplace = StoreMarketplace::factory()->ebay()->create([
            'store_id' => $this->store->id,
            'settings' => [],
        ]);

        $response = $this->get("/products/{$this->product->id}/platforms/{$emptyMarketplace->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('preview.validation.warnings', fn ($warnings) => collect($warnings)->contains('eBay fulfillment policy not configured')
                && collect($warnings)->contains('eBay payment policy not configured')
                && collect($warnings)->contains('eBay return policy not configured')
            )
        );
    }

    public function test_calculated_price_applies_markup(): void
    {
        $this->actingAs($this->user);

        $response = $this->get("/products/{$this->product->id}/platforms/{$this->marketplace->id}");

        $response->assertStatus(200);
        // Base price 100 * (1 + 15/100) = 115.00
        $response->assertInertia(fn ($page) => $page
            ->where('calculatedPrice', 115)
        );
    }
}
