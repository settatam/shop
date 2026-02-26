<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreCredit;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Credits\StoreCreditService;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class StoreCreditTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected StoreUser $storeUser;

    protected Customer $customer;

    protected Warehouse $warehouse;

    protected StoreCreditService $storeCreditService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create([
            'user_id' => $this->user->id,
            'step' => 2,
        ]);

        $this->user->update(['current_store_id' => $this->store->id]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        $this->storeUser = StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        $this->customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'store_credit_balance' => 0,
        ]);
        $this->warehouse = Warehouse::factory()->create(['store_id' => $this->store->id]);

        app(StoreContext::class)->setCurrentStore($this->store);
        $this->actingAs($this->user);

        $this->storeCreditService = app(StoreCreditService::class);
    }

    public function test_can_issue_store_credit(): void
    {
        $entry = $this->storeCreditService->issue(
            customer: $this->customer,
            amount: 100.00,
            source: StoreCredit::SOURCE_BUY_TRANSACTION,
            description: 'Test credit issuance',
        );

        $this->assertInstanceOf(StoreCredit::class, $entry);
        $this->assertEquals(StoreCredit::TYPE_CREDIT, $entry->type);
        $this->assertEquals('100.00', $entry->amount);
        $this->assertEquals('100.00', $entry->balance_after);
        $this->assertEquals(StoreCredit::SOURCE_BUY_TRANSACTION, $entry->source);

        $this->customer->refresh();
        $this->assertEquals('100.00', $this->customer->store_credit_balance);
    }

    public function test_can_issue_credit_with_payout_method(): void
    {
        $entry = $this->storeCreditService->issue(
            customer: $this->customer,
            amount: 200.00,
            source: StoreCredit::SOURCE_BUY_TRANSACTION,
            payoutMethod: StoreCredit::PAYOUT_PAYPAL,
        );

        $this->assertEquals(StoreCredit::PAYOUT_PAYPAL, $entry->payout_method);
    }

    public function test_cannot_issue_zero_or_negative_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Credit amount must be greater than zero.');

        $this->storeCreditService->issue(
            customer: $this->customer,
            amount: 0,
            source: StoreCredit::SOURCE_MANUAL,
        );
    }

    public function test_can_issue_multiple_credits_and_balance_accumulates(): void
    {
        $this->storeCreditService->issue(
            customer: $this->customer,
            amount: 100.00,
            source: StoreCredit::SOURCE_BUY_TRANSACTION,
        );

        $entry2 = $this->storeCreditService->issue(
            customer: $this->customer,
            amount: 50.00,
            source: StoreCredit::SOURCE_BUY_TRANSACTION,
        );

        $this->assertEquals('150.00', $entry2->balance_after);

        $this->customer->refresh();
        $this->assertEquals('150.00', $this->customer->store_credit_balance);
    }

    public function test_can_redeem_store_credit(): void
    {
        $this->storeCreditService->issue(
            customer: $this->customer,
            amount: 200.00,
            source: StoreCredit::SOURCE_BUY_TRANSACTION,
        );

        $entry = $this->storeCreditService->redeem(
            customer: $this->customer,
            amount: 75.00,
            source: StoreCredit::SOURCE_ORDER_PAYMENT,
            description: 'Payment for order',
        );

        $this->assertEquals(StoreCredit::TYPE_DEBIT, $entry->type);
        $this->assertEquals('75.00', $entry->amount);
        $this->assertEquals('125.00', $entry->balance_after);

        $this->customer->refresh();
        $this->assertEquals('125.00', $this->customer->store_credit_balance);
    }

    public function test_cannot_redeem_more_than_balance(): void
    {
        $this->storeCreditService->issue(
            customer: $this->customer,
            amount: 50.00,
            source: StoreCredit::SOURCE_BUY_TRANSACTION,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient store credit balance.');

        $this->storeCreditService->redeem(
            customer: $this->customer,
            amount: 100.00,
            source: StoreCredit::SOURCE_ORDER_PAYMENT,
        );
    }

    public function test_cannot_redeem_zero_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Redeem amount must be greater than zero.');

        $this->storeCreditService->redeem(
            customer: $this->customer,
            amount: 0,
            source: StoreCredit::SOURCE_ORDER_PAYMENT,
        );
    }

    public function test_can_cash_out_store_credit(): void
    {
        $this->storeCreditService->issue(
            customer: $this->customer,
            amount: 300.00,
            source: StoreCredit::SOURCE_BUY_TRANSACTION,
        );

        $entry = $this->storeCreditService->cashOut(
            customer: $this->customer,
            amount: 150.00,
            payoutMethod: StoreCredit::PAYOUT_CASH,
        );

        $this->assertEquals(StoreCredit::TYPE_DEBIT, $entry->type);
        $this->assertEquals(StoreCredit::SOURCE_CASH_OUT, $entry->source);
        $this->assertEquals(StoreCredit::PAYOUT_CASH, $entry->payout_method);
        $this->assertEquals('150.00', $entry->amount);
        $this->assertEquals('150.00', $entry->balance_after);

        $this->customer->refresh();
        $this->assertEquals('150.00', $this->customer->store_credit_balance);
    }

    public function test_can_cash_out_full_balance(): void
    {
        $this->storeCreditService->issue(
            customer: $this->customer,
            amount: 250.00,
            source: StoreCredit::SOURCE_BUY_TRANSACTION,
        );

        $entry = $this->storeCreditService->cashOut(
            customer: $this->customer,
            amount: 250.00,
            payoutMethod: StoreCredit::PAYOUT_VENMO,
        );

        $this->assertEquals('0.00', $entry->balance_after);

        $this->customer->refresh();
        $this->assertEquals('0.00', $this->customer->store_credit_balance);
    }

    public function test_cannot_cash_out_more_than_balance(): void
    {
        $this->storeCreditService->issue(
            customer: $this->customer,
            amount: 100.00,
            source: StoreCredit::SOURCE_BUY_TRANSACTION,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient store credit balance.');

        $this->storeCreditService->cashOut(
            customer: $this->customer,
            amount: 200.00,
            payoutMethod: StoreCredit::PAYOUT_CASH,
        );
    }

    public function test_issue_stores_reference_polymorphically(): void
    {
        $transaction = Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
        ]);

        $entry = $this->storeCreditService->issue(
            customer: $this->customer,
            amount: 100.00,
            source: StoreCredit::SOURCE_BUY_TRANSACTION,
            reference: $transaction,
        );

        $this->assertEquals(Transaction::class, $entry->reference_type);
        $this->assertEquals($transaction->id, $entry->reference_id);
        $this->assertInstanceOf(Transaction::class, $entry->reference);
    }

    public function test_ledger_balance_after_tracks_running_total(): void
    {
        $entry1 = $this->storeCreditService->issue($this->customer, 100.00, StoreCredit::SOURCE_BUY_TRANSACTION);
        $this->assertEquals('100.00', $entry1->balance_after);

        $entry2 = $this->storeCreditService->issue($this->customer, 50.00, StoreCredit::SOURCE_BUY_TRANSACTION);
        $this->assertEquals('150.00', $entry2->balance_after);

        $entry3 = $this->storeCreditService->redeem($this->customer, 30.00, StoreCredit::SOURCE_ORDER_PAYMENT);
        $this->assertEquals('120.00', $entry3->balance_after);

        $entry4 = $this->storeCreditService->cashOut($this->customer, 20.00, StoreCredit::PAYOUT_CASH);
        $this->assertEquals('100.00', $entry4->balance_after);

        $this->customer->refresh();
        $this->assertEquals('100.00', $this->customer->store_credit_balance);
    }

    public function test_recalculate_balance_syncs_from_ledger(): void
    {
        $this->storeCreditService->issue($this->customer, 200.00, StoreCredit::SOURCE_BUY_TRANSACTION);
        $this->storeCreditService->redeem($this->customer, 50.00, StoreCredit::SOURCE_ORDER_PAYMENT);

        // Manually mess up the cached balance
        $this->customer->update(['store_credit_balance' => 999.99]);

        $correctedBalance = $this->storeCreditService->recalculateBalance($this->customer);

        $this->assertEquals(150.00, $correctedBalance);

        $this->customer->refresh();
        $this->assertEquals('150.00', $this->customer->store_credit_balance);
    }

    public function test_store_credits_page_loads(): void
    {
        $this->storeCreditService->issue($this->customer, 100.00, StoreCredit::SOURCE_BUY_TRANSACTION);

        $response = $this->get("/customers/{$this->customer->id}/store-credits");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('customers/StoreCredits')
            ->has('customer')
            ->has('credits.data', 1)
            ->has('payoutMethods')
        );
    }

    public function test_store_credits_page_returns_404_for_other_stores_customer(): void
    {
        $otherStore = Store::factory()->create();
        $otherCustomer = Customer::factory()->create(['store_id' => $otherStore->id]);

        $response = $this->get("/customers/{$otherCustomer->id}/store-credits");

        $response->assertStatus(404);
    }

    public function test_cash_out_via_web_endpoint(): void
    {
        $this->storeCreditService->issue($this->customer, 500.00, StoreCredit::SOURCE_BUY_TRANSACTION);

        $response = $this->post("/customers/{$this->customer->id}/store-credits/cash-out", [
            'amount' => 200.00,
            'payout_method' => 'cash',
            'notes' => 'Customer requested cash out',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->customer->refresh();
        $this->assertEquals('300.00', $this->customer->store_credit_balance);

        $this->assertDatabaseHas('store_credits', [
            'customer_id' => $this->customer->id,
            'type' => StoreCredit::TYPE_DEBIT,
            'source' => StoreCredit::SOURCE_CASH_OUT,
            'payout_method' => 'cash',
            'amount' => '200.00',
        ]);
    }

    public function test_cash_out_validates_amount_exceeds_balance(): void
    {
        $this->storeCreditService->issue($this->customer, 100.00, StoreCredit::SOURCE_BUY_TRANSACTION);

        $response = $this->post("/customers/{$this->customer->id}/store-credits/cash-out", [
            'amount' => 500.00,
            'payout_method' => 'cash',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('amount');

        $this->customer->refresh();
        $this->assertEquals('100.00', $this->customer->store_credit_balance);
    }

    public function test_cash_out_validates_required_fields(): void
    {
        $response = $this->post("/customers/{$this->customer->id}/store-credits/cash-out", []);

        $response->assertSessionHasErrors(['amount', 'payout_method']);
    }

    public function test_cash_out_validates_payout_method(): void
    {
        $this->storeCreditService->issue($this->customer, 100.00, StoreCredit::SOURCE_BUY_TRANSACTION);

        $response = $this->post("/customers/{$this->customer->id}/store-credits/cash-out", [
            'amount' => 50.00,
            'payout_method' => 'bitcoin',
        ]);

        $response->assertSessionHasErrors('payout_method');
    }

    public function test_customer_model_has_store_credits_relationship(): void
    {
        $this->storeCreditService->issue($this->customer, 100.00, StoreCredit::SOURCE_BUY_TRANSACTION);
        $this->storeCreditService->issue($this->customer, 50.00, StoreCredit::SOURCE_MANUAL);

        $this->assertCount(2, $this->customer->storeCredits);
    }

    public function test_store_credit_balance_shown_on_customer_page(): void
    {
        $this->storeCreditService->issue($this->customer, 250.00, StoreCredit::SOURCE_BUY_TRANSACTION);

        $response = $this->get("/customers/{$this->customer->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('stats.store_credit_balance', 250)
        );
    }

    public function test_buy_transaction_with_store_credit_issues_credit(): void
    {
        $transactionService = app(\App\Services\Transactions\TransactionService::class);

        $result = $transactionService->createFromWizard([
            'store_user_id' => $this->storeUser->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'items' => [
                [
                    'title' => 'Pokemon Card Collection',
                    'buy_price' => 150.00,
                    'condition' => 'used',
                ],
            ],
            'payments' => [
                [
                    'method' => 'store_credit',
                    'amount' => 150.00,
                    'details' => [
                        'payout_method' => 'paypal',
                    ],
                ],
            ],
        ]);

        $this->customer->refresh();
        $this->assertEquals('150.00', $this->customer->store_credit_balance);

        $this->assertDatabaseHas('store_credits', [
            'customer_id' => $this->customer->id,
            'type' => StoreCredit::TYPE_CREDIT,
            'source' => StoreCredit::SOURCE_BUY_TRANSACTION,
            'amount' => '150.00',
            'payout_method' => 'paypal',
            'reference_type' => Transaction::class,
            'reference_id' => $result['transaction']->id,
        ]);
    }

    public function test_buy_transaction_with_split_payments_including_store_credit(): void
    {
        $transactionService = app(\App\Services\Transactions\TransactionService::class);

        $transactionService->createFromWizard([
            'store_user_id' => $this->storeUser->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'items' => [
                [
                    'title' => 'Trading Cards',
                    'buy_price' => 200.00,
                    'condition' => 'used',
                ],
            ],
            'payments' => [
                [
                    'method' => 'cash',
                    'amount' => 100.00,
                ],
                [
                    'method' => 'store_credit',
                    'amount' => 100.00,
                ],
            ],
        ]);

        $this->customer->refresh();
        $this->assertEquals('100.00', $this->customer->store_credit_balance);

        // Only the store credit portion should create a ledger entry
        $this->assertCount(1, StoreCredit::where('customer_id', $this->customer->id)->get());
    }

    public function test_buy_transaction_without_store_credit_does_not_issue_credit(): void
    {
        $transactionService = app(\App\Services\Transactions\TransactionService::class);

        $transactionService->createFromWizard([
            'store_user_id' => $this->storeUser->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'items' => [
                [
                    'title' => 'Gold Ring',
                    'buy_price' => 500.00,
                    'condition' => 'used',
                ],
            ],
            'payments' => [
                [
                    'method' => 'cash',
                    'amount' => 500.00,
                ],
            ],
        ]);

        $this->customer->refresh();
        $this->assertEquals('0.00', $this->customer->store_credit_balance);
        $this->assertCount(0, StoreCredit::where('customer_id', $this->customer->id)->get());
    }

    public function test_get_balance_returns_cached_balance(): void
    {
        $this->storeCreditService->issue($this->customer, 123.45, StoreCredit::SOURCE_MANUAL);

        $this->customer->refresh();
        $balance = $this->storeCreditService->getBalance($this->customer);

        $this->assertEquals(123.45, $balance);
    }

    public function test_cash_out_with_all_payout_methods(): void
    {
        $methods = [
            StoreCredit::PAYOUT_CASH,
            StoreCredit::PAYOUT_CHECK,
            StoreCredit::PAYOUT_PAYPAL,
            StoreCredit::PAYOUT_VENMO,
            StoreCredit::PAYOUT_ACH,
            StoreCredit::PAYOUT_WIRE_TRANSFER,
        ];

        foreach ($methods as $method) {
            // Issue fresh credit for each test
            $this->storeCreditService->issue($this->customer, 100.00, StoreCredit::SOURCE_MANUAL);

            $entry = $this->storeCreditService->cashOut(
                customer: $this->customer,
                amount: 100.00,
                payoutMethod: $method,
            );

            $this->assertEquals($method, $entry->payout_method);
        }
    }
}
