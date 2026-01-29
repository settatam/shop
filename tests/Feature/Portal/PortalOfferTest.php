<?php

namespace Tests\Feature\Portal;

use App\Models\Customer;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalOfferTest extends TestCase
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

    public function test_customer_can_accept_offer(): void
    {
        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'user_id' => $this->user->id,
            'status' => Transaction::STATUS_OFFER_GIVEN,
            'type' => Transaction::TYPE_MAIL_IN,
            'final_offer' => 100.00,
        ]);

        $offer = TransactionOffer::factory()->create([
            'transaction_id' => $transaction->id,
            'user_id' => $this->user->id,
            'amount' => 100.00,
            'status' => TransactionOffer::STATUS_PENDING,
        ]);

        $response = $this->actingAs($this->customer, 'customer')
            ->post($this->portalUrl("/transactions/{$transaction->id}/accept"));

        $response->assertSessionHas('success');

        $transaction->refresh();
        $this->assertEquals(Transaction::STATUS_OFFER_ACCEPTED, $transaction->status);

        $offer->refresh();
        $this->assertEquals(TransactionOffer::STATUS_ACCEPTED, $offer->status);
        $this->assertEquals($this->customer->id, $offer->responded_by_customer_id);
    }

    public function test_customer_can_decline_offer_with_reason(): void
    {
        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'user_id' => $this->user->id,
            'status' => Transaction::STATUS_OFFER_GIVEN,
            'type' => Transaction::TYPE_MAIL_IN,
            'final_offer' => 100.00,
        ]);

        $offer = TransactionOffer::factory()->create([
            'transaction_id' => $transaction->id,
            'user_id' => $this->user->id,
            'amount' => 100.00,
            'status' => TransactionOffer::STATUS_PENDING,
        ]);

        $response = $this->actingAs($this->customer, 'customer')
            ->post($this->portalUrl("/transactions/{$transaction->id}/decline"), [
                'reason' => 'Too low',
            ]);

        $response->assertSessionHas('success');

        $transaction->refresh();
        $this->assertEquals(Transaction::STATUS_OFFER_DECLINED, $transaction->status);

        $offer->refresh();
        $this->assertEquals(TransactionOffer::STATUS_DECLINED, $offer->status);
        $this->assertEquals($this->customer->id, $offer->responded_by_customer_id);
        $this->assertEquals('Too low', $offer->customer_response);
    }

    public function test_customer_cannot_accept_non_pending_offer(): void
    {
        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'user_id' => $this->user->id,
            'status' => Transaction::STATUS_PENDING,
            'type' => Transaction::TYPE_MAIL_IN,
        ]);

        $response = $this->actingAs($this->customer, 'customer')
            ->post($this->portalUrl("/transactions/{$transaction->id}/accept"));

        $response->assertSessionHasErrors('offer');
    }
}
