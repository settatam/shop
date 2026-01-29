<?php

namespace Tests\Feature\Portal;

use App\Models\Activity;
use App\Models\Customer;
use App\Models\StatusHistory;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalPayoutPreferenceTest extends TestCase
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

    private function createTransaction(string $status = Transaction::STATUS_OFFER_ACCEPTED): Transaction
    {
        return Transaction::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'user_id' => $this->user->id,
            'status' => $status,
            'type' => Transaction::TYPE_MAIL_IN,
            'final_offer' => 500.00,
        ]);
    }

    public function test_customer_can_update_payout_to_check(): void
    {
        $transaction = $this->createTransaction();

        $response = $this->actingAs($this->customer, 'customer')
            ->put($this->portalUrl("/transactions/{$transaction->id}/payout-preference"), [
                'payments' => [[
                    'method' => 'check',
                    'amount' => 500,
                    'details' => [
                        'mailing_name' => 'John Doe',
                        'mailing_address' => '123 Main St',
                        'mailing_city' => 'Springfield',
                        'mailing_state' => 'IL',
                        'mailing_zip' => '62701',
                    ],
                ]],
            ]);

        $response->assertSessionHas('success');

        $transaction->refresh();
        $this->assertEquals('check', $transaction->payment_method);
        $this->assertCount(1, $transaction->payment_details);
        $this->assertEquals('check', $transaction->payment_details[0]['method']);
    }

    public function test_customer_can_update_payout_to_paypal(): void
    {
        $transaction = $this->createTransaction();

        $response = $this->actingAs($this->customer, 'customer')
            ->put($this->portalUrl("/transactions/{$transaction->id}/payout-preference"), [
                'payments' => [[
                    'method' => 'paypal',
                    'amount' => 500,
                    'details' => [
                        'paypal_email' => 'john@example.com',
                    ],
                ]],
            ]);

        $response->assertSessionHas('success');

        $transaction->refresh();
        $this->assertEquals('paypal', $transaction->payment_method);
    }

    public function test_customer_can_update_payout_to_venmo(): void
    {
        $transaction = $this->createTransaction();

        $response = $this->actingAs($this->customer, 'customer')
            ->put($this->portalUrl("/transactions/{$transaction->id}/payout-preference"), [
                'payments' => [[
                    'method' => 'venmo',
                    'amount' => 500,
                    'details' => [
                        'venmo_handle' => '@johndoe',
                    ],
                ]],
            ]);

        $response->assertSessionHas('success');

        $transaction->refresh();
        $this->assertEquals('venmo', $transaction->payment_method);
    }

    public function test_customer_can_update_payout_to_ach(): void
    {
        $transaction = $this->createTransaction();

        $response = $this->actingAs($this->customer, 'customer')
            ->put($this->portalUrl("/transactions/{$transaction->id}/payout-preference"), [
                'payments' => [[
                    'method' => 'ach',
                    'amount' => 500,
                    'details' => [
                        'bank_name' => 'First National',
                        'account_name' => 'John Doe',
                        'account_number' => '123456789',
                        'routing_number' => '987654321',
                    ],
                ]],
            ]);

        $response->assertSessionHas('success');

        $transaction->refresh();
        $this->assertEquals('ach', $transaction->payment_method);
    }

    public function test_customer_can_submit_split_payment(): void
    {
        $transaction = $this->createTransaction();

        $response = $this->actingAs($this->customer, 'customer')
            ->put($this->portalUrl("/transactions/{$transaction->id}/payout-preference"), [
                'payments' => [
                    [
                        'method' => 'paypal',
                        'amount' => 300,
                        'details' => ['paypal_email' => 'john@example.com'],
                    ],
                    [
                        'method' => 'venmo',
                        'amount' => 200,
                        'details' => ['venmo_handle' => '@johndoe'],
                    ],
                ],
            ]);

        $response->assertSessionHas('success');

        $transaction->refresh();
        $this->assertEquals('paypal', $transaction->payment_method);
        $this->assertCount(2, $transaction->payment_details);
    }

    public function test_validation_fails_for_missing_check_details(): void
    {
        $transaction = $this->createTransaction();

        $response = $this->actingAs($this->customer, 'customer')
            ->put($this->portalUrl("/transactions/{$transaction->id}/payout-preference"), [
                'payments' => [[
                    'method' => 'check',
                    'amount' => 500,
                    'details' => [],
                ]],
            ]);

        $response->assertSessionHasErrors();
    }

    public function test_validation_fails_for_missing_paypal_email(): void
    {
        $transaction = $this->createTransaction();

        $response = $this->actingAs($this->customer, 'customer')
            ->put($this->portalUrl("/transactions/{$transaction->id}/payout-preference"), [
                'payments' => [[
                    'method' => 'paypal',
                    'amount' => 500,
                    'details' => [],
                ]],
            ]);

        $response->assertSessionHasErrors();
    }

    public function test_validation_fails_for_missing_ach_details(): void
    {
        $transaction = $this->createTransaction();

        $response = $this->actingAs($this->customer, 'customer')
            ->put($this->portalUrl("/transactions/{$transaction->id}/payout-preference"), [
                'payments' => [[
                    'method' => 'ach',
                    'amount' => 500,
                    'details' => [],
                ]],
            ]);

        $response->assertSessionHasErrors();
    }

    public function test_wrong_customer_gets_403(): void
    {
        $otherCustomer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'password' => bcrypt('password'),
        ]);

        $transaction = $this->createTransaction();

        $response = $this->actingAs($otherCustomer, 'customer')
            ->put($this->portalUrl("/transactions/{$transaction->id}/payout-preference"), [
                'payments' => [[
                    'method' => 'paypal',
                    'amount' => 500,
                    'details' => ['paypal_email' => 'other@example.com'],
                ]],
            ]);

        $response->assertStatus(403);
    }

    public function test_rejected_when_transaction_already_payment_processed(): void
    {
        $transaction = $this->createTransaction(Transaction::STATUS_PAYMENT_PROCESSED);

        $response = $this->actingAs($this->customer, 'customer')
            ->put($this->portalUrl("/transactions/{$transaction->id}/payout-preference"), [
                'payments' => [[
                    'method' => 'paypal',
                    'amount' => 500,
                    'details' => ['paypal_email' => 'john@example.com'],
                ]],
            ]);

        $response->assertSessionHasErrors('status');
    }

    public function test_activity_log_is_created(): void
    {
        $transaction = $this->createTransaction();

        $this->actingAs($this->customer, 'customer')
            ->put($this->portalUrl("/transactions/{$transaction->id}/payout-preference"), [
                'payments' => [[
                    'method' => 'paypal',
                    'amount' => 500,
                    'details' => ['paypal_email' => 'john@example.com'],
                ]],
            ]);

        $this->assertDatabaseHas('activity_logs', [
            'activity_slug' => Activity::TRANSACTIONS_UPDATE_PAYOUT_PREFERENCE,
            'subject_type' => Transaction::class,
            'subject_id' => $transaction->id,
            'causer_type' => Customer::class,
            'causer_id' => $this->customer->id,
        ]);
    }

    public function test_status_history_entry_is_created_with_notes(): void
    {
        $transaction = $this->createTransaction();

        $this->actingAs($this->customer, 'customer')
            ->put($this->portalUrl("/transactions/{$transaction->id}/payout-preference"), [
                'payments' => [[
                    'method' => 'venmo',
                    'amount' => 500,
                    'details' => ['venmo_handle' => '@johndoe'],
                ]],
            ]);

        $this->assertDatabaseHas('status_histories', [
            'trackable_type' => Transaction::class,
            'trackable_id' => $transaction->id,
            'from_status' => Transaction::STATUS_OFFER_ACCEPTED,
            'to_status' => Transaction::STATUS_OFFER_ACCEPTED,
        ]);

        $entry = StatusHistory::where('trackable_id', $transaction->id)
            ->where('trackable_type', Transaction::class)
            ->where('from_status', Transaction::STATUS_OFFER_ACCEPTED)
            ->where('to_status', Transaction::STATUS_OFFER_ACCEPTED)
            ->first();

        $this->assertNotNull($entry);
        $this->assertStringContainsString('Venmo', $entry->notes);
    }

    public function test_payment_pending_status_allows_update(): void
    {
        $transaction = $this->createTransaction(Transaction::STATUS_PAYMENT_PENDING);

        $response = $this->actingAs($this->customer, 'customer')
            ->put($this->portalUrl("/transactions/{$transaction->id}/payout-preference"), [
                'payments' => [[
                    'method' => 'paypal',
                    'amount' => 500,
                    'details' => ['paypal_email' => 'john@example.com'],
                ]],
            ]);

        $response->assertSessionHas('success');
    }
}
