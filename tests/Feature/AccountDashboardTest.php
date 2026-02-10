<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store1;

    protected Store $store2;

    protected function setUp(): void
    {
        parent::setUp();

        // Prevent Vite manifest errors for new Vue files not yet built
        $this->withoutVite();

        $this->user = User::factory()->create();

        // Create first store owned by user
        $this->store1 = Store::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);
        $ownerRole1 = Role::factory()->owner()->create(['store_id' => $this->store1->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store1->id,
            'role_id' => $ownerRole1->id,
        ]);

        // Create second store owned by user
        $this->store2 = Store::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);
        $ownerRole2 = Role::factory()->owner()->create(['store_id' => $this->store2->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store2->id,
            'role_id' => $ownerRole2->id,
        ]);
    }

    public function test_user_with_multiple_stores_can_access_account_dashboard(): void
    {
        $this->actingAs($this->user);

        $response = $this->get('/account/dashboard');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('account/Dashboard')
            ->has('stores', 2)
            ->has('summary')
        );
    }

    public function test_user_with_single_store_is_redirected_to_regular_dashboard(): void
    {
        // Create user with only one store
        $singleStoreUser = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $singleStoreUser->id, 'is_active' => true]);
        $ownerRole = Role::factory()->owner()->create(['store_id' => $store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $singleStoreUser->id,
            'store_id' => $store->id,
            'role_id' => $ownerRole->id,
        ]);
        $singleStoreUser->update(['current_store_id' => $store->id]);

        $this->actingAs($singleStoreUser);

        $response = $this->get('/account/dashboard');

        $response->assertRedirect(route('dashboard'));
    }

    public function test_account_dashboard_shows_store_transactions(): void
    {
        // Create some transactions for store1
        Transaction::factory()->count(3)->create(['store_id' => $this->store1->id]);

        $this->actingAs($this->user);

        $response = $this->get('/account/dashboard');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('account/Dashboard')
            ->has('stores', fn ($stores) => $stores
                ->has(2)
                ->each(fn ($store) => $store
                    ->has('total_transactions')
                    ->etc()
                )
            )
            ->where('summary.total_transactions', 3)
        );
    }

    public function test_account_dashboard_shows_store_orders(): void
    {
        // Create some orders for store1 (to avoid ordering issues)
        Order::factory()->count(2)->create(['store_id' => $this->store1->id]);

        $this->actingAs($this->user);

        $response = $this->get('/account/dashboard');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('account/Dashboard')
            ->has('stores', fn ($stores) => $stores
                ->has(2)
                ->each(fn ($store) => $store
                    ->has('total_orders')
                    ->etc()
                )
            )
            ->where('summary.total_orders', 2)
        );
    }

    public function test_admin_user_can_access_account_dashboard(): void
    {
        // Create a user who is admin (not owner) of two stores
        $adminUser = User::factory()->create();

        $otherOwner1 = User::factory()->create();
        $adminStore1 = Store::factory()->create(['user_id' => $otherOwner1->id, 'is_active' => true]);
        $adminRole1 = Role::factory()->admin()->create(['store_id' => $adminStore1->id]);
        StoreUser::factory()->create([
            'user_id' => $adminUser->id,
            'store_id' => $adminStore1->id,
            'role_id' => $adminRole1->id,
            'is_owner' => false,
        ]);

        $otherOwner2 = User::factory()->create();
        $adminStore2 = Store::factory()->create(['user_id' => $otherOwner2->id, 'is_active' => true]);
        $adminRole2 = Role::factory()->admin()->create(['store_id' => $adminStore2->id]);
        StoreUser::factory()->create([
            'user_id' => $adminUser->id,
            'store_id' => $adminStore2->id,
            'role_id' => $adminRole2->id,
            'is_owner' => false,
        ]);

        $this->actingAs($adminUser);

        $response = $this->get('/account/dashboard');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('account/Dashboard')
            ->has('stores', 2)
        );
    }

    public function test_staff_user_cannot_access_account_dashboard(): void
    {
        // Create a user who is only staff at two stores (not admin or owner)
        $staffUser = User::factory()->create();

        $owner1 = User::factory()->create();
        $staffStore1 = Store::factory()->create(['user_id' => $owner1->id, 'is_active' => true]);
        $staffRole1 = Role::factory()->staff()->create(['store_id' => $staffStore1->id]);
        StoreUser::factory()->create([
            'user_id' => $staffUser->id,
            'store_id' => $staffStore1->id,
            'role_id' => $staffRole1->id,
            'is_owner' => false,
        ]);
        $staffUser->update(['current_store_id' => $staffStore1->id]);

        $owner2 = User::factory()->create();
        $staffStore2 = Store::factory()->create(['user_id' => $owner2->id, 'is_active' => true]);
        $staffRole2 = Role::factory()->staff()->create(['store_id' => $staffStore2->id]);
        StoreUser::factory()->create([
            'user_id' => $staffUser->id,
            'store_id' => $staffStore2->id,
            'role_id' => $staffRole2->id,
            'is_owner' => false,
        ]);

        $this->actingAs($staffUser);

        $response = $this->get('/account/dashboard');

        // Staff users should be redirected since they're not admin/owner
        $response->assertRedirect(route('dashboard'));
    }

    public function test_store_switch_with_redirect_parameter(): void
    {
        $this->user->update(['current_store_id' => $this->store1->id]);

        $this->actingAs($this->user);

        $response = $this->post("/stores/{$this->store2->id}/switch?redirect=/transactions");

        $response->assertRedirect('/transactions');

        $this->user->refresh();
        $this->assertEquals($this->store2->id, $this->user->current_store_id);
    }

    public function test_store_switch_without_redirect_goes_to_dashboard(): void
    {
        $this->user->update(['current_store_id' => $this->store1->id]);

        $this->actingAs($this->user);

        $response = $this->post("/stores/{$this->store2->id}/switch");

        $response->assertRedirect(route('dashboard'));
    }

    public function test_unauthenticated_user_cannot_access_account_dashboard(): void
    {
        $response = $this->get('/account/dashboard');

        $response->assertRedirect(route('login'));
    }

    public function test_account_dashboard_only_shows_active_stores(): void
    {
        // Deactivate store2
        $this->store2->update(['is_active' => false]);

        $this->actingAs($this->user);

        $response = $this->get('/account/dashboard');

        // With only one active store, user should be redirected
        $response->assertRedirect(route('dashboard'));
    }
}
