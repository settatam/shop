<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class RoleTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected Role $ownerRole;

    protected StoreUser $storeUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id]);

        // Create owner role for this store
        $this->ownerRole = Role::factory()->owner()->create([
            'store_id' => $this->store->id,
        ]);

        // Create store user
        $this->storeUser = StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $this->ownerRole->id,
        ]);

        // Set store context
        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_role_has_permission_with_exact_match(): void
    {
        $role = Role::factory()->create([
            'store_id' => $this->store->id,
            'permissions' => ['products.view', 'products.create'],
        ]);

        $this->assertTrue($role->hasPermission('products.view'));
        $this->assertTrue($role->hasPermission('products.create'));
        $this->assertFalse($role->hasPermission('products.delete'));
    }

    public function test_role_has_permission_with_wildcard(): void
    {
        $role = Role::factory()->create([
            'store_id' => $this->store->id,
            'permissions' => ['*'],
        ]);

        $this->assertTrue($role->hasPermission('products.view'));
        $this->assertTrue($role->hasPermission('orders.create'));
        $this->assertTrue($role->hasPermission('settings.update'));
    }

    public function test_role_has_permission_with_category_wildcard(): void
    {
        $role = Role::factory()->create([
            'store_id' => $this->store->id,
            'permissions' => ['products.*', 'orders.view'],
        ]);

        $this->assertTrue($role->hasPermission('products.view'));
        $this->assertTrue($role->hasPermission('products.create'));
        $this->assertTrue($role->hasPermission('products.delete'));
        $this->assertTrue($role->hasPermission('orders.view'));
        $this->assertFalse($role->hasPermission('orders.create'));
    }

    public function test_role_has_any_permission(): void
    {
        $role = Role::factory()->create([
            'store_id' => $this->store->id,
            'permissions' => ['products.view'],
        ]);

        $this->assertTrue($role->hasAnyPermission(['products.view', 'products.create']));
        $this->assertFalse($role->hasAnyPermission(['orders.view', 'orders.create']));
    }

    public function test_role_has_all_permissions(): void
    {
        $role = Role::factory()->create([
            'store_id' => $this->store->id,
            'permissions' => ['products.view', 'products.create', 'orders.view'],
        ]);

        $this->assertTrue($role->hasAllPermissions(['products.view', 'products.create']));
        $this->assertFalse($role->hasAllPermissions(['products.view', 'products.delete']));
    }

    public function test_role_grant_permission(): void
    {
        $role = Role::factory()->create([
            'store_id' => $this->store->id,
            'permissions' => ['products.view'],
        ]);

        $role->grantPermission('products.create');

        $this->assertTrue($role->hasPermission('products.view'));
        $this->assertTrue($role->hasPermission('products.create'));
    }

    public function test_role_revoke_permission(): void
    {
        $role = Role::factory()->create([
            'store_id' => $this->store->id,
            'permissions' => ['products.view', 'products.create'],
        ]);

        $role->revokePermission('products.create');

        $this->assertTrue($role->hasPermission('products.view'));
        $this->assertFalse($role->hasPermission('products.create'));
    }

    public function test_role_sync_permissions(): void
    {
        $role = Role::factory()->create([
            'store_id' => $this->store->id,
            'permissions' => ['products.view', 'products.create'],
        ]);

        $role->syncPermissions(['orders.view', 'orders.create']);

        $this->assertFalse($role->hasPermission('products.view'));
        $this->assertTrue($role->hasPermission('orders.view'));
        $this->assertTrue($role->hasPermission('orders.create'));
    }

    public function test_create_default_roles_for_store(): void
    {
        $newStore = Store::factory()->create();

        Role::createDefaultRoles($newStore->id);

        $roles = Role::withoutGlobalScopes()->where('store_id', $newStore->id)->get();

        $this->assertCount(5, $roles);
        $this->assertTrue($roles->contains('slug', 'owner'));
        $this->assertTrue($roles->contains('slug', 'admin'));
        $this->assertTrue($roles->contains('slug', 'manager'));
        $this->assertTrue($roles->contains('slug', 'staff'));
        $this->assertTrue($roles->contains('slug', 'viewer'));
    }

    public function test_owner_role_is_system_role(): void
    {
        // Use the owner role created in setUp
        $this->assertTrue($this->ownerRole->isSystemRole());
        $this->assertTrue($this->ownerRole->isOwner());
    }

    public function test_can_list_roles_via_api(): void
    {
        Passport::actingAs($this->user);

        Role::factory()->count(3)->create(['store_id' => $this->store->id]);

        $response = $this->getJson('/api/v1/roles');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'slug', 'description', 'permissions'],
                ],
            ]);
    }

    public function test_can_create_role_via_api(): void
    {
        Passport::actingAs($this->user);

        $response = $this->postJson('/api/v1/roles', [
            'name' => 'Custom Role',
            'slug' => 'custom-role',
            'description' => 'A custom role',
            'permissions' => ['products.view', 'orders.view'],
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'name' => 'Custom Role',
                'slug' => 'custom-role',
            ]);

        $this->assertDatabaseHas('roles', [
            'name' => 'Custom Role',
            'slug' => 'custom-role',
            'store_id' => $this->store->id,
        ]);
    }

    public function test_cannot_create_role_with_duplicate_slug(): void
    {
        Passport::actingAs($this->user);

        Role::factory()->create([
            'store_id' => $this->store->id,
            'slug' => 'existing-role',
        ]);

        $response = $this->postJson('/api/v1/roles', [
            'name' => 'New Role',
            'slug' => 'existing-role',
            'permissions' => ['products.view'],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('slug');
    }

    public function test_can_update_role_via_api(): void
    {
        Passport::actingAs($this->user);

        $role = Role::factory()->create(['store_id' => $this->store->id]);

        $response = $this->patchJson("/api/v1/roles/{$role->id}", [
            'name' => 'Updated Role Name',
            'permissions' => ['products.*'],
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['name' => 'Updated Role Name']);

        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
            'name' => 'Updated Role Name',
        ]);
    }

    public function test_cannot_delete_system_role(): void
    {
        Passport::actingAs($this->user);

        // Use the owner role created in setUp
        $response = $this->deleteJson("/api/v1/roles/{$this->ownerRole->id}");

        $response->assertStatus(403);

        $this->assertDatabaseHas('roles', ['id' => $this->ownerRole->id]);
    }

    public function test_can_delete_custom_role(): void
    {
        Passport::actingAs($this->user);

        $role = Role::factory()->create(['store_id' => $this->store->id]);

        $response = $this->deleteJson("/api/v1/roles/{$role->id}");

        $response->assertStatus(204);

        $this->assertSoftDeleted('roles', ['id' => $role->id]);
    }

    public function test_cannot_delete_role_with_users(): void
    {
        Passport::actingAs($this->user);

        $role = Role::factory()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->create([
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        $response = $this->deleteJson("/api/v1/roles/{$role->id}");

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Cannot delete role with assigned users. Reassign users first.']);
    }

    public function test_can_get_permissions_list(): void
    {
        Passport::actingAs($this->user);

        $response = $this->getJson('/api/v1/roles/permissions');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'permissions',
                'grouped',
                'categories',
            ]);
    }

    public function test_can_get_role_presets(): void
    {
        Passport::actingAs($this->user);

        $response = $this->getJson('/api/v1/roles/presets');

        $response->assertStatus(200);

        $presets = $response->json();
        $this->assertArrayHasKey('owner', $presets);
        $this->assertArrayHasKey('admin', $presets);
        $this->assertArrayHasKey('manager', $presets);
        $this->assertArrayHasKey('staff', $presets);
        $this->assertArrayHasKey('viewer', $presets);
    }

    public function test_can_duplicate_role(): void
    {
        Passport::actingAs($this->user);

        $role = Role::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Original Role',
            'slug' => 'original-role',
            'permissions' => ['products.view'],
        ]);

        $response = $this->postJson("/api/v1/roles/{$role->id}/duplicate", [
            'name' => 'Copied Role',
            'slug' => 'copied-role',
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'name' => 'Copied Role',
                'slug' => 'copied-role',
            ]);

        // Verify permissions were copied
        $newRole = Role::withoutGlobalScopes()->find($response->json('id'));
        $this->assertEquals(['products.view'], $newRole->permissions);
    }
}
