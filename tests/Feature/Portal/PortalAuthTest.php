<?php

namespace Tests\Feature\Portal;

use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalAuthTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = Store::factory()->create(['slug' => 'test-store']);
    }

    protected function portalUrl(string $path = ''): string
    {
        return "http://{$this->store->slug}.portal.localhost/p{$path}";
    }

    public function test_login_page_renders(): void
    {
        $response = $this->get($this->portalUrl('/login'));

        $response->assertStatus(200);
    }

    public function test_customer_can_login_with_email_and_password(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'email' => 'customer@example.com',
            'password' => bcrypt('password'),
        ]);

        $response = $this->post($this->portalUrl('/login'), [
            'email' => 'customer@example.com',
            'password' => 'password',
        ]);

        $response->assertRedirect();
        $this->assertAuthenticatedAs($customer, 'customer');
    }

    public function test_customer_cannot_login_with_wrong_password(): void
    {
        Customer::factory()->create([
            'store_id' => $this->store->id,
            'email' => 'customer@example.com',
            'password' => bcrypt('password'),
        ]);

        $response = $this->post($this->portalUrl('/login'), [
            'email' => 'customer@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest('customer');
    }

    public function test_customer_cannot_login_to_wrong_store(): void
    {
        $otherStore = Store::factory()->create(['slug' => 'other-store']);

        Customer::factory()->create([
            'store_id' => $otherStore->id,
            'email' => 'customer@example.com',
            'password' => bcrypt('password'),
        ]);

        $response = $this->post($this->portalUrl('/login'), [
            'email' => 'customer@example.com',
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest('customer');
    }

    public function test_customer_can_logout(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'password' => bcrypt('password'),
        ]);

        $this->actingAs($customer, 'customer');

        $response = $this->post($this->portalUrl('/logout'));

        $response->assertRedirect();
        $this->assertGuest('customer');
    }

    public function test_admin_session_is_separate_from_customer_session(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'password' => bcrypt('password'),
        ]);

        // Login as admin
        $this->actingAs($user, 'web');

        // Login as customer
        $this->actingAs($customer, 'customer');

        // Both should be authenticated on their respective guards
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertAuthenticatedAs($customer, 'customer');
    }
}
