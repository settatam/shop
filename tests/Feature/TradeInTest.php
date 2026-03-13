<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreCredit;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Orders\OrderCreationService;
use App\Services\StoreContext;
use App\Services\TradeIn\TradeInService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TradeInTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected StoreUser $storeUser;

    protected Customer $customer;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create([
            'user_id' => $this->user->id,
            'step' => 2, // Mark onboarding as complete
        ]);

        // Set user's current store
        $this->user->update(['current_store_id' => $this->store->id]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        $this->storeUser = StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        $this->customer = Customer::factory()->create(['store_id' => $this->store->id]);
        $this->warehouse = Warehouse::factory()->create(['store_id' => $this->store->id]);

        app(StoreContext::class)->setCurrentStore($this->store);
        $this->actingAs($this->user);
    }

    public function test_can_create_trade_in_transaction(): void
    {
        $tradeInService = app(TradeInService::class);

        $items = [
            [
                'title' => '14K Gold Ring',
                'description' => 'Size 7 wedding band',
                'buy_price' => 500.00,
                'precious_metal' => TransactionItem::METAL_GOLD_14K,
                'condition' => TransactionItem::CONDITION_USED,
                'dwt' => 2.5,
            ],
            [
                'title' => 'Silver Necklace',
                'buy_price' => 150.00,
                'precious_metal' => TransactionItem::METAL_SILVER,
                'condition' => TransactionItem::CONDITION_LIKE_NEW,
            ],
        ];

        $transaction = $tradeInService->createTradeIn(
            $items,
            $this->customer->id,
            $this->store,
            $this->warehouse->id
        );

        $this->assertInstanceOf(Transaction::class, $transaction);
        $this->assertEquals(Transaction::SOURCE_TRADE_IN, $transaction->source);
        $this->assertEquals(Transaction::STATUS_PAYMENT_PROCESSED, $transaction->status);
        $this->assertEquals(650.00, $transaction->final_offer);
        $this->assertCount(2, $transaction->items);

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'source' => Transaction::SOURCE_TRADE_IN,
            'customer_id' => $this->customer->id,
        ]);

        $this->assertDatabaseHas('transaction_items', [
            'transaction_id' => $transaction->id,
            'title' => '14K Gold Ring',
            'buy_price' => 500.00,
        ]);
    }

    public function test_calculate_trade_in_credit(): void
    {
        $tradeInService = app(TradeInService::class);

        $items = [
            ['buy_price' => 1000.00],
            ['buy_price' => 500.00],
            ['buy_price' => 250.50],
        ];

        $credit = $tradeInService->calculateTradeInCredit($items);

        $this->assertEquals(1750.50, $credit);
    }

    public function test_can_create_order_with_trade_in(): void
    {
        $orderCreationService = app(OrderCreationService::class);

        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 4000.00,
        ]);
        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 10,
            'reserved_quantity' => 0,
        ]);

        $data = [
            'store_user_id' => $this->storeUser->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'tax_rate' => 0.08,
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'title' => $product->title,
                    'quantity' => 1,
                    'price' => 4000.00,
                ],
            ],
            'trade_in_items' => [
                [
                    'title' => 'Customer Ring',
                    'buy_price' => 3000.00,
                    'precious_metal' => TransactionItem::METAL_GOLD_18K,
                    'condition' => TransactionItem::CONDITION_USED,
                ],
            ],
        ];

        $order = $orderCreationService->createFromWizard($data, $this->store);

        // Order should have trade-in linked
        $this->assertTrue($order->hasTradeIn());
        $this->assertNotNull($order->trade_in_transaction_id);
        $this->assertEquals(3000.00, $order->trade_in_credit);

        // Trade-in transaction should exist and be linked
        $this->assertNotNull($order->tradeInTransaction);
        $this->assertEquals($order->id, $order->tradeInTransaction->order_id);
        $this->assertTrue($order->tradeInTransaction->isTradeIn());

        // Tax should be calculated on full subtotal (4000 * 0.08 = 320)
        $this->assertEquals(320.00, $order->sales_tax);

        // Total should be subtotal + tax = 4000 + 320 = 4320 (trade-in does not reduce total)
        $this->assertEquals(4320.00, $order->total);

        // No auto store credit payment should be created
        $this->assertDatabaseMissing('payments', [
            'order_id' => $order->id,
            'payment_method' => Payment::METHOD_STORE_CREDIT,
        ]);
    }

    public function test_trade_in_exceeds_purchase_total(): void
    {
        $orderCreationService = app(OrderCreationService::class);

        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 2000.00,
        ]);
        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 10,
            'reserved_quantity' => 0,
        ]);

        $data = [
            'store_user_id' => $this->storeUser->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'tax_rate' => 0.08,
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'title' => $product->title,
                    'quantity' => 1,
                    'price' => 2000.00,
                ],
            ],
            'trade_in_items' => [
                [
                    'title' => 'High Value Item',
                    'buy_price' => 5000.00,
                    'precious_metal' => TransactionItem::METAL_GOLD_24K,
                    'condition' => TransactionItem::CONDITION_NEW,
                ],
            ],
        ];

        $order = $orderCreationService->createFromWizard($data, $this->store);

        // Total should be full product price + tax (2000 + 160 = 2160), trade-in does not reduce total
        $this->assertEquals(2160.00, $order->total);

        // Trade-in credit should be the full trade-in amount (informational)
        $this->assertEquals(5000.00, $order->trade_in_credit);

        // Full trade-in amount should be in customer's store credit balance
        $this->customer->refresh();
        $this->assertEquals(5000.00, (float) $this->customer->store_credit_balance);
    }

    public function test_can_cancel_trade_in_with_order(): void
    {
        $tradeInService = app(TradeInService::class);
        $orderCreationService = app(OrderCreationService::class);

        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 1000.00,
        ]);
        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 10,
            'reserved_quantity' => 0,
        ]);

        $data = [
            'store_user_id' => $this->storeUser->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'tax_rate' => 0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'title' => $product->title,
                    'quantity' => 1,
                    'price' => 1000.00,
                ],
            ],
            'trade_in_items' => [
                [
                    'title' => 'Trade Item',
                    'buy_price' => 500.00,
                ],
            ],
        ];

        $order = $orderCreationService->createFromWizard($data, $this->store);
        $transactionId = $order->trade_in_transaction_id;

        // Cancel the trade-in with the order
        $tradeInService->cancelTradeInWithOrder($order, cancelTransaction: true);

        $order->refresh();

        // Order should no longer have trade-in
        $this->assertFalse($order->hasTradeIn());
        $this->assertNull($order->trade_in_transaction_id);
        $this->assertEquals(0, $order->trade_in_credit);

        // Transaction should be cancelled
        $transaction = Transaction::find($transactionId);
        $this->assertEquals(Transaction::STATUS_CANCELLED, $transaction->status);
        $this->assertNull($transaction->order_id);

    }

    public function test_can_unlink_trade_in_without_cancelling(): void
    {
        $tradeInService = app(TradeInService::class);
        $orderCreationService = app(OrderCreationService::class);

        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 1000.00,
        ]);
        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 10,
            'reserved_quantity' => 0,
        ]);

        $data = [
            'store_user_id' => $this->storeUser->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'tax_rate' => 0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'title' => $product->title,
                    'quantity' => 1,
                    'price' => 1000.00,
                ],
            ],
            'trade_in_items' => [
                [
                    'title' => 'Keep Trade Item',
                    'buy_price' => 500.00,
                ],
            ],
        ];

        $order = $orderCreationService->createFromWizard($data, $this->store);
        $transactionId = $order->trade_in_transaction_id;

        // Unlink without cancelling
        $tradeInService->cancelTradeInWithOrder($order, cancelTransaction: false);

        $order->refresh();
        $transaction = Transaction::find($transactionId);

        // Order should no longer have trade-in
        $this->assertFalse($order->hasTradeIn());
        $this->assertNull($order->trade_in_transaction_id);

        // Transaction should still be valid (not cancelled)
        $this->assertNotEquals(Transaction::STATUS_CANCELLED, $transaction->status);
        $this->assertNull($transaction->order_id);
    }

    public function test_wizard_creates_order_with_trade_in_via_web(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 2000.00,
        ]);
        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 10,
            'reserved_quantity' => 0,
        ]);

        $response = $this->post('/orders', [
            'store_user_id' => $this->storeUser->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'tax_rate' => 0.07,
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'title' => $product->title,
                    'quantity' => 1,
                    'price' => 2000.00,
                ],
            ],
            'has_trade_in' => true,
            'trade_in_items' => [
                [
                    'title' => 'Gold Bracelet',
                    'buy_price' => 800.00,
                    'precious_metal' => TransactionItem::METAL_GOLD_14K,
                    'condition' => TransactionItem::CONDITION_USED,
                    'dwt' => 3.5,
                ],
            ],
        ]);

        $response->assertRedirect();

        // Verify order was created with trade-in
        $order = Order::where('store_id', $this->store->id)->latest()->first();

        $this->assertNotNull($order);
        $this->assertTrue($order->hasTradeIn());
        $this->assertEquals(800.00, $order->trade_in_credit);

        // Tax on full subtotal: 2000 * 0.07 = 140
        $this->assertEquals(140.00, $order->sales_tax);

        // Total: 2000 + 140 = 2140 (trade-in does not reduce total)
        $this->assertEquals(2140.00, $order->total);
    }

    public function test_trade_in_transaction_is_trade_in_helper(): void
    {
        $tradeInService = app(TradeInService::class);

        $transaction = $tradeInService->createTradeIn(
            [['title' => 'Test', 'buy_price' => 100]],
            $this->customer->id,
            $this->store
        );

        $this->assertTrue($transaction->isTradeIn());
    }

    public function test_order_has_trade_in_helper(): void
    {
        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'trade_in_transaction_id' => null,
            'trade_in_credit' => 0,
        ]);

        $this->assertFalse($order->hasTradeIn());

        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'source' => Transaction::SOURCE_TRADE_IN,
        ]);

        $order->update(['trade_in_transaction_id' => $transaction->id]);

        $this->assertTrue($order->hasTradeIn());
    }

    public function test_trade_in_issues_store_credit_to_customer(): void
    {
        $tradeInService = app(TradeInService::class);

        $this->assertEquals(0, (float) $this->customer->store_credit_balance);

        $items = [
            ['title' => 'Gold Ring', 'buy_price' => 500.00],
            ['title' => 'Silver Chain', 'buy_price' => 200.00],
        ];

        $transaction = $tradeInService->createTradeIn(
            $items,
            $this->customer->id,
            $this->store,
            $this->warehouse->id
        );

        $this->customer->refresh();

        // Customer balance should increase
        $this->assertEquals(700.00, (float) $this->customer->store_credit_balance);

        // Store credit ledger entry should exist
        $this->assertDatabaseHas('store_credits', [
            'customer_id' => $this->customer->id,
            'type' => StoreCredit::TYPE_CREDIT,
            'amount' => 700.00,
            'source' => StoreCredit::SOURCE_BUY_TRANSACTION,
            'reference_type' => Transaction::class,
            'reference_id' => $transaction->id,
        ]);

        // Transaction payment_method should be store_credit
        $this->assertEquals(Transaction::PAYMENT_STORE_CREDIT, $transaction->payment_method);
    }

    public function test_cancelling_trade_in_reverses_store_credit(): void
    {
        $tradeInService = app(TradeInService::class);
        $orderCreationService = app(OrderCreationService::class);

        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 1000.00,
        ]);
        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 10,
            'reserved_quantity' => 0,
        ]);

        $data = [
            'store_user_id' => $this->storeUser->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'tax_rate' => 0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'title' => $product->title,
                    'quantity' => 1,
                    'price' => 1000.00,
                ],
            ],
            'trade_in_items' => [
                ['title' => 'Gold Ring', 'buy_price' => 300.00],
            ],
        ];

        $order = $orderCreationService->createFromWizard($data, $this->store);

        $this->customer->refresh();
        $balanceAfterTradeIn = (float) $this->customer->store_credit_balance;
        $this->assertEquals(300.00, $balanceAfterTradeIn);

        // Cancel the trade-in
        $tradeInService->cancelTradeInWithOrder($order, cancelTransaction: true);

        $this->customer->refresh();

        // Balance should be reversed back to 0
        $this->assertEquals(0, (float) $this->customer->store_credit_balance);

        // Debit entry should exist
        $this->assertDatabaseHas('store_credits', [
            'customer_id' => $this->customer->id,
            'type' => StoreCredit::TYPE_DEBIT,
            'amount' => 300.00,
            'source' => StoreCredit::SOURCE_REFUND,
        ]);
    }

    public function test_process_payment_saves_check_number(): void
    {
        $tradeInService = app(TradeInService::class);

        $transaction = $tradeInService->createTradeIn(
            [['title' => 'Test Item', 'buy_price' => 100.00]],
            $this->customer->id,
            $this->store,
            $this->warehouse->id
        );

        // Set to a state that allows payment processing
        $transaction->update(['status' => Transaction::STATUS_OFFER_ACCEPTED]);

        $response = $this->post("/transactions/{$transaction->id}/process-payment", [
            'payments' => [
                [
                    'method' => 'check',
                    'amount' => 100.00,
                    'details' => [
                        'check_number' => '1234',
                        'mailing_name' => 'John Doe',
                        'mailing_address' => '123 Main St',
                    ],
                ],
            ],
        ]);

        $transaction->refresh();

        $this->assertEquals('check', $transaction->payment_method);
        $this->assertEquals('1234', $transaction->payment_details['check_number']);
    }

    public function test_trade_in_validation_requires_title_and_price(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 100.00,
        ]);

        $response = $this->from('/orders/create')->post('/orders', [
            'store_user_id' => $this->storeUser->id,
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'title' => $product->title,
                    'quantity' => 1,
                    'price' => 100.00,
                ],
            ],
            'has_trade_in' => true,
            'trade_in_items' => [
                [
                    // Missing title and buy_price
                    'precious_metal' => 'gold_14k',
                ],
            ],
        ]);

        $response->assertSessionHasErrors(['trade_in_items.0.title', 'trade_in_items.0.buy_price']);
    }

    public function test_cancelling_order_cancels_trade_in_transaction(): void
    {
        $orderCreationService = app(OrderCreationService::class);

        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 1000.00,
        ]);
        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 10,
            'reserved_quantity' => 0,
        ]);

        $data = [
            'store_user_id' => $this->storeUser->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'tax_rate' => 0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'title' => $product->title,
                    'quantity' => 1,
                    'price' => 1000.00,
                ],
            ],
            'trade_in_items' => [
                ['title' => 'Gold Ring', 'buy_price' => 500.00],
            ],
        ];

        $order = $orderCreationService->createFromWizard($data, $this->store);
        $transactionId = $order->trade_in_transaction_id;

        $this->customer->refresh();
        $this->assertEquals(500.00, (float) $this->customer->store_credit_balance);

        // Cancel the order
        $orderCreationService->cancelOrder($order);

        $order->refresh();
        $this->customer->refresh();

        // Order should be cancelled
        $this->assertTrue($order->isCancelled());

        // Trade-in transaction should be cancelled
        $transaction = Transaction::find($transactionId);
        $this->assertEquals(Transaction::STATUS_CANCELLED, $transaction->status);
        $this->assertNull($transaction->order_id);

        // Trade-in should be unlinked from order
        $this->assertNull($order->trade_in_transaction_id);
        $this->assertEquals(0, $order->trade_in_credit);

        // Store credit should be reversed
        $this->assertEquals(0, (float) $this->customer->store_credit_balance);
    }
}
