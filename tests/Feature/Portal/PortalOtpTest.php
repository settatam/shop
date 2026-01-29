<?php

namespace Tests\Feature\Portal;

use App\Jobs\SendCustomerOtpJob;
use App\Models\Customer;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PortalOtpTest extends TestCase
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

    public function test_otp_can_be_sent(): void
    {
        Bus::fake();

        Customer::factory()->create([
            'store_id' => $this->store->id,
            'phone_number' => '+15551234567',
        ]);

        $response = $this->post($this->portalUrl('/otp/send'), [
            'phone' => '+15551234567',
        ]);

        $response->assertSessionHas('otpSent', true);
        Bus::assertDispatched(SendCustomerOtpJob::class);
    }

    public function test_otp_fails_for_unknown_phone(): void
    {
        Bus::fake();

        $response = $this->post($this->portalUrl('/otp/send'), [
            'phone' => '+15559999999',
        ]);

        $response->assertSessionHasErrors('phone');
        Bus::assertNotDispatched(SendCustomerOtpJob::class);
    }

    public function test_otp_can_be_verified(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'phone_number' => '+15551234567',
        ]);

        $code = '123456';
        Cache::put('customer_otp:+15551234567', Hash::make($code), 600);

        $response = $this->post($this->portalUrl('/otp/verify'), [
            'phone' => '+15551234567',
            'code' => $code,
        ]);

        $response->assertRedirect();
        $this->assertAuthenticatedAs($customer, 'customer');
    }

    public function test_otp_fails_with_wrong_code(): void
    {
        Customer::factory()->create([
            'store_id' => $this->store->id,
            'phone_number' => '+15551234567',
        ]);

        Cache::put('customer_otp:+15551234567', Hash::make('123456'), 600);

        $response = $this->post($this->portalUrl('/otp/verify'), [
            'phone' => '+15551234567',
            'code' => '000000',
        ]);

        $response->assertSessionHasErrors('code');
        $this->assertGuest('customer');
    }

    public function test_otp_fails_when_expired(): void
    {
        Customer::factory()->create([
            'store_id' => $this->store->id,
            'phone_number' => '+15551234567',
        ]);

        // No cache entry = expired

        $response = $this->post($this->portalUrl('/otp/verify'), [
            'phone' => '+15551234567',
            'code' => '123456',
        ]);

        $response->assertSessionHasErrors('code');
        $this->assertGuest('customer');
    }
}
