<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\Platforms\Ebay\EbayAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class EbayAccountManagementTest extends TestCase
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
            'settings' => ['marketplace_id' => 'EBAY_US'],
        ]);
    }

    protected function withStore()
    {
        return $this->withSession(['current_store_id' => $this->store->id]);
    }

    protected function mockEbayAccountService(): EbayAccountService
    {
        $mock = Mockery::mock(EbayAccountService::class);
        $this->app->instance(EbayAccountService::class, $mock);

        return $mock;
    }

    // ──────────────────────────────────────────────────────────────
    //  Return Policies
    // ──────────────────────────────────────────────────────────────

    public function test_fetch_return_policies(): void
    {
        $mock = $this->mockEbayAccountService();
        $mock->shouldReceive('getReturnPolicies')
            ->once()
            ->andReturn([
                ['returnPolicyId' => 'rp-1', 'name' => 'Test Return Policy'],
            ]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->getJson("/settings/marketplaces/{$this->marketplace->id}/ebay/return-policies");

        $response->assertOk();
        $response->assertJsonFragment(['returnPolicyId' => 'rp-1']);
    }

    public function test_create_return_policy(): void
    {
        $mock = $this->mockEbayAccountService();
        $mock->shouldReceive('createReturnPolicy')
            ->once()
            ->andReturn(['returnPolicyId' => 'rp-new', 'name' => 'New Return Policy']);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->postJson("/settings/marketplaces/{$this->marketplace->id}/ebay/return-policies", [
                'name' => 'New Return Policy',
                'marketplaceId' => 'EBAY_US',
                'returnsAccepted' => true,
                'refundMethod' => 'MONEY_BACK',
                'returnShippingCostPayer' => 'BUYER',
            ]);

        $response->assertStatus(201);
    }

    public function test_update_return_policy(): void
    {
        $mock = $this->mockEbayAccountService();
        $mock->shouldReceive('updateReturnPolicy')
            ->once()
            ->andReturn(['returnPolicyId' => 'rp-1', 'name' => 'Updated']);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->putJson("/settings/marketplaces/{$this->marketplace->id}/ebay/return-policies/rp-1", [
                'name' => 'Updated',
            ]);

        $response->assertOk();
    }

    public function test_delete_return_policy(): void
    {
        $mock = $this->mockEbayAccountService();
        $mock->shouldReceive('deleteReturnPolicy')
            ->once()
            ->andReturn([]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->deleteJson("/settings/marketplaces/{$this->marketplace->id}/ebay/return-policies/rp-1");

        $response->assertOk();
    }

    // ──────────────────────────────────────────────────────────────
    //  Fulfillment Policies
    // ──────────────────────────────────────────────────────────────

    public function test_fetch_fulfillment_policies(): void
    {
        $mock = $this->mockEbayAccountService();
        $mock->shouldReceive('getFulfillmentPolicies')
            ->once()
            ->andReturn([
                ['fulfillmentPolicyId' => 'fp-1', 'name' => 'Standard Shipping'],
            ]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->getJson("/settings/marketplaces/{$this->marketplace->id}/ebay/fulfillment-policies");

        $response->assertOk();
        $response->assertJsonFragment(['fulfillmentPolicyId' => 'fp-1']);
    }

    public function test_create_fulfillment_policy(): void
    {
        $mock = $this->mockEbayAccountService();
        $mock->shouldReceive('createFulfillmentPolicy')
            ->once()
            ->andReturn(['fulfillmentPolicyId' => 'fp-new']);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->postJson("/settings/marketplaces/{$this->marketplace->id}/ebay/fulfillment-policies", [
                'name' => 'Fast Shipping',
                'marketplaceId' => 'EBAY_US',
            ]);

        $response->assertStatus(201);
    }

    public function test_delete_fulfillment_policy(): void
    {
        $mock = $this->mockEbayAccountService();
        $mock->shouldReceive('deleteFulfillmentPolicy')
            ->once()
            ->andReturn([]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->deleteJson("/settings/marketplaces/{$this->marketplace->id}/ebay/fulfillment-policies/fp-1");

        $response->assertOk();
    }

    // ──────────────────────────────────────────────────────────────
    //  Payment Policies
    // ──────────────────────────────────────────────────────────────

    public function test_fetch_payment_policies(): void
    {
        $mock = $this->mockEbayAccountService();
        $mock->shouldReceive('getPaymentPolicies')
            ->once()
            ->andReturn([
                ['paymentPolicyId' => 'pp-1', 'name' => 'Immediate Payment'],
            ]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->getJson("/settings/marketplaces/{$this->marketplace->id}/ebay/payment-policies");

        $response->assertOk();
        $response->assertJsonFragment(['paymentPolicyId' => 'pp-1']);
    }

    public function test_create_payment_policy(): void
    {
        $mock = $this->mockEbayAccountService();
        $mock->shouldReceive('createPaymentPolicy')
            ->once()
            ->andReturn(['paymentPolicyId' => 'pp-new']);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->postJson("/settings/marketplaces/{$this->marketplace->id}/ebay/payment-policies", [
                'name' => 'PayPal Payment',
                'marketplaceId' => 'EBAY_US',
            ]);

        $response->assertStatus(201);
    }

    public function test_delete_payment_policy(): void
    {
        $mock = $this->mockEbayAccountService();
        $mock->shouldReceive('deletePaymentPolicy')
            ->once()
            ->andReturn([]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->deleteJson("/settings/marketplaces/{$this->marketplace->id}/ebay/payment-policies/pp-1");

        $response->assertOk();
    }

    // ──────────────────────────────────────────────────────────────
    //  Locations
    // ──────────────────────────────────────────────────────────────

    public function test_fetch_locations(): void
    {
        $mock = $this->mockEbayAccountService();
        $mock->shouldReceive('getLocations')
            ->once()
            ->andReturn([
                ['merchantLocationKey' => 'loc-1', 'name' => 'Main Warehouse'],
            ]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->getJson("/settings/marketplaces/{$this->marketplace->id}/ebay/locations");

        $response->assertOk();
        $response->assertJsonFragment(['merchantLocationKey' => 'loc-1']);
    }

    public function test_create_location(): void
    {
        $mock = $this->mockEbayAccountService();
        $mock->shouldReceive('createLocation')
            ->once()
            ->andReturn([]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->postJson("/settings/marketplaces/{$this->marketplace->id}/ebay/locations", [
                'location_key' => 'warehouse-01',
                'name' => 'Main Warehouse',
                'location' => [
                    'address' => [
                        'city' => 'New York',
                        'postalCode' => '10001',
                        'country' => 'US',
                    ],
                ],
            ]);

        $response->assertStatus(201);
    }

    public function test_delete_location(): void
    {
        $mock = $this->mockEbayAccountService();
        $mock->shouldReceive('deleteLocation')
            ->once()
            ->andReturn([]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->deleteJson("/settings/marketplaces/{$this->marketplace->id}/ebay/locations/loc-1");

        $response->assertOk();
    }

    // ──────────────────────────────────────────────────────────────
    //  Privileges & Programs
    // ──────────────────────────────────────────────────────────────

    public function test_fetch_privileges(): void
    {
        $mock = $this->mockEbayAccountService();
        $mock->shouldReceive('getPrivileges')
            ->once()
            ->andReturn(['sellingLimit' => ['quantity' => 100]]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->getJson("/settings/marketplaces/{$this->marketplace->id}/ebay/privileges");

        $response->assertOk();
        $response->assertJsonFragment(['quantity' => 100]);
    }

    public function test_fetch_programs(): void
    {
        $mock = $this->mockEbayAccountService();
        $mock->shouldReceive('getOptedInPrograms')
            ->once()
            ->andReturn(['programs' => [['programType' => 'SELLING_POLICY_MANAGEMENT']]]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->getJson("/settings/marketplaces/{$this->marketplace->id}/ebay/programs");

        $response->assertOk();
    }

    public function test_opt_in_to_program(): void
    {
        $mock = $this->mockEbayAccountService();
        $mock->shouldReceive('optInToProgram')
            ->once()
            ->andReturn([]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->postJson("/settings/marketplaces/{$this->marketplace->id}/ebay/programs/opt-in", [
                'program_type' => 'SELLING_POLICY_MANAGEMENT',
            ]);

        $response->assertOk();
    }

    public function test_opt_out_of_program(): void
    {
        $mock = $this->mockEbayAccountService();
        $mock->shouldReceive('optOutOfProgram')
            ->once()
            ->andReturn([]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->postJson("/settings/marketplaces/{$this->marketplace->id}/ebay/programs/opt-out", [
                'program_type' => 'SELLING_POLICY_MANAGEMENT',
            ]);

        $response->assertOk();
    }

    // ──────────────────────────────────────────────────────────────
    //  Authorization
    // ──────────────────────────────────────────────────────────────

    public function test_wrong_store_returns_403(): void
    {
        $otherUser = User::factory()->create();
        $otherStore = Store::factory()->create(['user_id' => $otherUser->id]);
        $otherMarketplace = StoreMarketplace::factory()->ebay()->create([
            'store_id' => $otherStore->id,
        ]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->getJson("/settings/marketplaces/{$otherMarketplace->id}/ebay/return-policies");

        $response->assertStatus(403);
    }

    public function test_non_ebay_marketplace_returns_403(): void
    {
        $shopifyMarketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $this->store->id,
            'is_app' => false,
            'connected_successfully' => true,
        ]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->getJson("/settings/marketplaces/{$shopifyMarketplace->id}/ebay/return-policies");

        $response->assertStatus(403);
    }

    public function test_unauthenticated_user_is_redirected(): void
    {
        $response = $this->getJson("/settings/marketplaces/{$this->marketplace->id}/ebay/return-policies");

        $response->assertStatus(401);
    }

    // ──────────────────────────────────────────────────────────────
    //  Validation
    // ──────────────────────────────────────────────────────────────

    public function test_create_return_policy_validates_required_fields(): void
    {
        $this->actingAs($this->user);

        $response = $this->withStore()
            ->postJson("/settings/marketplaces/{$this->marketplace->id}/ebay/return-policies", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'marketplaceId', 'returnsAccepted']);
    }

    public function test_create_fulfillment_policy_validates_required_fields(): void
    {
        $this->actingAs($this->user);

        $response = $this->withStore()
            ->postJson("/settings/marketplaces/{$this->marketplace->id}/ebay/fulfillment-policies", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'marketplaceId']);
    }

    public function test_create_payment_policy_validates_required_fields(): void
    {
        $this->actingAs($this->user);

        $response = $this->withStore()
            ->postJson("/settings/marketplaces/{$this->marketplace->id}/ebay/payment-policies", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'marketplaceId']);
    }

    public function test_create_location_validates_required_fields(): void
    {
        $this->actingAs($this->user);

        $response = $this->withStore()
            ->postJson("/settings/marketplaces/{$this->marketplace->id}/ebay/locations", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['location_key', 'name', 'location']);
    }

    public function test_create_location_validates_location_key_format(): void
    {
        $this->actingAs($this->user);

        $response = $this->withStore()
            ->postJson("/settings/marketplaces/{$this->marketplace->id}/ebay/locations", [
                'location_key' => 'invalid key!',
                'name' => 'Test',
                'location' => [
                    'address' => [
                        'city' => 'NYC',
                        'postalCode' => '10001',
                        'country' => 'US',
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['location_key']);
    }

    public function test_opt_in_requires_program_type(): void
    {
        $this->actingAs($this->user);

        $response = $this->withStore()
            ->postJson("/settings/marketplaces/{$this->marketplace->id}/ebay/programs/opt-in", []);

        $response->assertStatus(422);
    }

    // ──────────────────────────────────────────────────────────────
    //  Error handling
    // ──────────────────────────────────────────────────────────────

    public function test_api_error_returns_422_with_message(): void
    {
        $mock = $this->mockEbayAccountService();
        $mock->shouldReceive('getReturnPolicies')
            ->once()
            ->andThrow(new \Exception('eBay API error: token expired'));

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->getJson("/settings/marketplaces/{$this->marketplace->id}/ebay/return-policies");

        $response->assertStatus(422);
        $response->assertJsonFragment(['error' => 'eBay API error: token expired']);
    }
}
