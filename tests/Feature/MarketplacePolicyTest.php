<?php

namespace Tests\Feature;

use App\Jobs\CreateSalesChannelListingsJob;
use App\Models\MarketplacePolicy;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MarketplacePolicyTest extends TestCase
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
        ]);
    }

    public function test_sync_from_ebay_creates_policies(): void
    {
        $policies = [
            'return_policies' => [
                [
                    'returnPolicyId' => 'rp-001',
                    'name' => 'Standard Returns',
                    'description' => '30 day returns',
                    'returnsAccepted' => true,
                ],
                [
                    'returnPolicyId' => 'rp-002',
                    'name' => 'No Returns',
                    'description' => null,
                    'returnsAccepted' => false,
                ],
            ],
            'payment_policies' => [
                [
                    'paymentPolicyId' => 'pp-001',
                    'name' => 'Immediate Pay',
                    'description' => 'Pay now',
                    'immediatePay' => true,
                ],
            ],
            'fulfillment_policies' => [
                [
                    'fulfillmentPolicyId' => 'fp-001',
                    'name' => 'Standard Shipping',
                    'description' => '3-5 business days',
                ],
            ],
        ];

        MarketplacePolicy::syncFromEbay($this->marketplace, $policies);

        $this->assertDatabaseCount('marketplace_policies', 4);

        $this->assertDatabaseHas('marketplace_policies', [
            'store_marketplace_id' => $this->marketplace->id,
            'type' => MarketplacePolicy::TYPE_RETURN,
            'external_id' => 'rp-001',
            'name' => 'Standard Returns',
        ]);

        $this->assertDatabaseHas('marketplace_policies', [
            'store_marketplace_id' => $this->marketplace->id,
            'type' => MarketplacePolicy::TYPE_PAYMENT,
            'external_id' => 'pp-001',
            'name' => 'Immediate Pay',
        ]);

        $this->assertDatabaseHas('marketplace_policies', [
            'store_marketplace_id' => $this->marketplace->id,
            'type' => MarketplacePolicy::TYPE_FULFILLMENT,
            'external_id' => 'fp-001',
            'name' => 'Standard Shipping',
        ]);
    }

    public function test_sync_from_ebay_updates_existing_policies(): void
    {
        MarketplacePolicy::withoutGlobalScopes()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
            'type' => MarketplacePolicy::TYPE_RETURN,
            'external_id' => 'rp-001',
            'name' => 'Old Name',
        ]);

        $policies = [
            'return_policies' => [
                [
                    'returnPolicyId' => 'rp-001',
                    'name' => 'Updated Name',
                    'description' => 'Updated description',
                ],
            ],
            'payment_policies' => [],
            'fulfillment_policies' => [],
        ];

        MarketplacePolicy::syncFromEbay($this->marketplace, $policies);

        $policy = MarketplacePolicy::withoutGlobalScopes()
            ->where('external_id', 'rp-001')
            ->first();

        $this->assertEquals('Updated Name', $policy->name);
        $this->assertEquals('Updated description', $policy->description);
    }

    public function test_sync_from_ebay_removes_stale_policies(): void
    {
        MarketplacePolicy::withoutGlobalScopes()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
            'type' => MarketplacePolicy::TYPE_RETURN,
            'external_id' => 'rp-old',
            'name' => 'Old Policy',
        ]);

        $policies = [
            'return_policies' => [
                [
                    'returnPolicyId' => 'rp-new',
                    'name' => 'New Policy',
                ],
            ],
            'payment_policies' => [],
            'fulfillment_policies' => [],
        ];

        MarketplacePolicy::syncFromEbay($this->marketplace, $policies);

        $this->assertDatabaseMissing('marketplace_policies', [
            'external_id' => 'rp-old',
        ]);

        $this->assertDatabaseHas('marketplace_policies', [
            'external_id' => 'rp-new',
            'name' => 'New Policy',
        ]);
    }

    public function test_sync_is_idempotent(): void
    {
        $policies = [
            'return_policies' => [
                ['returnPolicyId' => 'rp-001', 'name' => 'Returns'],
            ],
            'payment_policies' => [
                ['paymentPolicyId' => 'pp-001', 'name' => 'Payment'],
            ],
            'fulfillment_policies' => [
                ['fulfillmentPolicyId' => 'fp-001', 'name' => 'Fulfillment'],
            ],
        ];

        MarketplacePolicy::syncFromEbay($this->marketplace, $policies);
        $count1 = MarketplacePolicy::withoutGlobalScopes()->count();

        MarketplacePolicy::syncFromEbay($this->marketplace, $policies);
        $count2 = MarketplacePolicy::withoutGlobalScopes()->count();

        $this->assertEquals($count1, $count2);
        $this->assertEquals(3, $count2);
    }

    public function test_set_as_default_unsets_other_defaults_of_same_type(): void
    {
        $policy1 = MarketplacePolicy::withoutGlobalScopes()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
            'type' => MarketplacePolicy::TYPE_RETURN,
            'external_id' => 'rp-001',
            'name' => 'Policy 1',
            'is_default' => true,
        ]);

        $policy2 = MarketplacePolicy::withoutGlobalScopes()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
            'type' => MarketplacePolicy::TYPE_RETURN,
            'external_id' => 'rp-002',
            'name' => 'Policy 2',
            'is_default' => false,
        ]);

        // A payment policy default should not be affected
        $paymentPolicy = MarketplacePolicy::withoutGlobalScopes()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
            'type' => MarketplacePolicy::TYPE_PAYMENT,
            'external_id' => 'pp-001',
            'name' => 'Payment Policy',
            'is_default' => true,
        ]);

        $policy2->setAsDefault();

        $policy1->refresh();
        $policy2->refresh();
        $paymentPolicy->refresh();

        $this->assertFalse($policy1->is_default);
        $this->assertTrue($policy2->is_default);
        $this->assertTrue($paymentPolicy->is_default); // Unaffected
    }

    public function test_store_marketplace_has_policies_relationship(): void
    {
        MarketplacePolicy::withoutGlobalScopes()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
            'type' => MarketplacePolicy::TYPE_RETURN,
            'external_id' => 'rp-001',
            'name' => 'Test Policy',
        ]);

        $policies = $this->marketplace->policies;

        $this->assertCount(1, $policies);
        $this->assertEquals('Test Policy', $policies->first()->name);
    }

    public function test_policy_scopes_filter_by_type(): void
    {
        MarketplacePolicy::withoutGlobalScopes()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
            'type' => MarketplacePolicy::TYPE_RETURN,
            'external_id' => 'rp-001',
            'name' => 'Return',
        ]);

        MarketplacePolicy::withoutGlobalScopes()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
            'type' => MarketplacePolicy::TYPE_PAYMENT,
            'external_id' => 'pp-001',
            'name' => 'Payment',
        ]);

        MarketplacePolicy::withoutGlobalScopes()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
            'type' => MarketplacePolicy::TYPE_FULFILLMENT,
            'external_id' => 'fp-001',
            'name' => 'Fulfillment',
        ]);

        $this->assertCount(1, MarketplacePolicy::withoutGlobalScopes()->return()->get());
        $this->assertCount(1, MarketplacePolicy::withoutGlobalScopes()->payment()->get());
        $this->assertCount(1, MarketplacePolicy::withoutGlobalScopes()->fulfillment()->get());
    }

    public function test_sync_stores_full_policy_details(): void
    {
        $policies = [
            'return_policies' => [
                [
                    'returnPolicyId' => 'rp-001',
                    'name' => 'Standard Returns',
                    'description' => '30 day returns',
                    'returnsAccepted' => true,
                    'returnPeriod' => ['value' => 30, 'unit' => 'DAY'],
                    'refundMethod' => 'MONEY_BACK',
                ],
            ],
            'payment_policies' => [],
            'fulfillment_policies' => [],
        ];

        MarketplacePolicy::syncFromEbay($this->marketplace, $policies);

        $policy = MarketplacePolicy::withoutGlobalScopes()
            ->where('external_id', 'rp-001')
            ->first();

        $this->assertIsArray($policy->details);
        $this->assertEquals('MONEY_BACK', $policy->details['refundMethod']);
        $this->assertTrue($policy->details['returnsAccepted']);
    }

    public function test_sync_does_not_affect_other_marketplaces(): void
    {
        $otherMarketplace = StoreMarketplace::factory()->ebay()->create([
            'store_id' => $this->store->id,
        ]);

        MarketplacePolicy::withoutGlobalScopes()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $otherMarketplace->id,
            'type' => MarketplacePolicy::TYPE_RETURN,
            'external_id' => 'rp-other',
            'name' => 'Other Policy',
        ]);

        $policies = [
            'return_policies' => [
                ['returnPolicyId' => 'rp-001', 'name' => 'This Marketplace'],
            ],
            'payment_policies' => [],
            'fulfillment_policies' => [],
        ];

        MarketplacePolicy::syncFromEbay($this->marketplace, $policies);

        // Other marketplace's policy should remain
        $this->assertDatabaseHas('marketplace_policies', [
            'store_marketplace_id' => $otherMarketplace->id,
            'external_id' => 'rp-other',
        ]);
    }

    public function test_create_listings_endpoint_dispatches_job(): void
    {
        Queue::fake();

        $this->actingAs($this->user);

        $channel = SalesChannel::create([
            'store_id' => $this->store->id,
            'name' => 'eBay Channel',
            'type' => 'ebay',
            'is_local' => false,
            'store_marketplace_id' => $this->marketplace->id,
            'is_active' => true,
        ]);

        $response = $this->withSession(['current_store_id' => $this->store->id])
            ->postJson("/settings/marketplaces/{$this->marketplace->id}/create-listings");

        $response->assertOk()
            ->assertJson(['message' => 'Listing creation has been queued. This may take a few minutes.']);

        Queue::assertPushed(CreateSalesChannelListingsJob::class, function ($job) use ($channel) {
            return $job->channel->id === $channel->id;
        });
    }

    public function test_create_listings_returns_error_without_sales_channel(): void
    {
        $this->actingAs($this->user);

        $response = $this->withSession(['current_store_id' => $this->store->id])
            ->postJson("/settings/marketplaces/{$this->marketplace->id}/create-listings");

        $response->assertStatus(422)
            ->assertJson(['error' => 'No sales channel found for this marketplace. Please reconnect.']);
    }

    public function test_show_includes_listing_count_and_sales_channel_flag(): void
    {
        $this->actingAs($this->user);

        SalesChannel::create([
            'store_id' => $this->store->id,
            'name' => 'eBay Channel',
            'type' => 'ebay',
            'is_local' => false,
            'store_marketplace_id' => $this->marketplace->id,
            'is_active' => true,
        ]);

        $response = $this->withSession(['current_store_id' => $this->store->id])
            ->get("/settings/marketplaces/{$this->marketplace->id}/settings");

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/MarketplaceSettings')
                ->has('listingCount')
                ->where('hasSalesChannel', true)
            );
    }
}
