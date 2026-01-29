<?php

namespace Tests\Feature\Portal;

use App\Models\Customer;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalTransactionTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Customer $customer;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['slug' => 'test-store', 'user_id' => $this->user->id]);
        $this->customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'password' => bcrypt('password'),
        ]);
    }

    protected function portalUrl(string $path = ''): string
    {
        return "http://{$this->store->slug}.portal.localhost/p{$path}";
    }

    public function test_customer_can_see_their_transactions(): void
    {
        Transaction::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'user_id' => $this->user->id,
            'type' => Transaction::TYPE_MAIL_IN,
        ]);

        $response = $this->actingAs($this->customer, 'customer')
            ->get($this->portalUrl('/'));

        $response->assertStatus(200);
    }

    public function test_customer_cannot_see_other_customers_transactions(): void
    {
        $otherCustomer = Customer::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $otherCustomer->id,
            'user_id' => $this->user->id,
            'type' => Transaction::TYPE_MAIL_IN,
        ]);

        $response = $this->actingAs($this->customer, 'customer')
            ->get($this->portalUrl("/transactions/{$transaction->id}"));

        $response->assertStatus(403);
    }

    public function test_customer_cannot_see_other_stores_transactions(): void
    {
        $otherStore = Store::factory()->create(['slug' => 'other-store', 'user_id' => $this->user->id]);

        $transaction = Transaction::factory()->create([
            'store_id' => $otherStore->id,
            'customer_id' => $this->customer->id,
            'user_id' => $this->user->id,
            'type' => Transaction::TYPE_MAIL_IN,
        ]);

        $response = $this->actingAs($this->customer, 'customer')
            ->get($this->portalUrl("/transactions/{$transaction->id}"));

        $response->assertStatus(403);
    }

    public function test_unauthenticated_customer_is_redirected_to_login(): void
    {
        $response = $this->get($this->portalUrl('/'));

        $response->assertRedirect();
    }
}
