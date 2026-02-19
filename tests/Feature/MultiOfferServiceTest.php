<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\TransactionOffer;
use App\Models\User;
use App\Services\Offers\MultiOfferService;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiOfferServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected Customer $customer;

    protected MultiOfferService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->withOnlineBuysWorkflow()->create([
            'user_id' => $this->user->id,
        ]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        $this->customer = Customer::factory()->create(['store_id' => $this->store->id]);

        app(StoreContext::class)->setCurrentStore($this->store);
        $this->actingAs($this->user);

        $this->service = app(MultiOfferService::class);
    }

    public function test_can_create_multiple_offers_for_online_transaction(): void
    {
        $transaction = Transaction::factory()
            ->itemsReviewed()
            ->create([
                'store_id' => $this->store->id,
                'customer_id' => $this->customer->id,
                'user_id' => $this->user->id,
                'type' => Transaction::TYPE_MAIL_IN,
            ]);

        $offers = [
            ['amount' => 100.00, 'tier' => 'good', 'reasoning' => 'Standard offer'],
            ['amount' => 150.00, 'tier' => 'better', 'reasoning' => 'Better condition'],
            ['amount' => 200.00, 'tier' => 'best', 'reasoning' => 'Excellent condition'],
        ];

        $result = $this->service->createMultipleOffers($transaction, $offers);

        $this->assertTrue($result['success']);
        $this->assertNull($result['error']);
        $this->assertCount(3, $result['offers']);

        $this->assertDatabaseHas('transaction_offers', [
            'transaction_id' => $transaction->id,
            'amount' => 100.00,
            'tier' => 'good',
            'status' => TransactionOffer::STATUS_PENDING,
        ]);

        $this->assertDatabaseHas('transaction_offers', [
            'transaction_id' => $transaction->id,
            'amount' => 200.00,
            'tier' => 'best',
        ]);

        // Transaction should be updated to offer_given
        $transaction->refresh();
        $this->assertEquals(Transaction::STATUS_OFFER_GIVEN, $transaction->status);
        $this->assertNotNull($transaction->offer_given_at);
    }

    public function test_cannot_create_offers_for_store_without_online_buys_workflow(): void
    {
        $store = Store::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $transaction = Transaction::factory()
            ->itemsReviewed()
            ->create([
                'store_id' => $store->id,
                'customer_id' => $this->customer->id,
                'user_id' => $this->user->id,
                'type' => Transaction::TYPE_MAIL_IN,
            ]);

        $offers = [
            ['amount' => 100.00, 'tier' => 'good', 'reasoning' => 'Test'],
        ];

        $result = $this->service->createMultipleOffers($transaction, $offers);

        $this->assertFalse($result['success']);
        $this->assertEquals('Multi-offer feature is only available for online buys workflow.', $result['error']);
    }

    public function test_cannot_create_offers_for_in_store_transaction(): void
    {
        $transaction = Transaction::factory()
            ->pending()
            ->create([
                'store_id' => $this->store->id,
                'customer_id' => $this->customer->id,
                'user_id' => $this->user->id,
                'type' => Transaction::TYPE_IN_STORE,
            ]);

        $offers = [
            ['amount' => 100.00, 'tier' => 'good', 'reasoning' => 'Test'],
        ];

        $result = $this->service->createMultipleOffers($transaction, $offers);

        $this->assertFalse($result['success']);
        $this->assertEquals('Multi-offer feature is only available for online transactions.', $result['error']);
    }

    public function test_cannot_create_offers_for_transaction_with_invalid_status(): void
    {
        $transaction = Transaction::factory()
            ->offerAccepted()
            ->create([
                'store_id' => $this->store->id,
                'customer_id' => $this->customer->id,
                'user_id' => $this->user->id,
                'type' => Transaction::TYPE_MAIL_IN,
            ]);

        $offers = [
            ['amount' => 100.00, 'tier' => 'good', 'reasoning' => 'Test'],
        ];

        $result = $this->service->createMultipleOffers($transaction, $offers);

        $this->assertFalse($result['success']);
        $this->assertEquals('Cannot submit offers for this transaction in its current state.', $result['error']);
    }

    public function test_validation_fails_for_empty_offers(): void
    {
        $transaction = Transaction::factory()
            ->itemsReviewed()
            ->create([
                'store_id' => $this->store->id,
                'customer_id' => $this->customer->id,
                'user_id' => $this->user->id,
                'type' => Transaction::TYPE_MAIL_IN,
            ]);

        $result = $this->service->createMultipleOffers($transaction, []);

        $this->assertFalse($result['success']);
        $this->assertEquals('At least one offer is required.', $result['error']);
    }

    public function test_validation_fails_for_more_than_three_offers(): void
    {
        $transaction = Transaction::factory()
            ->itemsReviewed()
            ->create([
                'store_id' => $this->store->id,
                'customer_id' => $this->customer->id,
                'user_id' => $this->user->id,
                'type' => Transaction::TYPE_MAIL_IN,
            ]);

        $offers = [
            ['amount' => 100.00, 'tier' => 'good'],
            ['amount' => 150.00, 'tier' => 'better'],
            ['amount' => 200.00, 'tier' => 'best'],
            ['amount' => 250.00, 'tier' => 'premium'],
        ];

        $result = $this->service->createMultipleOffers($transaction, $offers);

        $this->assertFalse($result['success']);
        $this->assertEquals('Maximum of 3 offer tiers allowed.', $result['error']);
    }

    public function test_validation_fails_for_invalid_amount(): void
    {
        $transaction = Transaction::factory()
            ->itemsReviewed()
            ->create([
                'store_id' => $this->store->id,
                'customer_id' => $this->customer->id,
                'user_id' => $this->user->id,
                'type' => Transaction::TYPE_MAIL_IN,
            ]);

        $offers = [
            ['amount' => 0, 'tier' => 'good'],
        ];

        $result = $this->service->createMultipleOffers($transaction, $offers);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('valid amount', $result['error']);
    }

    public function test_validation_fails_for_invalid_tier(): void
    {
        $transaction = Transaction::factory()
            ->itemsReviewed()
            ->create([
                'store_id' => $this->store->id,
                'customer_id' => $this->customer->id,
                'user_id' => $this->user->id,
                'type' => Transaction::TYPE_MAIL_IN,
            ]);

        $offers = [
            ['amount' => 100.00, 'tier' => 'invalid_tier'],
        ];

        $result = $this->service->createMultipleOffers($transaction, $offers);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid tier', $result['error']);
    }

    public function test_validation_fails_for_duplicate_tiers(): void
    {
        $transaction = Transaction::factory()
            ->itemsReviewed()
            ->create([
                'store_id' => $this->store->id,
                'customer_id' => $this->customer->id,
                'user_id' => $this->user->id,
                'type' => Transaction::TYPE_MAIL_IN,
            ]);

        $offers = [
            ['amount' => 100.00, 'tier' => 'good'],
            ['amount' => 150.00, 'tier' => 'good'],
        ];

        $result = $this->service->createMultipleOffers($transaction, $offers);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Duplicate tier', $result['error']);
    }

    public function test_supersedes_previous_pending_offers(): void
    {
        $transaction = Transaction::factory()
            ->itemsReviewed()
            ->create([
                'store_id' => $this->store->id,
                'customer_id' => $this->customer->id,
                'user_id' => $this->user->id,
                'type' => Transaction::TYPE_MAIL_IN,
            ]);

        // Create existing pending offers
        $existingOffer1 = TransactionOffer::factory()->pending()->create([
            'transaction_id' => $transaction->id,
            'amount' => 50.00,
            'tier' => 'good',
        ]);
        $existingOffer2 = TransactionOffer::factory()->pending()->create([
            'transaction_id' => $transaction->id,
            'amount' => 75.00,
            'tier' => 'better',
        ]);

        $newOffers = [
            ['amount' => 100.00, 'tier' => 'good', 'reasoning' => 'New offer'],
        ];

        $result = $this->service->createMultipleOffers($transaction, $newOffers);

        $this->assertTrue($result['success']);

        // Previous offers should be superseded
        $existingOffer1->refresh();
        $existingOffer2->refresh();

        $this->assertEquals(TransactionOffer::STATUS_SUPERSEDED, $existingOffer1->status);
        $this->assertEquals(TransactionOffer::STATUS_SUPERSEDED, $existingOffer2->status);
    }

    public function test_can_accept_specific_offer(): void
    {
        $transaction = Transaction::factory()
            ->offerGiven()
            ->create([
                'store_id' => $this->store->id,
                'customer_id' => $this->customer->id,
                'user_id' => $this->user->id,
                'type' => Transaction::TYPE_MAIL_IN,
            ]);

        $goodOffer = TransactionOffer::factory()->pending()->create([
            'transaction_id' => $transaction->id,
            'amount' => 100.00,
            'tier' => 'good',
        ]);
        $betterOffer = TransactionOffer::factory()->pending()->create([
            'transaction_id' => $transaction->id,
            'amount' => 150.00,
            'tier' => 'better',
        ]);
        $bestOffer = TransactionOffer::factory()->pending()->create([
            'transaction_id' => $transaction->id,
            'amount' => 200.00,
            'tier' => 'best',
        ]);

        $result = $this->service->acceptOffer($transaction, $betterOffer, customerId: $this->customer->id);

        $this->assertTrue($result['success']);
        $this->assertNull($result['error']);

        // Accepted offer should be marked as accepted
        $betterOffer->refresh();
        $this->assertEquals(TransactionOffer::STATUS_ACCEPTED, $betterOffer->status);
        $this->assertEquals($this->customer->id, $betterOffer->responded_by_customer_id);
        $this->assertNotNull($betterOffer->responded_at);

        // Other offers should be declined
        $goodOffer->refresh();
        $bestOffer->refresh();
        $this->assertEquals(TransactionOffer::STATUS_DECLINED, $goodOffer->status);
        $this->assertEquals(TransactionOffer::STATUS_DECLINED, $bestOffer->status);

        // Transaction should be updated
        $transaction->refresh();
        $this->assertEquals(Transaction::STATUS_OFFER_ACCEPTED, $transaction->status);
        $this->assertEquals(150.00, $transaction->final_offer);
        $this->assertNotNull($transaction->offer_accepted_at);
    }

    public function test_cannot_accept_offer_from_different_transaction(): void
    {
        $transaction1 = Transaction::factory()
            ->offerGiven()
            ->create([
                'store_id' => $this->store->id,
                'customer_id' => $this->customer->id,
                'user_id' => $this->user->id,
                'type' => Transaction::TYPE_MAIL_IN,
            ]);

        $transaction2 = Transaction::factory()
            ->offerGiven()
            ->create([
                'store_id' => $this->store->id,
                'customer_id' => $this->customer->id,
                'user_id' => $this->user->id,
                'type' => Transaction::TYPE_MAIL_IN,
            ]);

        $offer = TransactionOffer::factory()->pending()->create([
            'transaction_id' => $transaction2->id,
            'amount' => 100.00,
        ]);

        $result = $this->service->acceptOffer($transaction1, $offer, customerId: $this->customer->id);

        $this->assertFalse($result['success']);
        $this->assertEquals('Offer does not belong to this transaction.', $result['error']);
    }

    public function test_cannot_accept_already_responded_offer(): void
    {
        $transaction = Transaction::factory()
            ->offerGiven()
            ->create([
                'store_id' => $this->store->id,
                'customer_id' => $this->customer->id,
                'user_id' => $this->user->id,
                'type' => Transaction::TYPE_MAIL_IN,
            ]);

        $offer = TransactionOffer::factory()->declined()->create([
            'transaction_id' => $transaction->id,
            'amount' => 100.00,
        ]);

        $result = $this->service->acceptOffer($transaction, $offer, customerId: $this->customer->id);

        $this->assertFalse($result['success']);
        $this->assertEquals('This offer has already been responded to.', $result['error']);
    }

    public function test_cannot_accept_expired_offer(): void
    {
        $transaction = Transaction::factory()
            ->offerGiven()
            ->create([
                'store_id' => $this->store->id,
                'customer_id' => $this->customer->id,
                'user_id' => $this->user->id,
                'type' => Transaction::TYPE_MAIL_IN,
            ]);

        $offer = TransactionOffer::factory()->pending()->create([
            'transaction_id' => $transaction->id,
            'amount' => 100.00,
            'expires_at' => now()->subDay(),
        ]);

        $result = $this->service->acceptOffer($transaction, $offer, customerId: $this->customer->id);

        $this->assertFalse($result['success']);
        $this->assertEquals('This offer has expired.', $result['error']);
    }

    public function test_get_pending_offers_returns_sorted_by_tier(): void
    {
        $transaction = Transaction::factory()
            ->offerGiven()
            ->create([
                'store_id' => $this->store->id,
                'customer_id' => $this->customer->id,
                'user_id' => $this->user->id,
                'type' => Transaction::TYPE_MAIL_IN,
            ]);

        // Create offers in random order
        TransactionOffer::factory()->pending()->create([
            'transaction_id' => $transaction->id,
            'amount' => 100.00,
            'tier' => 'good',
        ]);
        TransactionOffer::factory()->pending()->create([
            'transaction_id' => $transaction->id,
            'amount' => 200.00,
            'tier' => 'best',
        ]);
        TransactionOffer::factory()->pending()->create([
            'transaction_id' => $transaction->id,
            'amount' => 150.00,
            'tier' => 'better',
        ]);

        $pendingOffers = $this->service->getPendingOffers($transaction);

        $this->assertCount(3, $pendingOffers);
        $this->assertEquals('best', $pendingOffers[0]->tier);
        $this->assertEquals('better', $pendingOffers[1]->tier);
        $this->assertEquals('good', $pendingOffers[2]->tier);
    }

    public function test_does_not_return_non_pending_offers(): void
    {
        $transaction = Transaction::factory()
            ->offerGiven()
            ->create([
                'store_id' => $this->store->id,
                'customer_id' => $this->customer->id,
                'user_id' => $this->user->id,
                'type' => Transaction::TYPE_MAIL_IN,
            ]);

        TransactionOffer::factory()->pending()->create([
            'transaction_id' => $transaction->id,
            'tier' => 'good',
        ]);
        TransactionOffer::factory()->accepted()->create([
            'transaction_id' => $transaction->id,
            'tier' => 'better',
        ]);
        TransactionOffer::factory()->declined()->create([
            'transaction_id' => $transaction->id,
            'tier' => 'best',
        ]);

        $pendingOffers = $this->service->getPendingOffers($transaction);

        $this->assertCount(1, $pendingOffers);
        $this->assertEquals('good', $pendingOffers[0]->tier);
    }

    public function test_creates_offer_with_expiration_date(): void
    {
        $transaction = Transaction::factory()
            ->itemsReviewed()
            ->create([
                'store_id' => $this->store->id,
                'customer_id' => $this->customer->id,
                'user_id' => $this->user->id,
                'type' => Transaction::TYPE_MAIL_IN,
            ]);

        $expiresAt = now()->addDays(7)->toDateTimeString();

        $offers = [
            ['amount' => 100.00, 'tier' => 'good', 'expires_at' => $expiresAt],
        ];

        $result = $this->service->createMultipleOffers($transaction, $offers);

        $this->assertTrue($result['success']);

        $offer = $result['offers']->first();
        $this->assertNotNull($offer->expires_at);
        $this->assertFalse($offer->isExpired());
    }

    public function test_creates_offer_with_images(): void
    {
        $transaction = Transaction::factory()
            ->itemsReviewed()
            ->create([
                'store_id' => $this->store->id,
                'customer_id' => $this->customer->id,
                'user_id' => $this->user->id,
                'type' => Transaction::TYPE_MAIL_IN,
            ]);

        $images = [
            ['url' => 'https://example.com/image1.jpg', 'thumbnail_url' => 'https://example.com/thumb1.jpg'],
            ['url' => 'https://example.com/image2.jpg', 'thumbnail_url' => 'https://example.com/thumb2.jpg'],
        ];

        $offers = [
            ['amount' => 100.00, 'tier' => 'good', 'images' => $images],
        ];

        $result = $this->service->createMultipleOffers($transaction, $offers);

        $this->assertTrue($result['success']);

        $offer = $result['offers']->first();
        $this->assertIsArray($offer->images);
        $this->assertCount(2, $offer->images);
    }

    public function test_can_create_offers_for_items_received_status(): void
    {
        $transaction = Transaction::factory()
            ->itemsReceived()
            ->create([
                'store_id' => $this->store->id,
                'customer_id' => $this->customer->id,
                'user_id' => $this->user->id,
                'type' => Transaction::TYPE_MAIL_IN,
            ]);

        $offers = [
            ['amount' => 100.00, 'tier' => 'good'],
        ];

        $result = $this->service->createMultipleOffers($transaction, $offers);

        $this->assertTrue($result['success']);
    }

    public function test_can_create_offers_for_offer_declined_status(): void
    {
        $transaction = Transaction::factory()
            ->offerDeclined()
            ->create([
                'store_id' => $this->store->id,
                'customer_id' => $this->customer->id,
                'user_id' => $this->user->id,
                'type' => Transaction::TYPE_MAIL_IN,
            ]);

        $offers = [
            ['amount' => 120.00, 'tier' => 'good', 'reasoning' => 'Revised offer'],
        ];

        $result = $this->service->createMultipleOffers($transaction, $offers);

        $this->assertTrue($result['success']);
    }

    public function test_logs_activity_when_creating_multiple_offers(): void
    {
        $transaction = Transaction::factory()
            ->itemsReviewed()
            ->create([
                'store_id' => $this->store->id,
                'customer_id' => $this->customer->id,
                'user_id' => $this->user->id,
                'type' => Transaction::TYPE_MAIL_IN,
            ]);

        $offers = [
            ['amount' => 100.00, 'tier' => 'good'],
            ['amount' => 150.00, 'tier' => 'better'],
        ];

        $this->service->createMultipleOffers($transaction, $offers);

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => Transaction::class,
            'subject_id' => $transaction->id,
            'activity_slug' => 'transactions.submit_offer',
        ]);
    }

    public function test_logs_activity_when_accepting_offer(): void
    {
        $transaction = Transaction::factory()
            ->offerGiven()
            ->create([
                'store_id' => $this->store->id,
                'customer_id' => $this->customer->id,
                'user_id' => $this->user->id,
                'type' => Transaction::TYPE_MAIL_IN,
            ]);

        $offer = TransactionOffer::factory()->pending()->create([
            'transaction_id' => $transaction->id,
            'amount' => 100.00,
            'tier' => 'good',
        ]);

        $this->service->acceptOffer($transaction, $offer, customerId: $this->customer->id);

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => Transaction::class,
            'subject_id' => $transaction->id,
            'activity_slug' => 'transactions.accept_offer',
        ]);
    }

    public function test_records_status_change_when_accepting_offer(): void
    {
        $transaction = Transaction::factory()
            ->offerGiven()
            ->create([
                'store_id' => $this->store->id,
                'customer_id' => $this->customer->id,
                'user_id' => $this->user->id,
                'type' => Transaction::TYPE_MAIL_IN,
            ]);

        $offer = TransactionOffer::factory()->pending()->create([
            'transaction_id' => $transaction->id,
            'amount' => 100.00,
            'tier' => 'good',
        ]);

        $this->service->acceptOffer($transaction, $offer, customerId: $this->customer->id);

        $this->assertDatabaseHas('status_histories', [
            'trackable_type' => Transaction::class,
            'trackable_id' => $transaction->id,
            'from_status' => Transaction::STATUS_OFFER_GIVEN,
            'to_status' => Transaction::STATUS_OFFER_ACCEPTED,
        ]);
    }

    public function test_offer_stores_admin_notes(): void
    {
        $transaction = Transaction::factory()
            ->itemsReviewed()
            ->create([
                'store_id' => $this->store->id,
                'customer_id' => $this->customer->id,
                'user_id' => $this->user->id,
                'type' => Transaction::TYPE_MAIL_IN,
            ]);

        $offers = [
            [
                'amount' => 100.00,
                'tier' => 'good',
                'reasoning' => 'Public reasoning',
                'admin_notes' => 'Internal admin notes',
            ],
        ];

        $result = $this->service->createMultipleOffers($transaction, $offers);

        $this->assertTrue($result['success']);

        $offer = $result['offers']->first();
        $this->assertEquals('Public reasoning', $offer->reasoning);
        $this->assertEquals('Internal admin notes', $offer->admin_notes);
    }

    public function test_single_offer_works(): void
    {
        $transaction = Transaction::factory()
            ->itemsReviewed()
            ->create([
                'store_id' => $this->store->id,
                'customer_id' => $this->customer->id,
                'user_id' => $this->user->id,
                'type' => Transaction::TYPE_MAIL_IN,
            ]);

        $offers = [
            ['amount' => 100.00, 'tier' => 'best'],
        ];

        $result = $this->service->createMultipleOffers($transaction, $offers);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['offers']);
    }
}
