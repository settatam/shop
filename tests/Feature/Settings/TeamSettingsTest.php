<?php

namespace Tests\Feature\Settings;

use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TeamSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Store $store;

    protected Role $ownerRole;

    protected Role $staffRole;

    protected StoreUser $ownerStoreUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->owner->id]);

        $this->ownerRole = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        $this->staffRole = Role::factory()->staff()->create(['store_id' => $this->store->id]);

        $this->ownerStoreUser = StoreUser::factory()->owner()->create([
            'user_id' => $this->owner->id,
            'store_id' => $this->store->id,
            'role_id' => $this->ownerRole->id,
        ]);

        $this->owner->update(['current_store_id' => $this->store->id]);
        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_team_settings_page_can_be_rendered(): void
    {
        $this->actingAs($this->owner);

        $response = $this->get('/settings/team');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/Team')
            ->has('members')
            ->has('roles')
            ->has('isOwner')
            ->has('currentUserId')
        );
    }

    public function test_team_settings_shows_team_members(): void
    {
        $this->actingAs($this->owner);

        // Add a staff member
        $staffUser = User::factory()->create();
        StoreUser::factory()->create([
            'user_id' => $staffUser->id,
            'store_id' => $this->store->id,
            'role_id' => $this->staffRole->id,
        ]);

        $response = $this->get('/settings/team');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/Team')
            ->has('members', 2) // Owner + staff
        );
    }

    public function test_team_settings_shows_available_roles(): void
    {
        $this->actingAs($this->owner);

        $response = $this->get('/settings/team');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/Team')
            ->has('roles', 2) // Owner + staff roles
        );
    }

    public function test_owner_sees_is_owner_true(): void
    {
        $this->actingAs($this->owner);

        $response = $this->get('/settings/team');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/Team')
            ->where('isOwner', true)
        );
    }

    public function test_admin_sees_is_owner_false(): void
    {
        // Create admin role with team.view permission
        $adminRole = Role::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Admin',
            'slug' => 'admin',
            'permissions' => ['team.view', 'team.invite', 'team.update', 'team.remove'],
        ]);

        $adminUser = User::factory()->create();
        StoreUser::factory()->create([
            'user_id' => $adminUser->id,
            'store_id' => $this->store->id,
            'role_id' => $adminRole->id,
            'is_owner' => false,
        ]);
        $adminUser->update(['current_store_id' => $this->store->id]);

        $this->actingAs($adminUser);

        $response = $this->get('/settings/team');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/Team')
            ->where('isOwner', false)
        );
    }

    public function test_staff_cannot_access_team_settings(): void
    {
        $staffUser = User::factory()->create();
        StoreUser::factory()->create([
            'user_id' => $staffUser->id,
            'store_id' => $this->store->id,
            'role_id' => $this->staffRole->id,
            'is_owner' => false,
        ]);
        $staffUser->update(['current_store_id' => $this->store->id]);

        $this->actingAs($staffUser);

        $response = $this->get('/settings/team');

        $response->assertStatus(403);
    }

    public function test_team_settings_requires_authentication(): void
    {
        $response = $this->get('/settings/team');

        $response->assertRedirect('/login');
    }

    public function test_team_settings_requires_store_context(): void
    {
        // Clear store context from setUp
        app(StoreContext::class)->clear();

        // Create a user without a store
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = $this->get('/settings/team');

        // Should get 403 because user has no permissions (no store = no role)
        $response->assertStatus(403);
    }
}
