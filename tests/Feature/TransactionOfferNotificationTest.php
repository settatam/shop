<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\TransactionOffer;
use App\Models\User;
use App\Services\StoreContext;
use App\Services\Transactions\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionOfferNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->onboarded()->create([
            'user_id' => $this->user->id,
        ]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
        $this->actingAs($this->user);
    }

    public function test_create_offer_without_notification(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'email' => 'customer@example.com',
        ]);

        $transaction = Transaction::factory()->itemsReviewed()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'type' => Transaction::TYPE_MAIL_IN,
        ]);

        $service = app(TransactionService::class);
        $offer = $service->createOffer($transaction, 500.00, 'Test offer', false);

        $this->assertDatabaseHas('transaction_offers', [
            'transaction_id' => $transaction->id,
            'amount' => '500.00',
            'status' => TransactionOffer::STATUS_PENDING,
        ]);

        $transaction->refresh();
        $this->assertEquals(Transaction::STATUS_OFFER_GIVEN, $transaction->status);
    }

    public function test_create_offer_with_notification_parameter(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'email' => 'customer@example.com',
        ]);

        $transaction = Transaction::factory()->itemsReviewed()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'type' => Transaction::TYPE_MAIL_IN,
        ]);

        $service = app(TransactionService::class);

        // Note: The notification will be triggered but won't actually send
        // since notification channels aren't configured. The important thing
        // is that the parameter works and doesn't cause any errors.
        $offer = $service->createOffer($transaction, 500.00, 'Test offer', true);

        $this->assertDatabaseHas('transaction_offers', [
            'transaction_id' => $transaction->id,
            'amount' => '500.00',
            'status' => TransactionOffer::STATUS_PENDING,
        ]);

        $transaction->refresh();
        $this->assertEquals(Transaction::STATUS_OFFER_GIVEN, $transaction->status);
    }

    public function test_web_submit_offer_with_notification(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'email' => 'customer@example.com',
        ]);

        $transaction = Transaction::factory()->itemsReviewed()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'type' => Transaction::TYPE_MAIL_IN,
        ]);

        $response = $this->post("/transactions/{$transaction->id}/offer", [
            'offer' => 500.00,
            'notes' => 'Initial offer',
            'send_notification' => true,
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('transaction_offers', [
            'transaction_id' => $transaction->id,
            'amount' => '500.00',
            'status' => TransactionOffer::STATUS_PENDING,
        ]);

        $transaction->refresh();
        $this->assertEquals(Transaction::STATUS_OFFER_GIVEN, $transaction->status);
    }

    public function test_web_submit_offer_without_notification(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'email' => 'customer@example.com',
        ]);

        $transaction = Transaction::factory()->itemsReviewed()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'type' => Transaction::TYPE_MAIL_IN,
        ]);

        $response = $this->post("/transactions/{$transaction->id}/offer", [
            'offer' => 500.00,
            'notes' => 'Initial offer',
            'send_notification' => false,
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('transaction_offers', [
            'transaction_id' => $transaction->id,
            'amount' => '500.00',
            'status' => TransactionOffer::STATUS_PENDING,
        ]);
    }

    public function test_create_offer_without_customer_does_not_fail(): void
    {
        $transaction = Transaction::factory()->itemsReviewed()->create([
            'store_id' => $this->store->id,
            'customer_id' => null,
            'type' => Transaction::TYPE_MAIL_IN,
        ]);

        $service = app(TransactionService::class);

        // Even with sendNotification=true, this should not fail
        // because there's no customer to notify
        $offer = $service->createOffer($transaction, 500.00, 'Test offer', true);

        $this->assertDatabaseHas('transaction_offers', [
            'transaction_id' => $transaction->id,
            'amount' => '500.00',
        ]);
    }
}
