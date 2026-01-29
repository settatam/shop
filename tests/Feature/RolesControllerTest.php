<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolesControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_access_roles_page(): void
    {
        // Create user and store
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);

        // Create default roles for the store
        Role::createDefaultRoles($store->id);

        // Get the owner role
        $ownerRole = Role::where('store_id', $store->id)
            ->where('slug', 'owner')
            ->first();

        // Create store user with owner role
        StoreUser::create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $ownerRole->id,
            'is_owner' => true,
            'status' => 'active',
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $user->email,
        ]);

        // Set current store
        $user->update(['current_store_id' => $store->id]);

        // Act as the user and access the roles page
        $response = $this->actingAs($user)
            ->withSession(['current_store_id' => $store->id])
            ->get('/settings/roles');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('settings/Roles')
            ->has('roles')
            ->has('permissionsGrouped')
            ->has('categories')
            ->has('presets')
        );
    }

    public function test_user_without_permission_cannot_access_roles_page(): void
    {
        // Create user and store
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);

        // Create default roles for the store
        Role::createDefaultRoles($store->id);

        // Get the staff role (doesn't have team.manage_roles permission)
        $staffRole = Role::where('store_id', $store->id)
            ->where('slug', 'staff')
            ->first();

        // Create store user with staff role
        StoreUser::create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $staffRole->id,
            'is_owner' => false,
            'status' => 'active',
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $user->email,
        ]);

        // Set current store
        $user->update(['current_store_id' => $store->id]);

        // Act as the user and try to access the roles page
        $response = $this->actingAs($user)
            ->withSession(['current_store_id' => $store->id])
            ->get('/settings/roles');

        $response->assertStatus(403);
    }
}
