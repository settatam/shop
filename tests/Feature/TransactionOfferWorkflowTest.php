<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\ShippingLabel;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\TransactionOffer;
use App\Models\User;
use App\Services\Shipping\ShippingLabelService;
use App\Services\StoreContext;
use App\Services\Transactions\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionOfferWorkflowTest extends TestCase
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
            'address' => '123 Main St',
            'city' => 'New York',
            'state' => 'NY',
            'zip' => '10001',
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

    public function test_can_create_offer_for_transaction(): void
    {
        $transaction = Transaction::factory()->itemsReviewed()->create([
            'store_id' => $this->store->id,
            'type' => Transaction::TYPE_MAIL_IN,
        ]);

        $service = app(TransactionService::class);
        $offer = $service->createOffer($transaction, 500.00, 'Initial offer');

        $this->assertDatabaseHas('transaction_offers', [
            'transaction_id' => $transaction->id,
            'amount' => '500.00',
            'status' => TransactionOffer::STATUS_PENDING,
            'admin_notes' => 'Initial offer',
        ]);

        $transaction->refresh();
        $this->assertEquals(Transaction::STATUS_OFFER_GIVEN, $transaction->status);
        $this->assertEquals('500.00', $transaction->final_offer);
    }

    public function test_can_accept_offer(): void
    {
        $transaction = Transaction::factory()->offerGiven()->create([
            'store_id' => $this->store->id,
            'type' => Transaction::TYPE_MAIL_IN,
        ]);

        $offer = TransactionOffer::factory()->pending()->create([
            'transaction_id' => $transaction->id,
            'amount' => 500.00,
        ]);

        $service = app(TransactionService::class);
        $service->acceptOfferWithTracking($transaction, $offer);

        $offer->refresh();
        $transaction->refresh();

        $this->assertEquals(TransactionOffer::STATUS_ACCEPTED, $offer->status);
        $this->assertEquals(Transaction::STATUS_OFFER_ACCEPTED, $transaction->status);
        $this->assertNotNull($offer->responded_at);
    }

    public function test_can_decline_offer(): void
    {
        $transaction = Transaction::factory()->offerGiven()->create([
            'store_id' => $this->store->id,
            'type' => Transaction::TYPE_MAIL_IN,
        ]);

        $offer = TransactionOffer::factory()->pending()->create([
            'transaction_id' => $transaction->id,
            'amount' => 500.00,
        ]);

        $service = app(TransactionService::class);
        $service->declineOfferWithTracking($transaction, $offer, 'Too low');

        $offer->refresh();
        $transaction->refresh();

        $this->assertEquals(TransactionOffer::STATUS_DECLINED, $offer->status);
        $this->assertEquals(Transaction::STATUS_OFFER_DECLINED, $transaction->status);
        $this->assertEquals('Too low', $offer->customer_response);
    }

    public function test_new_offer_supersedes_previous(): void
    {
        $transaction = Transaction::factory()->offerDeclined()->create([
            'store_id' => $this->store->id,
            'type' => Transaction::TYPE_MAIL_IN,
        ]);

        // Create first offer (already declined)
        $firstOffer = TransactionOffer::factory()->declined()->create([
            'transaction_id' => $transaction->id,
            'amount' => 400.00,
        ]);

        // Create a pending offer to be superseded
        $secondOffer = TransactionOffer::factory()->pending()->create([
            'transaction_id' => $transaction->id,
            'amount' => 450.00,
        ]);

        // Create new counter-offer
        $service = app(TransactionService::class);
        $thirdOffer = $service->createOffer($transaction, 475.00, 'Counter offer');

        $secondOffer->refresh();

        $this->assertEquals(TransactionOffer::STATUS_SUPERSEDED, $secondOffer->status);
        $this->assertEquals(TransactionOffer::STATUS_PENDING, $thirdOffer->status);
        $this->assertEquals('475.00', $transaction->fresh()->final_offer);
    }

    public function test_can_reject_kit_after_items_received(): void
    {
        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'type' => Transaction::TYPE_MAIL_IN,
            'status' => Transaction::STATUS_ITEMS_RECEIVED,
        ]);

        $service = app(TransactionService::class);
        $service->rejectKit($transaction, 'Items not as described');

        $transaction->refresh();

        $this->assertEquals(Transaction::STATUS_KIT_REQUEST_REJECTED, $transaction->status);
    }

    public function test_can_initiate_return_after_offer_declined(): void
    {
        $transaction = Transaction::factory()->offerDeclined()->create([
            'store_id' => $this->store->id,
            'type' => Transaction::TYPE_MAIL_IN,
        ]);

        $service = app(TransactionService::class);
        $service->initiateReturn($transaction);

        $transaction->refresh();

        $this->assertEquals(Transaction::STATUS_RETURN_REQUESTED, $transaction->status);
    }

    public function test_web_submit_offer_creates_offer_record(): void
    {
        $transaction = Transaction::factory()->itemsReviewed()->create([
            'store_id' => $this->store->id,
            'type' => Transaction::TYPE_MAIL_IN,
        ]);

        $response = $this->post("/transactions/{$transaction->id}/offer", [
            'offer' => 500.00,
            'notes' => 'Test offer',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('transaction_offers', [
            'transaction_id' => $transaction->id,
            'amount' => '500.00',
            'status' => TransactionOffer::STATUS_PENDING,
        ]);
    }

    public function test_web_accept_offer_requires_offer_id(): void
    {
        $transaction = Transaction::factory()->offerGiven()->create([
            'store_id' => $this->store->id,
        ]);

        $offer = TransactionOffer::factory()->pending()->create([
            'transaction_id' => $transaction->id,
            'amount' => 500.00,
            'user_id' => $this->user->id,
        ]);

        // Update the transaction to have can_accept_offer return true
        $transaction->update([
            'status' => Transaction::STATUS_OFFER_GIVEN,
            'final_offer' => 500.00,
        ]);

        $response = $this->from("/transactions/{$transaction->id}")
            ->post("/transactions/{$transaction->id}/accept", [
                'offer_id' => $offer->id,
            ]);

        $response->assertRedirect("/transactions/{$transaction->id}");

        $offer->refresh();
        $this->assertEquals(TransactionOffer::STATUS_ACCEPTED, $offer->status);
    }

    public function test_web_decline_offer_requires_offer_id(): void
    {
        $transaction = Transaction::factory()->offerGiven()->create([
            'store_id' => $this->store->id,
        ]);

        $offer = TransactionOffer::factory()->pending()->create([
            'transaction_id' => $transaction->id,
            'amount' => 500.00,
            'user_id' => $this->user->id,
        ]);

        $response = $this->from("/transactions/{$transaction->id}")
            ->post("/transactions/{$transaction->id}/decline", [
                'offer_id' => $offer->id,
                'reason' => 'Customer wants more',
            ]);

        $response->assertRedirect("/transactions/{$transaction->id}");

        $offer->refresh();
        $this->assertEquals(TransactionOffer::STATUS_DECLINED, $offer->status);
        $this->assertEquals('Customer wants more', $offer->customer_response);
    }

    public function test_shipping_label_service_is_configured_check(): void
    {
        $service = app(ShippingLabelService::class);

        // FedEx is not configured in test environment
        $this->assertFalse($service->isConfigured());
    }

    public function test_transaction_has_offers_relationship(): void
    {
        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
        ]);

        TransactionOffer::factory()->count(3)->create([
            'transaction_id' => $transaction->id,
        ]);

        $this->assertCount(3, $transaction->offers);
    }

    public function test_transaction_has_shipping_labels_relationship(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'address' => '456 Oak St',
            'city' => 'Los Angeles',
            'zip' => '90001',
        ]);

        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
        ]);

        ShippingLabel::factory()->outbound()->create([
            'store_id' => $this->store->id,
            'shippable_type' => Transaction::class,
            'shippable_id' => $transaction->id,
        ]);

        ShippingLabel::factory()->return()->create([
            'store_id' => $this->store->id,
            'shippable_type' => Transaction::class,
            'shippable_id' => $transaction->id,
        ]);

        $this->assertCount(2, $transaction->shippingLabels);
        $this->assertNotNull($transaction->outboundLabel);
        $this->assertNotNull($transaction->returnLabel);
    }

    public function test_shipping_label_has_tracking_url(): void
    {
        $label = ShippingLabel::factory()->make([
            'carrier' => ShippingLabel::CARRIER_FEDEX,
            'tracking_number' => '123456789012',
        ]);

        $this->assertStringContainsString('123456789012', $label->getTrackingUrl());
        $this->assertStringContainsString('fedex.com', $label->getTrackingUrl());
    }

    public function test_transaction_offer_has_user_relationship(): void
    {
        $offer = TransactionOffer::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $this->assertNotNull($offer->user);
        $this->assertEquals($this->user->id, $offer->user->id);
    }

    public function test_can_submit_counter_offer_after_decline(): void
    {
        // Start with a declined offer
        $transaction = Transaction::factory()->offerDeclined()->create([
            'store_id' => $this->store->id,
            'type' => Transaction::TYPE_MAIL_IN,
        ]);

        // Verify canSubmitOffer returns true for declined transactions
        $this->assertTrue($transaction->canSubmitOffer());

        // Submit counter-offer
        $service = app(TransactionService::class);
        $offer = $service->createOffer($transaction, 550.00, 'Better offer');

        $transaction->refresh();

        $this->assertEquals(Transaction::STATUS_OFFER_GIVEN, $transaction->status);
        $this->assertEquals('550.00', $transaction->final_offer);
    }

    public function test_cannot_accept_already_responded_offer(): void
    {
        $transaction = Transaction::factory()->offerGiven()->create([
            'store_id' => $this->store->id,
        ]);

        $offer = TransactionOffer::factory()->accepted()->create([
            'transaction_id' => $transaction->id,
        ]);

        $service = app(TransactionService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->acceptOfferWithTracking($transaction, $offer);
    }

    public function test_cannot_decline_already_responded_offer(): void
    {
        $transaction = Transaction::factory()->offerGiven()->create([
            'store_id' => $this->store->id,
        ]);

        $offer = TransactionOffer::factory()->declined()->create([
            'transaction_id' => $transaction->id,
        ]);

        $service = app(TransactionService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->declineOfferWithTracking($transaction, $offer);
    }

    public function test_web_reject_kit_action(): void
    {
        $transaction = Transaction::factory()->mailIn()->create([
            'store_id' => $this->store->id,
            'status' => Transaction::STATUS_ITEMS_RECEIVED,
        ]);

        $response = $this->from("/transactions/{$transaction->id}")
            ->post("/transactions/{$transaction->id}/reject-kit", [
                'reason' => 'Items damaged',
            ]);

        $response->assertRedirect("/transactions/{$transaction->id}");

        $transaction->refresh();
        $this->assertEquals(Transaction::STATUS_KIT_REQUEST_REJECTED, $transaction->status);
    }

    public function test_web_initiate_return_action(): void
    {
        $transaction = Transaction::factory()->mailIn()->offerDeclined()->create([
            'store_id' => $this->store->id,
        ]);

        $response = $this->from("/transactions/{$transaction->id}")
            ->post("/transactions/{$transaction->id}/initiate-return");

        $response->assertRedirect("/transactions/{$transaction->id}");

        $transaction->refresh();
        $this->assertEquals(Transaction::STATUS_RETURN_REQUESTED, $transaction->status);
    }

    public function test_only_online_transactions_can_have_shipping_labels_created(): void
    {
        $transaction = Transaction::factory()->inHouse()->create([
            'store_id' => $this->store->id,
            'status' => Transaction::STATUS_KIT_REQUEST_CONFIRMED,
        ]);

        $response = $this->from("/transactions/{$transaction->id}")
            ->post("/transactions/{$transaction->id}/create-outbound-label");

        $response->assertRedirect("/transactions/{$transaction->id}");
        $response->assertSessionHas('error');
    }

    // Rollback/Reset Tests

    public function test_can_reset_to_items_reviewed_from_offer_given(): void
    {
        $transaction = Transaction::factory()->offerGiven()->create([
            'store_id' => $this->store->id,
            'type' => Transaction::TYPE_MAIL_IN,
        ]);

        $offer = TransactionOffer::factory()->pending()->create([
            'transaction_id' => $transaction->id,
            'amount' => 500.00,
        ]);

        $service = app(TransactionService::class);
        $service->resetToItemsReviewed($transaction);

        $transaction->refresh();
        $offer->refresh();

        $this->assertEquals(Transaction::STATUS_ITEMS_REVIEWED, $transaction->status);
        $this->assertEquals(TransactionOffer::STATUS_SUPERSEDED, $offer->status);
    }

    public function test_can_reset_to_items_reviewed_from_offer_declined(): void
    {
        $transaction = Transaction::factory()->offerDeclined()->create([
            'store_id' => $this->store->id,
            'type' => Transaction::TYPE_MAIL_IN,
        ]);

        $service = app(TransactionService::class);
        $service->resetToItemsReviewed($transaction);

        $transaction->refresh();
        $this->assertEquals(Transaction::STATUS_ITEMS_REVIEWED, $transaction->status);
    }

    public function test_can_reset_to_items_reviewed_from_return_requested(): void
    {
        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'type' => Transaction::TYPE_MAIL_IN,
            'status' => Transaction::STATUS_RETURN_REQUESTED,
        ]);

        $service = app(TransactionService::class);
        $service->resetToItemsReviewed($transaction);

        $transaction->refresh();
        $this->assertEquals(Transaction::STATUS_ITEMS_REVIEWED, $transaction->status);
    }

    public function test_can_reopen_offer_from_accepted(): void
    {
        $transaction = Transaction::factory()->offerAccepted()->create([
            'store_id' => $this->store->id,
            'type' => Transaction::TYPE_MAIL_IN,
        ]);

        $offer = TransactionOffer::factory()->accepted()->create([
            'transaction_id' => $transaction->id,
            'amount' => 500.00,
        ]);

        $service = app(TransactionService::class);
        $service->reopenOffer($transaction);

        $transaction->refresh();
        $offer->refresh();

        $this->assertEquals(Transaction::STATUS_OFFER_GIVEN, $transaction->status);
        $this->assertEquals(TransactionOffer::STATUS_PENDING, $offer->status);
        $this->assertNull($offer->responded_at);
    }

    public function test_can_reopen_offer_from_declined(): void
    {
        $transaction = Transaction::factory()->offerDeclined()->create([
            'store_id' => $this->store->id,
            'type' => Transaction::TYPE_MAIL_IN,
        ]);

        $offer = TransactionOffer::factory()->declined()->create([
            'transaction_id' => $transaction->id,
            'amount' => 500.00,
        ]);

        $service = app(TransactionService::class);
        $service->reopenOffer($transaction);

        $transaction->refresh();
        $offer->refresh();

        $this->assertEquals(Transaction::STATUS_OFFER_GIVEN, $transaction->status);
        $this->assertEquals(TransactionOffer::STATUS_PENDING, $offer->status);
    }

    public function test_can_cancel_return(): void
    {
        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'type' => Transaction::TYPE_MAIL_IN,
            'status' => Transaction::STATUS_RETURN_REQUESTED,
        ]);

        // Create a declined offer so we know where to go back to
        $offer = TransactionOffer::factory()->declined()->create([
            'transaction_id' => $transaction->id,
            'amount' => 400.00,
        ]);

        $service = app(TransactionService::class);
        $service->cancelReturn($transaction);

        $transaction->refresh();
        $this->assertEquals(Transaction::STATUS_OFFER_DECLINED, $transaction->status);
    }

    public function test_cancel_return_goes_to_items_reviewed_without_offer(): void
    {
        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'type' => Transaction::TYPE_MAIL_IN,
            'status' => Transaction::STATUS_RETURN_REQUESTED,
        ]);

        $service = app(TransactionService::class);
        $service->cancelReturn($transaction);

        $transaction->refresh();
        $this->assertEquals(Transaction::STATUS_ITEMS_REVIEWED, $transaction->status);
    }

    public function test_can_undo_payment(): void
    {
        $transaction = Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
            'type' => Transaction::TYPE_MAIL_IN,
        ]);

        $this->assertNotNull($transaction->payment_method);
        $this->assertNotNull($transaction->payment_processed_at);

        $service = app(TransactionService::class);
        $service->undoPayment($transaction);

        $transaction->refresh();

        $this->assertEquals(Transaction::STATUS_OFFER_ACCEPTED, $transaction->status);
        $this->assertNull($transaction->payment_method);
        $this->assertNull($transaction->payment_processed_at);
    }

    public function test_cannot_reset_from_payment_processed(): void
    {
        $transaction = Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
            'type' => Transaction::TYPE_MAIL_IN,
        ]);

        $service = app(TransactionService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->resetToItemsReviewed($transaction);
    }

    public function test_get_available_rollback_actions(): void
    {
        $service = app(TransactionService::class);

        // Test offer given state
        $transaction = Transaction::factory()->offerGiven()->create([
            'store_id' => $this->store->id,
        ]);
        $actions = $service->getAvailableRollbackActions($transaction);
        $this->assertArrayHasKey('reset_to_items_reviewed', $actions);

        // Test offer declined state
        $transaction2 = Transaction::factory()->offerDeclined()->create([
            'store_id' => $this->store->id,
        ]);
        $actions2 = $service->getAvailableRollbackActions($transaction2);
        $this->assertArrayHasKey('reopen_offer', $actions2);
        $this->assertArrayHasKey('reset_to_items_reviewed', $actions2);

        // Test payment processed state
        $transaction3 = Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
        ]);
        $actions3 = $service->getAvailableRollbackActions($transaction3);
        $this->assertArrayHasKey('undo_payment', $actions3);
    }

    public function test_web_reset_to_items_reviewed(): void
    {
        $transaction = Transaction::factory()->mailIn()->offerGiven()->create([
            'store_id' => $this->store->id,
        ]);

        $response = $this->from("/transactions/{$transaction->id}")
            ->post("/transactions/{$transaction->id}/reset-to-items-reviewed");

        $response->assertRedirect("/transactions/{$transaction->id}");

        $transaction->refresh();
        $this->assertEquals(Transaction::STATUS_ITEMS_REVIEWED, $transaction->status);
    }

    public function test_web_reopen_offer(): void
    {
        $transaction = Transaction::factory()->mailIn()->offerDeclined()->create([
            'store_id' => $this->store->id,
        ]);

        $offer = TransactionOffer::factory()->declined()->create([
            'transaction_id' => $transaction->id,
            'amount' => 500.00,
        ]);

        $response = $this->from("/transactions/{$transaction->id}")
            ->post("/transactions/{$transaction->id}/reopen-offer");

        $response->assertRedirect("/transactions/{$transaction->id}");

        $transaction->refresh();
        $offer->refresh();
        $this->assertEquals(Transaction::STATUS_OFFER_GIVEN, $transaction->status);
        $this->assertEquals(TransactionOffer::STATUS_PENDING, $offer->status);
    }

    public function test_web_cancel_return(): void
    {
        $transaction = Transaction::factory()->mailIn()->create([
            'store_id' => $this->store->id,
            'status' => Transaction::STATUS_RETURN_REQUESTED,
        ]);

        $response = $this->from("/transactions/{$transaction->id}")
            ->post("/transactions/{$transaction->id}/cancel-return");

        $response->assertRedirect("/transactions/{$transaction->id}");

        $transaction->refresh();
        $this->assertEquals(Transaction::STATUS_ITEMS_REVIEWED, $transaction->status);
    }

    public function test_web_undo_payment(): void
    {
        $transaction = Transaction::factory()->mailIn()->paymentProcessed()->create([
            'store_id' => $this->store->id,
        ]);

        $response = $this->from("/transactions/{$transaction->id}")
            ->post("/transactions/{$transaction->id}/undo-payment");

        $response->assertRedirect("/transactions/{$transaction->id}");

        $transaction->refresh();
        $this->assertEquals(Transaction::STATUS_OFFER_ACCEPTED, $transaction->status);
    }

    // Payment Details Tests (Multiple Payments Format)

    public function test_process_payment_with_paypal_stores_email(): void
    {
        $transaction = Transaction::factory()->mailIn()->offerAccepted()->create([
            'store_id' => $this->store->id,
            'final_offer' => 500.00,
        ]);

        $response = $this->from("/transactions/{$transaction->id}")
            ->post("/transactions/{$transaction->id}/process-payment", [
                'payments' => [
                    [
                        'method' => 'paypal',
                        'amount' => 500.00,
                        'details' => ['paypal_email' => 'customer@example.com'],
                    ],
                ],
            ]);

        $response->assertRedirect("/transactions/{$transaction->id}");

        $transaction->refresh();
        $this->assertEquals(Transaction::STATUS_PAYMENT_PROCESSED, $transaction->status);
        $this->assertEquals('paypal', $transaction->payment_method);
        $this->assertEquals('customer@example.com', $transaction->payment_details['email']);
    }

    public function test_process_payment_with_venmo_stores_username(): void
    {
        $transaction = Transaction::factory()->mailIn()->offerAccepted()->create([
            'store_id' => $this->store->id,
            'final_offer' => 500.00,
        ]);

        $response = $this->from("/transactions/{$transaction->id}")
            ->post("/transactions/{$transaction->id}/process-payment", [
                'payments' => [
                    [
                        'method' => 'venmo',
                        'amount' => 500.00,
                        'details' => ['venmo_handle' => '@johndoe'],
                    ],
                ],
            ]);

        $response->assertRedirect("/transactions/{$transaction->id}");

        $transaction->refresh();
        $this->assertEquals('venmo', $transaction->payment_method);
        $this->assertEquals('@johndoe', $transaction->payment_details['username']);
    }

    public function test_process_payment_with_ach_stores_bank_info(): void
    {
        $transaction = Transaction::factory()->mailIn()->offerAccepted()->create([
            'store_id' => $this->store->id,
            'final_offer' => 500.00,
        ]);

        $response = $this->from("/transactions/{$transaction->id}")
            ->post("/transactions/{$transaction->id}/process-payment", [
                'payments' => [
                    [
                        'method' => 'ach',
                        'amount' => 500.00,
                        'details' => [
                            'bank_name' => 'Bank of America',
                            'account_name' => 'John Doe',
                            'account_number' => '1234567890',
                            'routing_number' => '123456789',
                        ],
                    ],
                ],
            ]);

        $response->assertRedirect("/transactions/{$transaction->id}");

        $transaction->refresh();
        $this->assertEquals('ach', $transaction->payment_method);
        $this->assertEquals('Bank of America', $transaction->payment_details['bank_name']);
        $this->assertEquals('John Doe', $transaction->payment_details['account_name']);
        $this->assertEquals('1234567890', $transaction->payment_details['account_number']);
        $this->assertEquals('123456789', $transaction->payment_details['routing_number']);
    }

    public function test_process_payment_with_check_stores_mailing_address(): void
    {
        $transaction = Transaction::factory()->mailIn()->offerAccepted()->create([
            'store_id' => $this->store->id,
            'final_offer' => 500.00,
        ]);

        $response = $this->from("/transactions/{$transaction->id}")
            ->post("/transactions/{$transaction->id}/process-payment", [
                'payments' => [
                    [
                        'method' => 'check',
                        'amount' => 500.00,
                        'details' => [
                            'mailing_name' => 'John Doe',
                            'mailing_address' => '123 Main St',
                            'mailing_city' => 'New York',
                            'mailing_state' => 'NY',
                            'mailing_zip' => '10001',
                        ],
                    ],
                ],
            ]);

        $response->assertRedirect("/transactions/{$transaction->id}");

        $transaction->refresh();
        $this->assertEquals('check', $transaction->payment_method);
        $this->assertEquals('John Doe', $transaction->payment_details['mailing_name']);
        $this->assertEquals('123 Main St', $transaction->payment_details['mailing_address']);
        $this->assertEquals('New York', $transaction->payment_details['mailing_city']);
        $this->assertEquals('NY', $transaction->payment_details['mailing_state']);
        $this->assertEquals('10001', $transaction->payment_details['mailing_zip']);
    }

    public function test_process_payment_with_cash_no_additional_details(): void
    {
        $transaction = Transaction::factory()->mailIn()->offerAccepted()->create([
            'store_id' => $this->store->id,
            'final_offer' => 500.00,
        ]);

        $response = $this->from("/transactions/{$transaction->id}")
            ->post("/transactions/{$transaction->id}/process-payment", [
                'payments' => [
                    [
                        'method' => 'cash',
                        'amount' => 500.00,
                        'details' => [],
                    ],
                ],
            ]);

        $response->assertRedirect("/transactions/{$transaction->id}");

        $transaction->refresh();
        $this->assertEquals('cash', $transaction->payment_method);
        $this->assertNull($transaction->payment_details);
    }

    public function test_process_payment_with_store_credit_no_additional_details(): void
    {
        $transaction = Transaction::factory()->mailIn()->offerAccepted()->create([
            'store_id' => $this->store->id,
            'final_offer' => 500.00,
        ]);

        $response = $this->from("/transactions/{$transaction->id}")
            ->post("/transactions/{$transaction->id}/process-payment", [
                'payments' => [
                    [
                        'method' => 'store_credit',
                        'amount' => 500.00,
                        'details' => [],
                    ],
                ],
            ]);

        $response->assertRedirect("/transactions/{$transaction->id}");

        $transaction->refresh();
        $this->assertEquals('store_credit', $transaction->payment_method);
        $this->assertNull($transaction->payment_details);
    }

    public function test_process_multiple_payments(): void
    {
        $transaction = Transaction::factory()->mailIn()->offerAccepted()->create([
            'store_id' => $this->store->id,
            'final_offer' => 500.00,
        ]);

        $response = $this->from("/transactions/{$transaction->id}")
            ->post("/transactions/{$transaction->id}/process-payment", [
                'payments' => [
                    [
                        'method' => 'cash',
                        'amount' => 200.00,
                        'details' => [],
                    ],
                    [
                        'method' => 'paypal',
                        'amount' => 300.00,
                        'details' => ['paypal_email' => 'customer@example.com'],
                    ],
                ],
            ]);

        $response->assertRedirect("/transactions/{$transaction->id}");

        $transaction->refresh();
        $this->assertEquals(Transaction::STATUS_PAYMENT_PROCESSED, $transaction->status);
        // Primary method is the one with highest amount (paypal: 300)
        $this->assertEquals('paypal', $transaction->payment_method);
        $this->assertTrue($transaction->payment_details['multiple_payments']);
        $this->assertCount(2, $transaction->payment_details['payments']);
    }

    public function test_process_payment_total_must_match_offer(): void
    {
        $transaction = Transaction::factory()->mailIn()->offerAccepted()->create([
            'store_id' => $this->store->id,
            'final_offer' => 500.00,
        ]);

        $response = $this->from("/transactions/{$transaction->id}")
            ->post("/transactions/{$transaction->id}/process-payment", [
                'payments' => [
                    [
                        'method' => 'cash',
                        'amount' => 400.00, // Only $400 when $500 is expected
                        'details' => [],
                    ],
                ],
            ]);

        $response->assertSessionHas('error');
    }

    // PayPal Payout Tests

    public function test_transaction_has_payouts_relationship(): void
    {
        $transaction = Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
        ]);

        $payout = \App\Models\TransactionPayout::factory()->create([
            'store_id' => $this->store->id,
            'transaction_id' => $transaction->id,
        ]);

        $this->assertCount(1, $transaction->payouts);
        $this->assertEquals($payout->id, $transaction->payouts->first()->id);
    }

    public function test_transaction_payout_model_has_status_helpers(): void
    {
        $pendingPayout = \App\Models\TransactionPayout::factory()->pending()->create([
            'store_id' => $this->store->id,
        ]);

        $successPayout = \App\Models\TransactionPayout::factory()->success()->create([
            'store_id' => $this->store->id,
        ]);

        $failedPayout = \App\Models\TransactionPayout::factory()->failed()->create([
            'store_id' => $this->store->id,
        ]);

        $this->assertTrue($pendingPayout->isPending());
        $this->assertFalse($pendingPayout->isSuccess());

        $this->assertTrue($successPayout->isSuccess());
        $this->assertFalse($successPayout->isPending());

        $this->assertTrue($failedPayout->isFailed());
    }

    public function test_transaction_payout_wallet_helpers(): void
    {
        $paypalPayout = \App\Models\TransactionPayout::factory()->paypal()->create([
            'store_id' => $this->store->id,
        ]);

        $venmoPayout = \App\Models\TransactionPayout::factory()->venmo()->create([
            'store_id' => $this->store->id,
        ]);

        $this->assertTrue($paypalPayout->isPayPal());
        $this->assertFalse($paypalPayout->isVenmo());

        $this->assertTrue($venmoPayout->isVenmo());
        $this->assertFalse($venmoPayout->isPayPal());
    }

    public function test_transaction_payout_has_tracking_url(): void
    {
        $payout = \App\Models\TransactionPayout::factory()->success()->create([
            'store_id' => $this->store->id,
            'payout_batch_id' => 'BATCH123',
            'payout_item_id' => 'ITEM456',
        ]);

        $this->assertNotNull($payout->getTrackingUrl());
        $this->assertStringContainsString('ITEM456', $payout->getTrackingUrl());
    }

    public function test_store_integration_model(): void
    {
        $integration = \App\Models\StoreIntegration::factory()->paypal()->create([
            'store_id' => $this->store->id,
        ]);

        $this->assertEquals(\App\Models\StoreIntegration::PROVIDER_PAYPAL, $integration->provider);
        $this->assertTrue($integration->isActive());
        $this->assertTrue($integration->isSandbox());
        $this->assertFalse($integration->isProduction());
        $this->assertFalse($integration->isTokenExpired());
    }

    public function test_store_has_integrations_relationship(): void
    {
        $integration = \App\Models\StoreIntegration::factory()->paypal()->create([
            'store_id' => $this->store->id,
        ]);

        $this->assertCount(1, $this->store->integrations);
        $this->assertEquals($integration->id, $this->store->integrations->first()->id);
    }

    public function test_store_can_get_paypal_integration(): void
    {
        $integration = \App\Models\StoreIntegration::factory()->paypal()->create([
            'store_id' => $this->store->id,
        ]);

        $paypalIntegration = $this->store->paypalIntegration();

        $this->assertNotNull($paypalIntegration);
        $this->assertEquals($integration->id, $paypalIntegration->id);
    }

    public function test_paypal_payouts_service_is_configured_check(): void
    {
        $service = new \App\Services\Payments\PayPalPayoutsService;
        // Without config, it should not be configured
        $this->assertFalse($service->isConfigured());
    }

    public function test_paypal_payouts_service_for_store(): void
    {
        // Create integration with credentials
        \App\Models\StoreIntegration::factory()->paypal()->create([
            'store_id' => $this->store->id,
            'credentials' => [
                'client_id' => 'test_client_id',
                'client_secret' => 'test_client_secret',
            ],
        ]);

        $service = \App\Services\Payments\PayPalPayoutsService::forStore($this->store);

        $this->assertTrue($service->isConfigured());
        $this->assertNotNull($service->getIntegration());
    }

    public function test_web_send_payout_requires_payment_processed(): void
    {
        // Transaction that hasn't had payment processed yet
        $transaction = Transaction::factory()->offerAccepted()->create([
            'store_id' => $this->store->id,
        ]);

        $response = $this->from("/transactions/{$transaction->id}")
            ->post("/transactions/{$transaction->id}/send-payout", [
                'recipient_value' => 'customer@example.com',
                'amount' => 100.00,
                'wallet' => 'PAYPAL',
            ]);

        $response->assertSessionHas('error', 'Payment must be processed before sending payouts.');
    }

    public function test_web_send_payout_requires_paypal_configured(): void
    {
        $transaction = Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
            'final_offer' => 500.00,
        ]);

        $response = $this->from("/transactions/{$transaction->id}")
            ->post("/transactions/{$transaction->id}/send-payout", [
                'recipient_value' => 'customer@example.com',
                'amount' => 500.00,
                'wallet' => 'PAYPAL',
            ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('PayPal is not configured', session('error'));
    }

    public function test_transaction_payout_mark_as_failed(): void
    {
        $payout = \App\Models\TransactionPayout::factory()->processing()->create([
            'store_id' => $this->store->id,
        ]);

        $payout->markAsFailed('INSUFFICIENT_FUNDS', 'Not enough balance', ['raw' => 'response']);

        $payout->refresh();
        $this->assertEquals(\App\Models\TransactionPayout::STATUS_FAILED, $payout->status);
        $this->assertEquals('INSUFFICIENT_FUNDS', $payout->error_code);
        $this->assertEquals('Not enough balance', $payout->error_message);
        $this->assertNotNull($payout->processed_at);
    }

    public function test_transaction_payout_mark_as_success(): void
    {
        $payout = \App\Models\TransactionPayout::factory()->processing()->create([
            'store_id' => $this->store->id,
        ]);

        $payout->markAsSuccess('TXN123456', ['raw' => 'response']);

        $payout->refresh();
        $this->assertEquals(\App\Models\TransactionPayout::STATUS_SUCCESS, $payout->status);
        $this->assertEquals('TXN123456', $payout->transaction_id_external);
        $this->assertNotNull($payout->processed_at);
    }
}
