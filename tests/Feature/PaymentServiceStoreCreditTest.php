<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreCredit;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\Credits\StoreCreditService;
use App\Services\PaymentService;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentServiceStoreCreditTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected Customer $customer;

    protected PaymentService $paymentService;

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
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        $this->customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'store_credit_balance' => 0,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
        $this->actingAs($this->user);

        $this->paymentService = app(PaymentService::class);
        $this->storeCreditService = app(StoreCreditService::class);
    }

    public function test_store_credit_payment_deducts_from_customer_balance(): void
    {
        // Give the customer store credit
        $this->storeCreditService->issue(
            customer: $this->customer,
            amount: 200.00,
            source: StoreCredit::SOURCE_BUY_TRANSACTION,
        );

        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'total' => 150.00,
            'sub_total' => 150.00,
        ]);

        $result = $this->paymentService->processPayments($order, [
            [
                'payment_method' => Payment::METHOD_STORE_CREDIT,
                'amount' => 150.00,
            ],
        ], $this->user->id);

        $this->assertCount(1, $result['payments']);
        $this->assertEquals(Payment::METHOD_STORE_CREDIT, $result['payments'][0]->payment_method);

        // Verify balance was deducted
        $this->customer->refresh();
        $this->assertEquals('50.00', $this->customer->store_credit_balance);

        // Verify ledger entry was created
        $this->assertDatabaseHas('store_credits', [
            'customer_id' => $this->customer->id,
            'type' => StoreCredit::TYPE_DEBIT,
            'amount' => '150.00',
            'source' => StoreCredit::SOURCE_ORDER_PAYMENT,
            'reference_type' => Payment::class,
            'reference_id' => $result['payments'][0]->id,
        ]);
    }

    public function test_store_credit_payment_fails_with_insufficient_balance(): void
    {
        // Give the customer only $50 in store credit
        $this->storeCreditService->issue(
            customer: $this->customer,
            amount: 50.00,
            source: StoreCredit::SOURCE_BUY_TRANSACTION,
        );

        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'total' => 100.00,
            'sub_total' => 100.00,
        ]);

        // Try to pay $100 with only $50 balance via the HTTP endpoint
        $response = $this->postJson("/orders/{$order->id}/payment/process", [
            'payment_method' => Payment::METHOD_STORE_CREDIT,
            'amount' => 100.00,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('payment_method');

        // Verify balance was NOT deducted
        $this->customer->refresh();
        $this->assertEquals('50.00', $this->customer->store_credit_balance);
    }

    public function test_store_credit_not_available_when_customer_has_zero_balance(): void
    {
        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'total' => 100.00,
            'sub_total' => 100.00,
        ]);

        // Try to pay with store credit when balance is 0
        $response = $this->postJson("/orders/{$order->id}/payment/process", [
            'payment_method' => Payment::METHOD_STORE_CREDIT,
            'amount' => 50.00,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('payment_method');
    }

    public function test_non_store_credit_payments_unaffected(): void
    {
        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'total' => 100.00,
            'sub_total' => 100.00,
        ]);

        $result = $this->paymentService->processPayments($order, [
            [
                'payment_method' => Payment::METHOD_CASH,
                'amount' => 100.00,
            ],
        ], $this->user->id);

        $this->assertCount(1, $result['payments']);
        $this->assertEquals(Payment::METHOD_CASH, $result['payments'][0]->payment_method);

        // No store credit ledger entries should exist
        $this->assertDatabaseMissing('store_credits', [
            'customer_id' => $this->customer->id,
        ]);
    }

    public function test_split_payment_with_store_credit_and_cash(): void
    {
        // Give the customer $100 store credit
        $this->storeCreditService->issue(
            customer: $this->customer,
            amount: 100.00,
            source: StoreCredit::SOURCE_BUY_TRANSACTION,
        );

        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'total' => 200.00,
            'sub_total' => 200.00,
        ]);

        $result = $this->paymentService->processPayments($order, [
            [
                'payment_method' => Payment::METHOD_STORE_CREDIT,
                'amount' => 100.00,
            ],
            [
                'payment_method' => Payment::METHOD_CASH,
                'amount' => 100.00,
            ],
        ], $this->user->id);

        $this->assertCount(2, $result['payments']);

        // Verify store credit was deducted
        $this->customer->refresh();
        $this->assertEquals('0.00', $this->customer->store_credit_balance);

        // Only one debit ledger entry (for the store credit payment, not the cash)
        $this->assertEquals(1, StoreCredit::where('customer_id', $this->customer->id)
            ->where('type', StoreCredit::TYPE_DEBIT)
            ->count());
    }
}
