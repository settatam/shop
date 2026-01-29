<?php

namespace Tests\Feature\Portal;

use App\Models\Customer;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PortalInviteTest extends TestCase
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

    public function test_invite_page_renders_with_valid_token(): void
    {
        $token = Str::random(64);

        Customer::factory()->create([
            'store_id' => $this->store->id,
            'portal_invite_token' => $token,
        ]);

        $response = $this->get($this->portalUrl("/invite/{$token}"));

        $response->assertStatus(200);
    }

    public function test_invite_page_returns_404_for_invalid_token(): void
    {
        $response = $this->get($this->portalUrl('/invite/invalid-token'));

        $response->assertStatus(404);
    }

    public function test_accepting_invite_sets_password_and_logs_in(): void
    {
        $token = Str::random(64);

        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'portal_invite_token' => $token,
            'password' => null,
        ]);

        $response = $this->post($this->portalUrl("/invite/{$token}"), [
            'password' => 'newpassword',
            'password_confirmation' => 'newpassword',
        ]);

        $response->assertRedirect();
        $this->assertAuthenticatedAs($customer, 'customer');

        $customer->refresh();
        $this->assertNull($customer->portal_invite_token);
        $this->assertNotNull($customer->password);
    }
}
