<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\TransactionPayout;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderPayoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create([
            'user_id' => $this->user->id,
            'step' => 2,
        ]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        $this->user->update(['current_store_id' => $this->store->id]);
        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_order_show_displays_excess_credit_payout_data(): void
    {
        $transaction = Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
        ]);

        $order = Order::factory()->confirmed()->create([
            'store_id' => $this->store->id,
            'trade_in_transaction_id' => $transaction->id,
            'trade_in_credit' => 10000,
        ]);

        $payout = TransactionPayout::factory()->pending()->create([
            'store_id' => $this->store->id,
            'transaction_id' => $transaction->id,
            'amount' => 3300,
            'provider' => 'cash',
        ]);

        $response = $this->actingAs($this->user)->get("/orders/{$order->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('orders/Show')
            ->has('order.excess_credit_payout')
            ->where('order.excess_credit_payout.id', $payout->id)
            ->where('order.excess_credit_payout.amount', '3300.00')
            ->where('order.excess_credit_payout.status', TransactionPayout::STATUS_PENDING)
        );
    }

    public function test_can_issue_customer_payout(): void
    {
        $transaction = Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
        ]);

        $order = Order::factory()->confirmed()->create([
            'store_id' => $this->store->id,
            'trade_in_transaction_id' => $transaction->id,
            'trade_in_credit' => 10000,
        ]);

        $payout = TransactionPayout::factory()->pending()->create([
            'store_id' => $this->store->id,
            'transaction_id' => $transaction->id,
            'amount' => 3300,
            'provider' => 'cash',
        ]);

        $response = $this->actingAs($this->user)->post("/orders/{$order->id}/issue-payout", [
            'payout_method' => 'check',
            'notes' => 'Check #1234',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $payout->refresh();
        $this->assertEquals(TransactionPayout::STATUS_SUCCESS, $payout->status);
        $this->assertEquals('check', $payout->provider);
        $this->assertEquals('Check #1234', $payout->notes);
        $this->assertEquals($this->user->id, $payout->user_id);
        $this->assertNotNull($payout->processed_at);
    }

    public function test_cannot_issue_payout_for_order_without_trade_in(): void
    {
        $order = Order::factory()->confirmed()->create([
            'store_id' => $this->store->id,
        ]);

        $response = $this->actingAs($this->user)->post("/orders/{$order->id}/issue-payout", [
            'payout_method' => 'cash',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'This order does not have a trade-in transaction.');
    }

    public function test_cannot_issue_already_completed_payout(): void
    {
        $transaction = Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
        ]);

        $order = Order::factory()->confirmed()->create([
            'store_id' => $this->store->id,
            'trade_in_transaction_id' => $transaction->id,
        ]);

        TransactionPayout::factory()->success()->create([
            'store_id' => $this->store->id,
            'transaction_id' => $transaction->id,
            'amount' => 3300,
        ]);

        $response = $this->actingAs($this->user)->post("/orders/{$order->id}/issue-payout", [
            'payout_method' => 'cash',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'This payout has already been processed.');
    }

    public function test_issue_payout_validates_method(): void
    {
        $transaction = Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
        ]);

        $order = Order::factory()->confirmed()->create([
            'store_id' => $this->store->id,
            'trade_in_transaction_id' => $transaction->id,
        ]);

        TransactionPayout::factory()->pending()->create([
            'store_id' => $this->store->id,
            'transaction_id' => $transaction->id,
            'amount' => 3300,
        ]);

        $response = $this->actingAs($this->user)->post("/orders/{$order->id}/issue-payout", [
            'payout_method' => 'bitcoin',
        ]);

        $response->assertSessionHasErrors('payout_method');
    }

    public function test_issue_payout_logs_activity(): void
    {
        $transaction = Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
        ]);

        $order = Order::factory()->confirmed()->create([
            'store_id' => $this->store->id,
            'trade_in_transaction_id' => $transaction->id,
            'trade_in_credit' => 10000,
        ]);

        TransactionPayout::factory()->pending()->create([
            'store_id' => $this->store->id,
            'transaction_id' => $transaction->id,
            'amount' => 3300,
            'provider' => 'cash',
        ]);

        $this->actingAs($this->user)->post("/orders/{$order->id}/issue-payout", [
            'payout_method' => 'cash',
            'notes' => 'Paid in cash',
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'activity_slug' => 'orders.payout_issued',
            'subject_type' => Order::class,
            'subject_id' => $order->id,
            'user_id' => $this->user->id,
        ]);
    }

    public function test_print_invoice_includes_customer_payout_data(): void
    {
        $transaction = Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
        ]);

        $order = Order::factory()->confirmed()->create([
            'store_id' => $this->store->id,
            'trade_in_transaction_id' => $transaction->id,
            'trade_in_credit' => 10000,
        ]);

        $invoice = Invoice::factory()->create([
            'store_id' => $this->store->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order->id,
        ]);

        TransactionPayout::factory()->pending()->create([
            'store_id' => $this->store->id,
            'transaction_id' => $transaction->id,
            'amount' => 3300,
            'provider' => 'cash',
        ]);

        $response = $this->actingAs($this->user)->get("/invoices/{$invoice->id}/print");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('invoices/PrintInvoice')
            ->has('invoice.customer_payout')
            ->where('invoice.customer_payout.amount', 3300)
            ->where('invoice.customer_payout.status', TransactionPayout::STATUS_PENDING)
        );
    }
}
