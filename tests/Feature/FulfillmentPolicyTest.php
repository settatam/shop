<?php

namespace Tests\Feature;

use App\Models\FulfillmentPolicy;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FulfillmentPolicyTest extends TestCase
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

    public function test_fulfillment_policy_can_be_created(): void
    {
        $policy = FulfillmentPolicy::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Standard Shipping',
        ]);

        $this->assertDatabaseHas('fulfillment_policies', [
            'id' => $policy->id,
            'store_id' => $this->store->id,
            'name' => 'Standard Shipping',
        ]);
    }

    public function test_fulfillment_policy_has_correct_defaults(): void
    {
        $policy = FulfillmentPolicy::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $this->assertEquals(true, $policy->is_active);
        $this->assertEquals(false, $policy->is_default);
    }

    public function test_set_as_default_unsets_other_defaults(): void
    {
        $policy1 = FulfillmentPolicy::factory()->default()->create([
            'store_id' => $this->store->id,
        ]);

        $policy2 = FulfillmentPolicy::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $policy2->setAsDefault();

        $this->assertTrue($policy2->fresh()->is_default);
        $this->assertFalse($policy1->fresh()->is_default);
    }

    public function test_set_as_default_does_not_affect_other_stores(): void
    {
        $otherStore = Store::factory()->create();

        $otherPolicy = FulfillmentPolicy::factory()->default()->create([
            'store_id' => $otherStore->id,
        ]);

        $policy = FulfillmentPolicy::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $policy->setAsDefault();

        $this->assertTrue($otherPolicy->fresh()->is_default);
        $this->assertTrue($policy->fresh()->is_default);
    }

    public function test_scope_active_filters_correctly(): void
    {
        FulfillmentPolicy::factory()->create([
            'store_id' => $this->store->id,
            'is_active' => true,
        ]);

        FulfillmentPolicy::factory()->inactive()->create([
            'store_id' => $this->store->id,
        ]);

        $activePolicies = FulfillmentPolicy::active()->get();

        $this->assertCount(1, $activePolicies);
    }

    public function test_scope_default_filters_correctly(): void
    {
        FulfillmentPolicy::factory()->default()->create([
            'store_id' => $this->store->id,
        ]);

        FulfillmentPolicy::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $defaultPolicies = FulfillmentPolicy::default()->get();

        $this->assertCount(1, $defaultPolicies);
    }

    public function test_fulfillment_policy_has_products_relationship(): void
    {
        $policy = FulfillmentPolicy::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $policy->products());
    }

    public function test_product_can_have_fulfillment_policy(): void
    {
        $policy = FulfillmentPolicy::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'fulfillment_policy_id' => $policy->id,
        ]);

        $this->assertEquals($policy->id, $product->fulfillment_policy_id);
        $this->assertEquals($policy->id, $product->fulfillmentPolicy->id);
    }

    public function test_product_fulfillment_policy_nullable(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'fulfillment_policy_id' => null,
        ]);

        $this->assertNull($product->fulfillment_policy_id);
        $this->assertNull($product->fulfillmentPolicy);
    }

    public function test_deleting_fulfillment_policy_nullifies_product_fk(): void
    {
        $policy = FulfillmentPolicy::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'fulfillment_policy_id' => $policy->id,
        ]);

        $policy->delete();

        $this->assertNull($product->fresh()->fulfillment_policy_id);
    }

    public function test_fulfillment_policy_belongs_to_store(): void
    {
        $policy = FulfillmentPolicy::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $this->assertEquals($this->store->id, $policy->store->id);
    }

    public function test_free_shipping_factory_state(): void
    {
        $policy = FulfillmentPolicy::factory()->freeShipping()->create([
            'store_id' => $this->store->id,
        ]);

        $this->assertTrue($policy->free_shipping);
        $this->assertEquals(0, (float) $policy->domestic_shipping_cost);
    }

    public function test_fulfillment_policy_casts_are_correct(): void
    {
        $policy = FulfillmentPolicy::factory()->create([
            'store_id' => $this->store->id,
            'handling_time_value' => 3,
            'free_shipping' => true,
            'is_default' => false,
            'is_active' => true,
        ]);

        $this->assertIsInt($policy->handling_time_value);
        $this->assertIsBool($policy->free_shipping);
        $this->assertIsBool($policy->is_default);
        $this->assertIsBool($policy->is_active);
    }
}
