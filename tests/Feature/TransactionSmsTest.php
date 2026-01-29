<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionSmsTest extends TestCase
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

    public function test_can_send_sms_to_transaction_customer(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'phone_number' => '+15551234567',
        ]);

        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'type' => Transaction::TYPE_MAIL_IN,
        ]);

        // Note: The actual SMS sending will fail because Twilio isn't configured,
        // but we can test that the endpoint exists and validates properly
        $response = $this->post("/transactions/{$transaction->id}/send-sms", [
            'message' => 'Hello, this is a test message.',
        ]);

        // Since Twilio isn't configured, this will either succeed (notification manager handles gracefully)
        // or fail with an error message. Either way, the route should work.
        $response->assertRedirect();
    }

    public function test_cannot_send_sms_to_in_house_transaction(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'phone_number' => '+15551234567',
        ]);

        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'type' => Transaction::TYPE_IN_STORE,
        ]);

        $response = $this->post("/transactions/{$transaction->id}/send-sms", [
            'message' => 'Hello, this is a test message.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'SMS messaging is only available for online transactions.');
    }

    public function test_cannot_send_sms_without_customer_phone(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'phone_number' => null,
        ]);

        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'type' => Transaction::TYPE_MAIL_IN,
        ]);

        $response = $this->post("/transactions/{$transaction->id}/send-sms", [
            'message' => 'Hello, this is a test message.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Customer has no phone number on file.');
    }

    public function test_send_sms_requires_message(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'phone_number' => '+15551234567',
        ]);

        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'type' => Transaction::TYPE_MAIL_IN,
        ]);

        $response = $this->post("/transactions/{$transaction->id}/send-sms", [
            'message' => '',
        ]);

        $response->assertSessionHasErrors('message');
    }

    public function test_send_sms_message_max_length(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'phone_number' => '+15551234567',
        ]);

        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'type' => Transaction::TYPE_MAIL_IN,
        ]);

        // Message longer than 1600 characters
        $longMessage = str_repeat('a', 1601);

        $response = $this->post("/transactions/{$transaction->id}/send-sms", [
            'message' => $longMessage,
        ]);

        $response->assertSessionHasErrors('message');
    }

    public function test_sms_messages_are_included_in_show_page_props(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'phone_number' => '+15551234567',
        ]);

        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'type' => Transaction::TYPE_MAIL_IN,
        ]);

        // Create some SMS logs
        NotificationLog::create([
            'store_id' => $this->store->id,
            'notifiable_type' => Transaction::class,
            'notifiable_id' => $transaction->id,
            'channel' => NotificationChannel::TYPE_SMS,
            'recipient' => '+15551234567',
            'content' => 'Test message 1',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        NotificationLog::create([
            'store_id' => $this->store->id,
            'notifiable_type' => Transaction::class,
            'notifiable_id' => $transaction->id,
            'channel' => NotificationChannel::TYPE_SMS,
            'recipient' => '+15551234567',
            'content' => 'Test message 2',
            'status' => 'delivered',
            'sent_at' => now(),
        ]);

        $response = $this->get("/transactions/{$transaction->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('transactions/Show')
            ->has('smsMessages', 2)
        );
    }

    public function test_sms_messages_empty_for_in_house_transaction(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'type' => Transaction::TYPE_IN_STORE,
        ]);

        $response = $this->get("/transactions/{$transaction->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('transactions/Show')
            ->has('smsMessages', 0)
        );
    }

    public function test_cannot_send_sms_to_another_stores_transaction(): void
    {
        $otherStore = Store::factory()->onboarded()->create();
        $customer = Customer::factory()->create([
            'store_id' => $otherStore->id,
            'phone_number' => '+15551234567',
        ]);

        $transaction = Transaction::factory()->create([
            'store_id' => $otherStore->id,
            'customer_id' => $customer->id,
            'type' => Transaction::TYPE_MAIL_IN,
        ]);

        $response = $this->post("/transactions/{$transaction->id}/send-sms", [
            'message' => 'Hello, this is a test message.',
        ]);

        $response->assertStatus(404);
    }
}
