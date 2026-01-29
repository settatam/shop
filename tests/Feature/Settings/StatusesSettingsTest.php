<?php

namespace Tests\Feature\Settings;

use App\Enums\StatusableType;
use App\Models\Role;
use App\Models\Status;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StatusesSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Store $store;

    protected Role $ownerRole;

    protected StoreUser $ownerStoreUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->store = Store::factory()->create([
            'user_id' => $this->owner->id,
            'step' => 2, // Mark onboarding as complete
        ]);

        $this->ownerRole = Role::factory()->owner()->create(['store_id' => $this->store->id]);

        $this->ownerStoreUser = StoreUser::factory()->owner()->create([
            'user_id' => $this->owner->id,
            'store_id' => $this->store->id,
            'role_id' => $this->ownerRole->id,
        ]);

        $this->owner->update(['current_store_id' => $this->store->id]);
        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_statuses_settings_page_can_be_rendered(): void
    {
        $this->actingAs($this->owner);

        $response = $this->get('/settings/statuses');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/Statuses')
            ->has('statuses')
            ->has('entityTypes')
            ->has('behaviorFlags')
        );
    }

    public function test_statuses_settings_shows_all_entity_types(): void
    {
        $this->actingAs($this->owner);

        $response = $this->get('/settings/statuses');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/Statuses')
            ->has('entityTypes', count(StatusableType::cases()))
        );
    }

    public function test_statuses_settings_shows_store_statuses(): void
    {
        // Create some statuses
        Status::factory()->forOrder()->count(3)->create(['store_id' => $this->store->id]);
        Status::factory()->forTransaction()->count(2)->create(['store_id' => $this->store->id]);

        $this->actingAs($this->owner);

        $response = $this->get('/settings/statuses');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/Statuses')
            ->has('statuses.order', 3)
            ->has('statuses.transaction', 2)
        );
    }

    public function test_statuses_settings_shows_status_data(): void
    {
        Status::factory()->forOrder()->create(['store_id' => $this->store->id]);

        $this->actingAs($this->owner);

        $response = $this->get('/settings/statuses');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/Statuses')
            ->has('statuses.order.0', fn (Assert $status) => $status
                ->has('id')
                ->has('name')
                ->has('slug')
                ->has('entity_type')
                ->has('color')
                ->has('icon')
                ->has('description')
                ->has('is_default')
                ->has('is_final')
                ->has('is_system')
                ->has('sort_order')
                ->has('behavior')
                ->has('transitions')
                ->has('automations')
                ->has('automations_count')
            )
        );
    }

    public function test_statuses_settings_shows_transitions(): void
    {
        $fromStatus = Status::factory()->forOrder()->create([
            'store_id' => $this->store->id,
            'sort_order' => 0,
        ]);
        $toStatus = Status::factory()->forOrder()->create([
            'store_id' => $this->store->id,
            'sort_order' => 1,
        ]);

        $fromStatus->outgoingTransitions()->create([
            'to_status_id' => $toStatus->id,
            'name' => 'Test Transition',
        ]);

        $this->actingAs($this->owner);

        $response = $this->get('/settings/statuses');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/Statuses')
            ->has('statuses.order.0.transitions', 1)
        );
    }

    public function test_statuses_settings_shows_behavior_flags(): void
    {
        $this->actingAs($this->owner);

        $response = $this->get('/settings/statuses');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/Statuses')
            ->has('behaviorFlags')
            ->where('behaviorFlags.allows_payment', 'Allows payment processing')
            ->where('behaviorFlags.allows_cancellation', 'Allows cancellation')
        );
    }

    public function test_statuses_settings_requires_authentication(): void
    {
        $response = $this->get('/settings/statuses');

        $response->assertRedirect('/login');
    }

    public function test_statuses_settings_requires_store_context(): void
    {
        app(StoreContext::class)->clear();

        $user = User::factory()->create();

        $this->actingAs($user);

        $response = $this->get('/settings/statuses');

        // User without store context has no permissions, so gets 403
        $response->assertStatus(403);
    }

    public function test_statuses_settings_requires_permission(): void
    {
        // Create a user with a role that doesn't have the manage_statuses permission
        $user = User::factory()->create();
        $role = Role::factory()->create([
            'store_id' => $this->store->id,
            'permissions' => ['products.view'], // Only has product view permission
        ]);
        StoreUser::factory()->create([
            'user_id' => $user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);
        $user->update(['current_store_id' => $this->store->id]);

        $this->actingAs($user);

        $response = $this->get('/settings/statuses');

        $response->assertStatus(403);
    }

    public function test_statuses_grouped_by_entity_type(): void
    {
        Status::factory()->forOrder()->count(2)->create(['store_id' => $this->store->id]);
        Status::factory()->forTransaction()->count(3)->create(['store_id' => $this->store->id]);
        Status::factory()->forRepair()->count(1)->create(['store_id' => $this->store->id]);

        $this->actingAs($this->owner);

        $response = $this->get('/settings/statuses');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/Statuses')
            ->has('statuses.order', 2)
            ->has('statuses.transaction', 3)
            ->has('statuses.repair', 1)
        );
    }

    public function test_other_stores_statuses_not_shown(): void
    {
        // Create statuses for our store
        Status::factory()->forOrder()->count(2)->create(['store_id' => $this->store->id]);

        // Create statuses for another store
        $otherStore = Store::factory()->create();
        Status::factory()->forOrder()->count(5)->create(['store_id' => $otherStore->id]);

        $this->actingAs($this->owner);

        $response = $this->get('/settings/statuses');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/Statuses')
            ->has('statuses.order', 2) // Only our store's statuses
        );
    }
}
