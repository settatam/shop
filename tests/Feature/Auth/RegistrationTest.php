<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get(route('register'));

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'store_name' => 'Test Store',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_registration_creates_store_and_owner(): void
    {
        $response = $this->post(route('register.store'), [
            'name' => 'John Smith',
            'store_name' => 'Smith Jewelers',
            'email' => 'john@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();

        // Verify user was created
        $user = User::where('email', 'john@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals('John Smith', $user->name);

        // Verify store was created
        $store = Store::where('user_id', $user->id)->first();
        $this->assertNotNull($store);
        $this->assertEquals('Smith Jewelers', $store->name);
        $this->assertTrue($store->is_active);

        // Verify user's current store is set
        $this->assertEquals($store->id, $user->current_store_id);

        // Verify default roles were created
        $roles = Role::where('store_id', $store->id)->get();
        $this->assertGreaterThan(0, $roles->count());
        $this->assertTrue($roles->pluck('slug')->contains('owner'));

        // Verify store user record was created with owner role
        $storeUser = StoreUser::where('user_id', $user->id)
            ->where('store_id', $store->id)
            ->first();
        $this->assertNotNull($storeUser);
        $this->assertTrue($storeUser->is_owner);
        $this->assertEquals('active', $storeUser->status);
    }

    public function test_registration_requires_store_name(): void
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors(['store_name']);
        $this->assertGuest();
    }
}
