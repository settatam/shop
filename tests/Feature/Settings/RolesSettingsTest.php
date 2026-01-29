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

class RolesSettingsTest extends TestCase
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

    public function test_roles_settings_page_can_be_rendered(): void
    {
        $this->actingAs($this->owner);

        $response = $this->get('/settings/roles');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/Roles')
            ->has('roles')
            ->has('permissionsGrouped')
            ->has('categories')
            ->has('presets')
        );
    }

    public function test_roles_settings_shows_all_store_roles(): void
    {
        $this->actingAs($this->owner);

        $response = $this->get('/settings/roles');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/Roles')
            ->has('roles', 2) // Owner + staff roles
        );
    }

    public function test_roles_settings_shows_role_data(): void
    {
        $this->actingAs($this->owner);

        $response = $this->get('/settings/roles');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/Roles')
            ->has('roles.0', fn (Assert $role) => $role
                ->has('id')
                ->has('name')
                ->has('slug')
                ->has('description')
                ->has('permissions')
                ->has('is_system')
                ->has('is_default')
                ->has('store_users_count')
            )
        );
    }

    public function test_roles_settings_shows_permissions_grouped_by_category(): void
    {
        $this->actingAs($this->owner);

        $response = $this->get('/settings/roles');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/Roles')
            ->has('permissionsGrouped')
            ->has('categories')
        );
    }

    public function test_roles_settings_shows_presets(): void
    {
        $this->actingAs($this->owner);

        $response = $this->get('/settings/roles');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/Roles')
            ->has('presets')
        );
    }

    public function test_admin_with_permission_can_access_roles_settings(): void
    {
        // Create admin role with team.manage_roles permission
        $adminRole = Role::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Admin',
            'slug' => 'admin',
            'permissions' => ['team.view', 'team.manage_roles'],
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

        $response = $this->get('/settings/roles');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/Roles')
        );
    }

    public function test_staff_without_permission_cannot_access_roles_settings(): void
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

        $response = $this->get('/settings/roles');

        $response->assertStatus(403);
    }

    public function test_roles_settings_requires_authentication(): void
    {
        $response = $this->get('/settings/roles');

        $response->assertRedirect('/login');
    }

    public function test_roles_settings_requires_store_context(): void
    {
        // Clear store context from setUp
        app(StoreContext::class)->clear();

        // Create a user without a store
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = $this->get('/settings/roles');

        // Should get 403 because user has no permissions (no store = no role)
        $response->assertStatus(403);
    }
}
