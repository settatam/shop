<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ReturnPolicy;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ReturnPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected User $user;

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

    public function test_can_list_return_policies(): void
    {
        Passport::actingAs($this->user);

        ReturnPolicy::factory()->count(3)->create(['store_id' => $this->store->id]);
        ReturnPolicy::factory()->create(); // Different store

        $response = $this->getJson('/api/v1/return-policies');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_can_create_return_policy(): void
    {
        Passport::actingAs($this->user);

        $data = [
            'name' => 'Standard Return Policy',
            'description' => 'Our standard 30-day return policy',
            'return_window_days' => 30,
            'allow_refund' => true,
            'allow_store_credit' => true,
            'allow_exchange' => true,
            'restocking_fee_percent' => 15,
            'require_receipt' => true,
        ];

        $response = $this->postJson('/api/v1/return-policies', $data);

        $response->assertStatus(201);
        $response->assertJsonFragment(['name' => 'Standard Return Policy']);

        $this->assertDatabaseHas('return_policies', [
            'store_id' => $this->store->id,
            'name' => 'Standard Return Policy',
            'return_window_days' => 30,
            'restocking_fee_percent' => 15,
        ]);
    }

    public function test_can_view_return_policy(): void
    {
        Passport::actingAs($this->user);

        $policy = ReturnPolicy::factory()->create(['store_id' => $this->store->id]);

        $response = $this->getJson("/api/v1/return-policies/{$policy->id}");

        $response->assertStatus(200);
        $response->assertJsonFragment(['id' => $policy->id]);
    }

    public function test_can_update_return_policy(): void
    {
        Passport::actingAs($this->user);

        $policy = ReturnPolicy::factory()->create(['store_id' => $this->store->id]);

        $response = $this->putJson("/api/v1/return-policies/{$policy->id}", [
            'name' => 'Updated Policy',
            'return_window_days' => 60,
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['name' => 'Updated Policy']);

        $this->assertDatabaseHas('return_policies', [
            'id' => $policy->id,
            'name' => 'Updated Policy',
            'return_window_days' => 60,
        ]);
    }

    public function test_can_delete_return_policy(): void
    {
        Passport::actingAs($this->user);

        $policy = ReturnPolicy::factory()->create(['store_id' => $this->store->id]);

        $response = $this->deleteJson("/api/v1/return-policies/{$policy->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('return_policies', ['id' => $policy->id]);
    }

    public function test_cannot_delete_default_policy(): void
    {
        Passport::actingAs($this->user);

        $policy = ReturnPolicy::factory()->default()->create(['store_id' => $this->store->id]);

        $response = $this->deleteJson("/api/v1/return-policies/{$policy->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('return_policies', ['id' => $policy->id]);
    }

    public function test_cannot_delete_policy_with_assigned_products(): void
    {
        Passport::actingAs($this->user);

        $policy = ReturnPolicy::factory()->create(['store_id' => $this->store->id]);
        Product::factory()->create([
            'store_id' => $this->store->id,
            'return_policy_id' => $policy->id,
        ]);

        $response = $this->deleteJson("/api/v1/return-policies/{$policy->id}");

        $response->assertStatus(422);
    }

    public function test_can_set_policy_as_default(): void
    {
        Passport::actingAs($this->user);

        $policy1 = ReturnPolicy::factory()->default()->create(['store_id' => $this->store->id]);
        $policy2 = ReturnPolicy::factory()->create(['store_id' => $this->store->id]);

        $response = $this->postJson("/api/v1/return-policies/{$policy2->id}/default");

        $response->assertStatus(200);

        $this->assertTrue($policy2->fresh()->is_default);
        $this->assertFalse($policy1->fresh()->is_default);
    }

    public function test_can_filter_policies_by_active_status(): void
    {
        Passport::actingAs($this->user);

        ReturnPolicy::factory()->count(2)->create(['store_id' => $this->store->id, 'is_active' => true]);
        ReturnPolicy::factory()->inactive()->create(['store_id' => $this->store->id]);

        $response = $this->getJson('/api/v1/return-policies?active=true');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_policy_calculates_restocking_fee(): void
    {
        $policy = ReturnPolicy::factory()->withRestockingFee(15)->create([
            'store_id' => $this->store->id,
        ]);

        $fee = $policy->calculateRestockingFee(100.00);

        $this->assertEquals(15.00, $fee);
    }

    public function test_policy_checks_order_eligibility(): void
    {
        $policy = ReturnPolicy::factory()->create([
            'store_id' => $this->store->id,
            'return_window_days' => 30,
        ]);

        $order = \App\Models\Order::factory()->create([
            'store_id' => $this->store->id,
            'date_of_purchase' => now()->subDays(15),
        ]);

        $this->assertTrue($policy->isEligibleForReturn($order));

        $oldOrder = \App\Models\Order::factory()->create([
            'store_id' => $this->store->id,
            'date_of_purchase' => now()->subDays(45),
        ]);

        $this->assertFalse($policy->isEligibleForReturn($oldOrder));
    }

    public function test_validation_rules_are_enforced(): void
    {
        Passport::actingAs($this->user);

        $response = $this->postJson('/api/v1/return-policies', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }
}
