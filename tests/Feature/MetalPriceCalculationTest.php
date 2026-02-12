<?php

namespace Tests\Feature;

use App\Models\MetalPrice;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class MetalPriceCalculationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_calc_spot_price_returns_correct_value_for_gold_14k(): void
    {
        MetalPrice::create([
            'metal_type' => 'gold',
            'purity' => null,
            'price_per_gram' => 60.00,
            'price_per_ounce' => 1866.00,
            'price_per_dwt' => 93.30,
            'currency' => 'USD',
            'source' => 'test',
            'effective_at' => now(),
        ]);

        $result = MetalPrice::calcSpotPrice('gold_14k', 5.0);

        $this->assertNotNull($result);
        // 93.30 * 0.5833 * 5.0 = 272.1
        $this->assertEqualsWithDelta(272.1, $result, 0.5);
    }

    public function test_calc_spot_price_returns_correct_value_for_silver(): void
    {
        MetalPrice::create([
            'metal_type' => 'silver',
            'purity' => null,
            'price_per_gram' => 0.75,
            'price_per_ounce' => 23.34,
            'price_per_dwt' => 1.17,
            'currency' => 'USD',
            'source' => 'test',
            'effective_at' => now(),
        ]);

        $result = MetalPrice::calcSpotPrice('silver', 10.0);

        $this->assertNotNull($result);
        // 1.17 * 0.925 * 10 = 10.82
        $this->assertEqualsWithDelta(10.82, $result, 0.1);
    }

    public function test_calc_spot_price_with_quantity(): void
    {
        MetalPrice::create([
            'metal_type' => 'gold',
            'purity' => null,
            'price_per_gram' => 60.00,
            'price_per_ounce' => 1866.00,
            'price_per_dwt' => 93.30,
            'currency' => 'USD',
            'source' => 'test',
            'effective_at' => now(),
        ]);

        $resultSingle = MetalPrice::calcSpotPrice('gold_14k', 5.0, 1);
        $resultDouble = MetalPrice::calcSpotPrice('gold_14k', 5.0, 2);

        $this->assertNotNull($resultSingle);
        $this->assertNotNull($resultDouble);
        $this->assertEqualsWithDelta($resultSingle * 2, $resultDouble, 0.01);
    }

    public function test_calc_spot_price_returns_null_when_no_price_data(): void
    {
        $result = MetalPrice::calcSpotPrice('gold_14k', 5.0);

        $this->assertNull($result);
    }

    public function test_calc_spot_price_returns_null_for_unknown_metal(): void
    {
        $result = MetalPrice::calcSpotPrice('unobtanium', 5.0);

        $this->assertNull($result);
    }

    public function test_api_endpoint_returns_spot_price(): void
    {
        Passport::actingAs($this->user);

        MetalPrice::create([
            'metal_type' => 'gold',
            'purity' => null,
            'price_per_gram' => 60.00,
            'price_per_ounce' => 1866.00,
            'price_per_dwt' => 93.30,
            'currency' => 'USD',
            'source' => 'test',
            'effective_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/metal-prices/calculate?precious_metal=gold_14k&dwt=5');

        $response->assertStatus(200)
            ->assertJsonStructure(['spot_price']);

        $this->assertNotNull($response->json('spot_price'));
    }

    public function test_api_endpoint_returns_null_spot_price_when_no_data(): void
    {
        Passport::actingAs($this->user);

        $response = $this->getJson('/api/v1/metal-prices/calculate?precious_metal=gold_14k&dwt=5');

        $response->assertStatus(200)
            ->assertJson(['spot_price' => null]);
    }

    public function test_api_endpoint_validates_required_fields(): void
    {
        Passport::actingAs($this->user);

        $response = $this->getJson('/api/v1/metal-prices/calculate');

        $response->assertStatus(422);
    }

    public function test_api_endpoint_validates_precious_metal_enum(): void
    {
        Passport::actingAs($this->user);

        $response = $this->getJson('/api/v1/metal-prices/calculate?precious_metal=unobtanium&dwt=5');

        $response->assertStatus(422);
    }

    public function test_calc_spot_price_applies_store_buy_percentage(): void
    {
        MetalPrice::create([
            'metal_type' => 'gold',
            'purity' => null,
            'price_per_gram' => 60.00,
            'price_per_ounce' => 1866.00,
            'price_per_dwt' => 93.30,
            'currency' => 'USD',
            'source' => 'test',
            'effective_at' => now(),
        ]);

        // Set store buy percentage to 80%
        $this->store->update([
            'metal_price_settings' => [
                'buy_percentages' => [
                    '14k' => 0.80,
                ],
            ],
        ]);

        $rawSpotPrice = MetalPrice::calcSpotPrice('14k', 5.0);
        $buyPrice = MetalPrice::calcSpotPrice('14k', 5.0, 1, $this->store);

        $this->assertNotNull($rawSpotPrice);
        $this->assertNotNull($buyPrice);
        // Buy price should be 80% of raw spot price
        $this->assertEqualsWithDelta($rawSpotPrice * 0.80, $buyPrice, 0.01);
    }

    public function test_calc_spot_price_uses_default_when_no_store_percentage(): void
    {
        MetalPrice::create([
            'metal_type' => 'gold',
            'purity' => null,
            'price_per_gram' => 60.00,
            'price_per_ounce' => 1866.00,
            'price_per_dwt' => 93.30,
            'currency' => 'USD',
            'source' => 'test',
            'effective_at' => now(),
        ]);

        // No buy percentages set - store has default settings
        $this->store->update(['metal_price_settings' => null]);

        $rawSpotPrice = MetalPrice::calcSpotPrice('14k', 5.0);
        $buyPriceWithStore = MetalPrice::calcSpotPrice('14k', 5.0, 1, $this->store);

        $this->assertNotNull($rawSpotPrice);
        $this->assertNotNull($buyPriceWithStore);
        // When no percentage is set, getMetalBuyPercentage returns 0.75 default
        $this->assertEqualsWithDelta($rawSpotPrice * 0.75, $buyPriceWithStore, 0.01);
    }

    public function test_calc_spot_price_returns_full_price_without_store(): void
    {
        MetalPrice::create([
            'metal_type' => 'gold',
            'purity' => null,
            'price_per_gram' => 60.00,
            'price_per_ounce' => 1866.00,
            'price_per_dwt' => 93.30,
            'currency' => 'USD',
            'source' => 'test',
            'effective_at' => now(),
        ]);

        $spotPrice = MetalPrice::calcSpotPrice('14k', 5.0);
        $spotPriceWithNullStore = MetalPrice::calcSpotPrice('14k', 5.0, 1, null);

        $this->assertNotNull($spotPrice);
        $this->assertNotNull($spotPriceWithNullStore);
        // Both should be the same (no store = full spot price)
        $this->assertEqualsWithDelta($spotPrice, $spotPriceWithNullStore, 0.01);
    }

    public function test_api_endpoint_returns_buy_price_with_store_id(): void
    {
        MetalPrice::create([
            'metal_type' => 'gold',
            'purity' => null,
            'price_per_gram' => 60.00,
            'price_per_ounce' => 1866.00,
            'price_per_dwt' => 93.30,
            'currency' => 'USD',
            'source' => 'test',
            'effective_at' => now(),
        ]);

        // Set store buy percentage to 70%
        $this->store->update([
            'metal_price_settings' => [
                'buy_percentages' => [
                    '14k' => 0.70,
                ],
            ],
        ]);

        $response = $this->getJson("/api/v1/metal-prices/calculate?precious_metal=14k&dwt=5&store_id={$this->store->id}");

        $response->assertStatus(200)
            ->assertJsonStructure(['spot_price', 'buy_price', 'buy_percentage']);

        $spotPrice = $response->json('spot_price');
        $buyPrice = $response->json('buy_price');
        $buyPercentage = $response->json('buy_percentage');

        $this->assertEquals(0.70, $buyPercentage);
        $this->assertEqualsWithDelta($spotPrice * 0.70, $buyPrice, 0.01);
    }

    public function test_api_endpoint_returns_same_prices_without_store_id(): void
    {
        MetalPrice::create([
            'metal_type' => 'gold',
            'purity' => null,
            'price_per_gram' => 60.00,
            'price_per_ounce' => 1866.00,
            'price_per_dwt' => 93.30,
            'currency' => 'USD',
            'source' => 'test',
            'effective_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/metal-prices/calculate?precious_metal=14k&dwt=5');

        $response->assertStatus(200);

        $spotPrice = $response->json('spot_price');
        $buyPrice = $response->json('buy_price');

        // Without store_id, spot_price and buy_price should be the same
        $this->assertEquals($spotPrice, $buyPrice);
    }
}
