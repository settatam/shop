<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Passport;
use Tests\TestCase;

class PermissionMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected Role $role;

    protected StoreUser $storeUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id]);

        $this->role = Role::factory()->create([
            'store_id' => $this->store->id,
            'permissions' => ['products.view', 'products.create'],
        ]);

        $this->storeUser = StoreUser::factory()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $this->role->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);

        // Register test routes
        Route::middleware(['api', 'auth:api', 'store', 'permission:products.view'])
            ->get('/test/view-products', fn () => response()->json(['success' => true]));

        Route::middleware(['api', 'auth:api', 'store', 'permission:products.delete'])
            ->get('/test/delete-products', fn () => response()->json(['success' => true]));

        Route::middleware(['api', 'auth:api', 'store', 'permission:products.view,orders.view'])
            ->get('/test/view-any', fn () => response()->json(['success' => true]));
    }

    public function test_allows_user_with_required_permission(): void
    {
        Passport::actingAs($this->user);

        $response = $this->getJson('/test/view-products');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_denies_user_without_required_permission(): void
    {
        Passport::actingAs($this->user);

        $response = $this->getJson('/test/delete-products');

        $response->assertStatus(403)
            ->assertJson(['message' => 'You do not have permission to perform this action']);
    }

    public function test_allows_user_with_any_of_multiple_permissions(): void
    {
        Passport::actingAs($this->user);

        // User has products.view but not orders.view
        $response = $this->getJson('/test/view-any');

        $response->assertStatus(200);
    }

    public function test_denies_unauthenticated_request(): void
    {
        $response = $this->getJson('/test/view-products');

        // Should be denied by auth middleware first
        $response->assertStatus(401);
    }

    public function test_user_with_wildcard_permission_can_access_any_resource(): void
    {
        $ownerRole = Role::factory()->create([
            'store_id' => $this->store->id,
            'permissions' => ['*'],
        ]);

        $this->storeUser->update(['role_id' => $ownerRole->id]);

        Passport::actingAs($this->user);

        $response = $this->getJson('/test/delete-products');

        $response->assertStatus(200);
    }

    public function test_user_with_category_wildcard_can_access_category_resources(): void
    {
        $productManagerRole = Role::factory()->create([
            'store_id' => $this->store->id,
            'permissions' => ['products.*'],
        ]);

        $this->storeUser->update(['role_id' => $productManagerRole->id]);

        Passport::actingAs($this->user);

        $response = $this->getJson('/test/delete-products');

        $response->assertStatus(200);
    }

    public function test_middleware_can_be_applied_via_alias(): void
    {
        // Define a route using the alias
        Route::middleware(['api', 'auth:api', 'store', 'permission:settings.view'])
            ->get('/test/settings', fn () => response()->json(['success' => true]));

        // User doesn't have settings.view permission
        Passport::actingAs($this->user);

        $response = $this->getJson('/test/settings');

        $response->assertStatus(403);
    }

    public function test_middleware_returns_json_for_api_requests(): void
    {
        Passport::actingAs($this->user);

        $response = $this->getJson('/test/delete-products');

        $response->assertStatus(403)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message', 'required_permissions']);
    }
}
