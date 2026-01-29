<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class StoreUserTest extends TestCase
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

        // Create roles for this store
        $this->ownerRole = Role::factory()->owner()->create([
            'store_id' => $this->store->id,
        ]);

        $this->staffRole = Role::factory()->staff()->create([
            'store_id' => $this->store->id,
        ]);

        // Create owner store user
        $this->ownerStoreUser = StoreUser::factory()->owner()->create([
            'user_id' => $this->owner->id,
            'store_id' => $this->store->id,
            'role_id' => $this->ownerRole->id,
        ]);

        // Set store context
        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_can_list_team_members(): void
    {
        Passport::actingAs($this->owner);

        // Create additional team members
        StoreUser::factory()->count(3)->create([
            'store_id' => $this->store->id,
            'role_id' => $this->staffRole->id,
        ]);

        $response = $this->getJson('/api/v1/team');

        $response->assertStatus(200);

        // Owner + 3 staff = 4 total
        $this->assertCount(4, $response->json('data'));
    }

    public function test_can_invite_new_user(): void
    {
        Passport::actingAs($this->owner);

        $response = $this->postJson('/api/v1/team', [
            'email' => 'newuser@example.com',
            'first_name' => 'New',
            'last_name' => 'User',
            'role_id' => $this->staffRole->id,
        ]);

        $response->assertStatus(201);

        // Verify store user was created with pending status (user not in system yet)
        $this->assertDatabaseHas('store_users', [
            'email' => 'newuser@example.com',
            'store_id' => $this->store->id,
            'role_id' => $this->staffRole->id,
            'status' => 'invite sent',
        ]);
    }

    public function test_can_add_existing_user_to_store(): void
    {
        Passport::actingAs($this->owner);

        $existingUser = User::factory()->create();

        $response = $this->postJson('/api/v1/team', [
            'email' => $existingUser->email,
            'first_name' => explode(' ', $existingUser->name)[0],
            'last_name' => explode(' ', $existingUser->name, 2)[1] ?? '',
            'role_id' => $this->staffRole->id,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('store_users', [
            'user_id' => $existingUser->id,
            'store_id' => $this->store->id,
            'role_id' => $this->staffRole->id,
            'status' => 'active',
        ]);
    }

    public function test_cannot_add_same_user_twice(): void
    {
        Passport::actingAs($this->owner);

        // The owner already has a store_user record with their email from setUp
        $response = $this->postJson('/api/v1/team', [
            'email' => $this->ownerStoreUser->email,
            'first_name' => 'Test',
            'last_name' => 'User',
            'role_id' => $this->staffRole->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'User is already a member of this store']);
    }

    public function test_can_update_team_member_role(): void
    {
        Passport::actingAs($this->owner);

        $staffUser = StoreUser::factory()->create([
            'store_id' => $this->store->id,
            'role_id' => $this->staffRole->id,
            'is_owner' => false,
        ]);

        $newRole = Role::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Manager',
            'slug' => 'manager',
        ]);

        $response = $this->patchJson("/api/v1/team/{$staffUser->id}", [
            'role_id' => $newRole->id,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('store_users', [
            'id' => $staffUser->id,
            'role_id' => $newRole->id,
        ]);
    }

    public function test_cannot_modify_owner(): void
    {
        Passport::actingAs($this->owner);

        $response = $this->patchJson("/api/v1/team/{$this->ownerStoreUser->id}", [
            'role_id' => $this->staffRole->id,
        ]);

        $response->assertStatus(403)
            ->assertJsonFragment(['message' => 'Cannot modify the store owner']);
    }

    public function test_can_remove_team_member(): void
    {
        Passport::actingAs($this->owner);

        $staffUser = StoreUser::factory()->create([
            'store_id' => $this->store->id,
            'role_id' => $this->staffRole->id,
            'is_owner' => false,
        ]);

        $response = $this->deleteJson("/api/v1/team/{$staffUser->id}");

        $response->assertStatus(204);

        $this->assertSoftDeleted('store_users', ['id' => $staffUser->id]);
    }

    public function test_cannot_remove_owner(): void
    {
        Passport::actingAs($this->owner);

        $response = $this->deleteJson("/api/v1/team/{$this->ownerStoreUser->id}");

        $response->assertStatus(403)
            ->assertJsonFragment(['message' => 'Cannot remove the store owner']);
    }

    public function test_cannot_remove_self(): void
    {
        // Remove owner flag from original owner so deletion would be allowed if not self
        $this->ownerStoreUser->update(['is_owner' => false]);

        Passport::actingAs($this->owner);

        $response = $this->deleteJson("/api/v1/team/{$this->ownerStoreUser->id}");

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Cannot remove yourself from the store']);
    }

    public function test_can_get_own_permissions(): void
    {
        Passport::actingAs($this->owner);

        $response = $this->getJson('/api/v1/team/permissions');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'permissions',
                'is_owner',
                'role',
            ]);

        $this->assertTrue($response->json('is_owner'));
    }

    public function test_store_user_has_permission_through_role(): void
    {
        $staffUser = StoreUser::factory()->create([
            'store_id' => $this->store->id,
            'role_id' => $this->staffRole->id,
        ]);

        // Staff role should have products.view permission
        $this->assertTrue($staffUser->hasPermission('products.view'));
    }

    public function test_store_user_owner_flag_grants_owner_status(): void
    {
        $storeUser = StoreUser::factory()->owner()->create([
            'store_id' => $this->store->id,
            'role_id' => $this->staffRole->id, // Even with staff role
        ]);

        $this->assertTrue($storeUser->isOwner());
    }

    public function test_transfer_ownership(): void
    {
        Passport::actingAs($this->owner);

        $newOwner = User::factory()->create();
        $newOwnerStoreUser = StoreUser::factory()->create([
            'user_id' => $newOwner->id,
            'store_id' => $this->store->id,
            'role_id' => $this->staffRole->id,
            'is_owner' => false,
        ]);

        $response = $this->postJson("/api/v1/team/{$newOwnerStoreUser->id}/transfer-ownership");

        $response->assertStatus(200);

        // Verify ownership was transferred
        $this->ownerStoreUser->refresh();
        $newOwnerStoreUser->refresh();

        $this->assertFalse($this->ownerStoreUser->is_owner);
        $this->assertTrue($newOwnerStoreUser->is_owner);
    }

    public function test_non_owner_cannot_transfer_ownership(): void
    {
        $staffUser = User::factory()->create();
        $staffStoreUser = StoreUser::factory()->create([
            'user_id' => $staffUser->id,
            'store_id' => $this->store->id,
            'role_id' => $this->staffRole->id,
            'is_owner' => false,
        ]);

        Passport::actingAs($staffUser);

        $targetUser = User::factory()->create();
        $targetStoreUser = StoreUser::factory()->create([
            'user_id' => $targetUser->id,
            'store_id' => $this->store->id,
            'role_id' => $this->staffRole->id,
        ]);

        $response = $this->postJson("/api/v1/team/{$targetStoreUser->id}/transfer-ownership");

        $response->assertStatus(403)
            ->assertJsonFragment(['message' => 'Only the store owner can transfer ownership']);
    }

    public function test_can_manually_accept_pending_invitation(): void
    {
        Passport::actingAs($this->owner);

        // Create a pending invitation (user doesn't exist yet)
        $pendingInvite = StoreUser::factory()->create([
            'store_id' => $this->store->id,
            'role_id' => $this->staffRole->id,
            'user_id' => null,
            'status' => 'invite sent',
            'email' => 'invited@example.com',
            'first_name' => 'Invited',
            'last_name' => 'User',
            'token' => 'some-random-token',
        ]);

        $response = $this->postJson("/api/v1/team/{$pendingInvite->id}/accept-invitation", [
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'Invitation accepted successfully']);

        // Verify user was created
        $this->assertDatabaseHas('users', [
            'email' => 'invited@example.com',
            'name' => 'Invited User',
        ]);

        // Verify store user was updated
        $pendingInvite->refresh();
        $this->assertEquals('active', $pendingInvite->status);
        $this->assertNull($pendingInvite->token);
        $this->assertNotNull($pendingInvite->user_id);

        // Verify password works
        $newUser = User::where('email', 'invited@example.com')->first();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('SecurePassword123!', $newUser->password));
    }

    public function test_cannot_accept_already_active_invitation(): void
    {
        Passport::actingAs($this->owner);

        $existingUser = User::factory()->create();
        $activeStoreUser = StoreUser::factory()->create([
            'store_id' => $this->store->id,
            'role_id' => $this->staffRole->id,
            'user_id' => $existingUser->id,
            'status' => 'active',
        ]);

        $response = $this->postJson("/api/v1/team/{$activeStoreUser->id}/accept-invitation", [
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'This invitation has already been accepted or is not pending']);
    }

    public function test_cannot_accept_invitation_when_user_already_exists(): void
    {
        Passport::actingAs($this->owner);

        $existingUser = User::factory()->create();
        $storeUser = StoreUser::factory()->create([
            'store_id' => $this->store->id,
            'role_id' => $this->staffRole->id,
            'user_id' => $existingUser->id,
            'status' => 'invite sent', // Status still showing invite sent
        ]);

        $response = $this->postJson("/api/v1/team/{$storeUser->id}/accept-invitation", [
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'User already exists in the system']);
    }

    public function test_accept_invitation_requires_password_confirmation(): void
    {
        Passport::actingAs($this->owner);

        $pendingInvite = StoreUser::factory()->create([
            'store_id' => $this->store->id,
            'role_id' => $this->staffRole->id,
            'user_id' => null,
            'status' => 'invite sent',
        ]);

        $response = $this->postJson("/api/v1/team/{$pendingInvite->id}/accept-invitation", [
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'DifferentPassword!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_cannot_accept_invitation_from_other_store(): void
    {
        Passport::actingAs($this->owner);

        // Create another store with its own pending invitation
        $otherStore = Store::factory()->create();
        $otherRole = Role::factory()->staff()->create(['store_id' => $otherStore->id]);

        $pendingInvite = StoreUser::factory()->create([
            'store_id' => $otherStore->id,
            'role_id' => $otherRole->id,
            'user_id' => null,
            'status' => 'invite sent',
        ]);

        $response = $this->postJson("/api/v1/team/{$pendingInvite->id}/accept-invitation", [
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!',
        ]);

        $response->assertStatus(404);
    }

    public function test_invite_team_member_is_logged(): void
    {
        Passport::actingAs($this->owner);

        $response = $this->postJson('/api/v1/team', [
            'email' => 'newmember@example.com',
            'first_name' => 'New',
            'last_name' => 'Member',
            'role_id' => $this->staffRole->id,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('activity_logs', [
            'store_id' => $this->store->id,
            'activity_slug' => Activity::TEAM_INVITE,
        ]);

        $log = ActivityLog::where('activity_slug', Activity::TEAM_INVITE)->latest()->first();
        $this->assertStringContains('newmember@example.com', $log->description);
    }

    public function test_update_team_member_role_is_logged(): void
    {
        Passport::actingAs($this->owner);

        $staffUser = StoreUser::factory()->create([
            'store_id' => $this->store->id,
            'role_id' => $this->staffRole->id,
            'is_owner' => false,
        ]);

        $newRole = Role::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Manager',
            'slug' => 'manager',
        ]);

        $response = $this->patchJson("/api/v1/team/{$staffUser->id}", [
            'role_id' => $newRole->id,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('activity_logs', [
            'store_id' => $this->store->id,
            'activity_slug' => Activity::TEAM_UPDATE,
        ]);

        $log = ActivityLog::where('activity_slug', Activity::TEAM_UPDATE)->latest()->first();
        $this->assertStringContains('Manager', $log->description);
    }

    public function test_remove_team_member_is_logged(): void
    {
        Passport::actingAs($this->owner);

        $staffUser = StoreUser::factory()->create([
            'store_id' => $this->store->id,
            'role_id' => $this->staffRole->id,
            'is_owner' => false,
            'email' => 'toremove@example.com',
        ]);

        $response = $this->deleteJson("/api/v1/team/{$staffUser->id}");

        $response->assertStatus(204);

        $this->assertDatabaseHas('activity_logs', [
            'store_id' => $this->store->id,
            'activity_slug' => Activity::TEAM_REMOVE,
        ]);

        $log = ActivityLog::where('activity_slug', Activity::TEAM_REMOVE)->latest()->first();
        $this->assertStringContains('toremove@example.com', $log->description);
    }

    public function test_accept_invitation_is_logged(): void
    {
        Passport::actingAs($this->owner);

        $pendingInvite = StoreUser::factory()->create([
            'store_id' => $this->store->id,
            'role_id' => $this->staffRole->id,
            'user_id' => null,
            'status' => 'invite sent',
            'email' => 'accepted@example.com',
            'first_name' => 'Accepted',
            'last_name' => 'User',
        ]);

        $response = $this->postJson("/api/v1/team/{$pendingInvite->id}/accept-invitation", [
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('activity_logs', [
            'store_id' => $this->store->id,
            'activity_slug' => Activity::TEAM_ACCEPT_INVITATION,
        ]);

        $log = ActivityLog::where('activity_slug', Activity::TEAM_ACCEPT_INVITATION)->latest()->first();
        $this->assertStringContains('accepted@example.com', $log->description);
    }

    public function test_transfer_ownership_is_logged(): void
    {
        Passport::actingAs($this->owner);

        $newOwner = User::factory()->create();
        $newOwnerStoreUser = StoreUser::factory()->create([
            'user_id' => $newOwner->id,
            'store_id' => $this->store->id,
            'role_id' => $this->staffRole->id,
            'is_owner' => false,
        ]);

        $response = $this->postJson("/api/v1/team/{$newOwnerStoreUser->id}/transfer-ownership");

        $response->assertStatus(200);

        $this->assertDatabaseHas('activity_logs', [
            'store_id' => $this->store->id,
            'activity_slug' => Activity::TEAM_TRANSFER_OWNERSHIP,
        ]);

        $log = ActivityLog::where('activity_slug', Activity::TEAM_TRANSFER_OWNERSHIP)->latest()->first();
        $this->assertStringContains('Transferred store ownership', $log->description);
    }

    /**
     * Helper method to check if string contains substring.
     */
    private function assertStringContains(string $needle, ?string $haystack): void
    {
        $this->assertNotNull($haystack, 'The string to search in is null');
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'"
        );
    }
}
