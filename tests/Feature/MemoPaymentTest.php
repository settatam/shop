<?php

namespace Tests\Feature;

use App\Models\Memo;
use App\Models\MemoItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Models\Vendor;
use App\Services\MemoPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemoPaymentTest extends TestCase
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
            'step' => 2, // Onboarding complete
        ]);

        StoreUser::factory()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'is_owner' => true,
        ]);

        $this->user->update(['current_store_id' => $this->store->id]);
    }

    public function test_payment_service_calculates_summary_with_no_adjustments(): void
    {
        $memo = $this->createMemoWithItems(total: 500.00, chargeTaxes: false);

        $service = app(MemoPaymentService::class);
        $summary = $service->calculateSummary($memo);

        $this->assertEquals(500.00, $summary['subtotal']);
        $this->assertEquals(0.00, $summary['discount_amount']);
        $this->assertEquals(0.00, $summary['service_fee_amount']);
        $this->assertEquals(0.00, $summary['tax_amount']);
        $this->assertEquals(500.00, $summary['grand_total']);
        $this->assertEquals(500.00, $summary['balance_due']);
    }

    public function test_payment_service_calculates_summary_with_percent_discount(): void
    {
        $memo = $this->createMemoWithItems(total: 500.00, chargeTaxes: false);
        $memo->update([
            'discount_value' => 10,
            'discount_unit' => 'percent',
        ]);

        $service = app(MemoPaymentService::class);
        $summary = $service->calculateSummary($memo);

        $this->assertEquals(500.00, $summary['subtotal']);
        $this->assertEquals(50.00, $summary['discount_amount']); // 10% of 500
        $this->assertEquals(450.00, $summary['grand_total']);
    }

    public function test_payment_service_calculates_summary_with_fixed_discount(): void
    {
        $memo = $this->createMemoWithItems(total: 500.00, chargeTaxes: false);
        $memo->update([
            'discount_value' => 75,
            'discount_unit' => 'fixed',
        ]);

        $service = app(MemoPaymentService::class);
        $summary = $service->calculateSummary($memo);

        $this->assertEquals(75.00, $summary['discount_amount']);
        $this->assertEquals(425.00, $summary['grand_total']);
    }

    public function test_payment_service_calculates_summary_with_percent_service_fee(): void
    {
        $memo = $this->createMemoWithItems(total: 500.00, chargeTaxes: false);
        $memo->update([
            'service_fee_value' => 5,
            'service_fee_unit' => 'percent',
        ]);

        $service = app(MemoPaymentService::class);
        $summary = $service->calculateSummary($memo);

        $this->assertEquals(25.00, $summary['service_fee_amount']); // 5% of 500
        $this->assertEquals(525.00, $summary['grand_total']);
    }

    public function test_payment_service_calculates_summary_with_fixed_service_fee(): void
    {
        $memo = $this->createMemoWithItems(total: 500.00, chargeTaxes: false);
        $memo->update([
            'service_fee_value' => 30,
            'service_fee_unit' => 'fixed',
        ]);

        $service = app(MemoPaymentService::class);
        $summary = $service->calculateSummary($memo);

        $this->assertEquals(30.00, $summary['service_fee_amount']);
        $this->assertEquals(530.00, $summary['grand_total']);
    }

    public function test_payment_service_calculates_summary_with_percent_tax(): void
    {
        $memo = $this->createMemoWithItems(total: 500.00, chargeTaxes: false);
        $memo->update([
            'charge_taxes' => true,
            'tax_rate' => 8,
            'tax_type' => 'percent',
        ]);

        $service = app(MemoPaymentService::class);
        $summary = $service->calculateSummary($memo);

        $this->assertEquals(40.00, $summary['tax_amount']); // 8% of 500
        $this->assertEquals(540.00, $summary['grand_total']);
    }

    public function test_payment_service_calculates_summary_with_fixed_tax(): void
    {
        $memo = $this->createMemoWithItems(total: 500.00, chargeTaxes: false);
        $memo->update([
            'charge_taxes' => true,
            'tax_rate' => 25, // Fixed tax amount
            'tax_type' => 'fixed',
        ]);

        $service = app(MemoPaymentService::class);
        $summary = $service->calculateSummary($memo);

        $this->assertEquals(25.00, $summary['tax_amount']);
        $this->assertEquals(525.00, $summary['grand_total']);
    }

    public function test_payment_service_calculates_summary_with_all_adjustments(): void
    {
        $memo = $this->createMemoWithItems(total: 1000.00, chargeTaxes: false);
        $memo->update([
            'discount_value' => 10,
            'discount_unit' => 'percent',
            'service_fee_value' => 5,
            'service_fee_unit' => 'percent',
            'charge_taxes' => true,
            'tax_rate' => 8,
            'tax_type' => 'percent',
            'shipping_cost' => 15,
        ]);

        $service = app(MemoPaymentService::class);
        $summary = $service->calculateSummary($memo);

        // Subtotal: 1000
        // Discount: 10% of 1000 = 100
        // After discount: 900
        // Service fee: 5% of 900 = 45
        // Taxable: 900 + 45 = 945
        // Tax: 8% of 945 = 75.60
        // Grand total: 945 + 75.60 + 15 = 1035.60
        $this->assertEquals(1000.00, $summary['subtotal']);
        $this->assertEquals(100.00, $summary['discount_amount']);
        $this->assertEquals(45.00, $summary['service_fee_amount']);
        $this->assertEquals(75.60, $summary['tax_amount']);
        $this->assertEquals(15.00, $summary['shipping_cost']);
        $this->assertEquals(1035.60, $summary['grand_total']);
    }

    public function test_payment_service_processes_payment(): void
    {
        $memo = $this->createMemoWithItems(total: 500.00, chargeTaxes: false);
        $memo->update([
            'grand_total' => 500.00,
            'balance_due' => 500.00,
        ]);

        $service = app(MemoPaymentService::class);
        $result = $service->processPayment($memo, [
            'payment_method' => Payment::METHOD_CASH,
            'amount' => 200.00,
        ], $this->user->id);

        $this->assertInstanceOf(Payment::class, $result['payment']);
        $this->assertEquals(200.00, $result['payment']->amount);
        $this->assertEquals(Payment::STATUS_COMPLETED, $result['payment']->status);
        $this->assertEquals(200.00, $result['memo']->total_paid);
        $this->assertEquals(300.00, $result['memo']->balance_due);
        $this->assertFalse($result['is_fully_paid']);
    }

    public function test_payment_service_marks_memo_as_paid_when_fully_paid(): void
    {
        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $memo = Memo::factory()->vendorReceived()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
            'total' => 500.00,
            'grand_total' => 500.00,
            'balance_due' => 500.00,
            'total_paid' => 0,
            'charge_taxes' => false,
            'tax_rate' => 0,
            'shipping_cost' => 0,
        ]);
        MemoItem::factory()->create([
            'memo_id' => $memo->id,
            'price' => 500.00,
            'is_returned' => false,
        ]);

        $service = app(MemoPaymentService::class);
        $result = $service->processPayment($memo, [
            'payment_method' => Payment::METHOD_CARD,
            'amount' => 500.00,
            'gateway' => 'square',
            'gateway_payment_id' => 'sq_payment_123',
        ], $this->user->id);

        $this->assertTrue($result['is_fully_paid']);
        $this->assertEquals(Memo::STATUS_PAYMENT_RECEIVED, $result['memo']->status);

        // Should have created an order
        $this->assertNotNull($result['memo']->order_id);

        // Should have created an invoice
        $this->assertDatabaseHas('invoices', [
            'invoiceable_type' => Memo::class,
            'invoiceable_id' => $memo->id,
        ]);
    }

    public function test_payment_service_creates_multiple_payments(): void
    {
        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $memo = Memo::factory()->vendorReceived()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
            'total' => 500.00,
            'grand_total' => 500.00,
            'balance_due' => 500.00,
            'charge_taxes' => false,
            'shipping_cost' => 0,
        ]);
        MemoItem::factory()->create([
            'memo_id' => $memo->id,
            'price' => 500.00,
            'is_returned' => false,
        ]);

        $service = app(MemoPaymentService::class);

        // First payment
        $service->processPayment($memo, [
            'payment_method' => Payment::METHOD_CASH,
            'amount' => 200.00,
        ], $this->user->id);

        // Second payment
        $result = $service->processPayment($memo->fresh(), [
            'payment_method' => Payment::METHOD_CARD,
            'amount' => 300.00,
        ], $this->user->id);

        $this->assertEquals(500.00, $result['memo']->total_paid);
        $this->assertEquals(0, $result['memo']->balance_due);
        $this->assertTrue($result['is_fully_paid']);

        $this->assertCount(2, $memo->fresh()->payments);
    }

    public function test_payment_service_voids_payment(): void
    {
        $memo = $this->createMemoWithItems(total: 500.00, chargeTaxes: false);
        $memo->update([
            'grand_total' => 500.00,
            'balance_due' => 500.00,
        ]);

        $service = app(MemoPaymentService::class);

        // Process a payment
        $result = $service->processPayment($memo, [
            'payment_method' => Payment::METHOD_CASH,
            'amount' => 200.00,
        ], $this->user->id);

        $payment = $result['payment'];
        $memo = $result['memo'];

        $this->assertEquals(200.00, $memo->total_paid);
        $this->assertEquals(300.00, $memo->balance_due);

        // Void the payment
        $voidedPayment = $service->voidPayment($payment);

        $this->assertEquals(Payment::STATUS_REFUNDED, $voidedPayment->status);
        $this->assertEquals(0, $memo->fresh()->total_paid);
        $this->assertEquals(500.00, $memo->fresh()->balance_due);
    }

    public function test_can_get_payment_summary_via_api(): void
    {
        $memo = $this->createMemoWithItems(total: 500.00, chargeTaxes: false);
        $memo->update([
            'discount_value' => 10,
            'discount_unit' => 'percent',
        ]);

        $response = $this->actingAs($this->user)->getJson("/memos/{$memo->id}/payment/summary");

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEquals(500.00, $data['summary']['subtotal']);
        $this->assertEquals(50.00, $data['summary']['discount_amount']);
        $this->assertEquals(450.00, $data['summary']['grand_total']);
    }

    public function test_can_update_adjustments_via_api(): void
    {
        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $memo = Memo::factory()->vendorReceived()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
            'total' => 500.00,
            'charge_taxes' => false,
            'shipping_cost' => 0,
        ]);
        MemoItem::factory()->create([
            'memo_id' => $memo->id,
            'price' => 500.00,
            'is_returned' => false,
        ]);

        $response = $this->actingAs($this->user)->postJson("/memos/{$memo->id}/payment/adjustments", [
            'discount_value' => 50,
            'discount_unit' => 'fixed',
            'discount_reason' => 'Loyal customer discount',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Payment adjustments updated successfully.');

        $this->assertDatabaseHas('memos', [
            'id' => $memo->id,
            'discount_value' => '50.00',
            'discount_unit' => 'fixed',
            'discount_reason' => 'Loyal customer discount',
        ]);
    }

    public function test_can_process_payment_via_api(): void
    {
        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $memo = Memo::factory()->vendorReceived()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
            'total' => 500.00,
            'grand_total' => 500.00,
            'balance_due' => 500.00,
            'charge_taxes' => false,
            'shipping_cost' => 0,
        ]);
        MemoItem::factory()->create([
            'memo_id' => $memo->id,
            'price' => 500.00,
            'is_returned' => false,
        ]);

        $response = $this->actingAs($this->user)->postJson("/memos/{$memo->id}/payment/process", [
            'payment_method' => 'cash',
            'amount' => 250.00,
            'notes' => 'Partial payment',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Payment recorded successfully.')
            ->assertJsonPath('is_fully_paid', false);

        $this->assertDatabaseHas('payments', [
            'payable_type' => Memo::class,
            'payable_id' => $memo->id,
            'payment_method' => 'cash',
            'amount' => '250.00',
        ]);
    }

    public function test_can_get_payment_history_via_api(): void
    {
        $memo = $this->createMemoWithItems(total: 500.00, chargeTaxes: false);

        // Create some payments using the new polymorphic relationship
        Payment::factory()->forMemo($memo)->create([
            'user_id' => $this->user->id,
            'amount' => 200.00,
            'payment_method' => Payment::METHOD_CASH,
            'status' => Payment::STATUS_COMPLETED,
        ]);
        Payment::factory()->forMemo($memo)->create([
            'user_id' => $this->user->id,
            'amount' => 150.00,
            'payment_method' => Payment::METHOD_CARD,
            'status' => Payment::STATUS_COMPLETED,
        ]);

        $response = $this->actingAs($this->user)->getJson("/memos/{$memo->id}/payment/history");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'payments');
    }

    public function test_can_void_payment_via_api(): void
    {
        $memo = $this->createMemoWithItems(total: 500.00, chargeTaxes: false);
        $memo->update([
            'grand_total' => 500.00,
            'balance_due' => 300.00,
            'total_paid' => 200.00,
        ]);

        $payment = Payment::factory()->forMemo($memo)->create([
            'user_id' => $this->user->id,
            'amount' => 200.00,
            'payment_method' => Payment::METHOD_CASH,
            'status' => Payment::STATUS_COMPLETED,
        ]);

        $response = $this->actingAs($this->user)->postJson("/memos/{$memo->id}/payment/{$payment->id}/void");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Payment voided successfully.');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => Payment::STATUS_REFUNDED,
        ]);
    }

    public function test_cannot_access_other_store_memo_payment(): void
    {
        $otherStore = Store::factory()->create(['step' => 2]);
        $otherVendor = Vendor::factory()->create(['store_id' => $otherStore->id]);
        $otherMemo = Memo::factory()->create([
            'store_id' => $otherStore->id,
            'vendor_id' => $otherVendor->id,
        ]);

        $response = $this->actingAs($this->user)->getJson("/memos/{$otherMemo->id}/payment/summary");

        // Returns 404 because the StoreScope global scope filters out memos from other stores
        // This is the correct multi-tenant behavior - the memo doesn't exist from this user's perspective
        $response->assertStatus(404);
    }

    public function test_validation_errors_for_invalid_payment_method(): void
    {
        $memo = $this->createMemoWithItems(total: 500.00, chargeTaxes: false);

        $response = $this->actingAs($this->user)->postJson("/memos/{$memo->id}/payment/process", [
            'payment_method' => 'invalid_method',
            'amount' => 100.00,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payment_method']);
    }

    public function test_validation_errors_for_negative_amount(): void
    {
        $memo = $this->createMemoWithItems(total: 500.00, chargeTaxes: false);

        $response = $this->actingAs($this->user)->postJson("/memos/{$memo->id}/payment/process", [
            'payment_method' => 'cash',
            'amount' => -50.00,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_can_process_split_payments_via_api(): void
    {
        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $memo = Memo::factory()->vendorReceived()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
            'total' => 500.00,
            'grand_total' => 500.00,
            'balance_due' => 500.00,
            'charge_taxes' => false,
            'shipping_cost' => 0,
        ]);
        MemoItem::factory()->create([
            'memo_id' => $memo->id,
            'price' => 500.00,
            'is_returned' => false,
        ]);

        $response = $this->actingAs($this->user)->postJson("/memos/{$memo->id}/payment/process", [
            'payments' => [
                [
                    'payment_method' => 'cash',
                    'amount' => 200.00,
                ],
                [
                    'payment_method' => 'card',
                    'amount' => 300.00,
                    'reference' => 'TXN123456',
                ],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('is_fully_paid', true);

        $this->assertCount(2, $memo->fresh()->payments);
        $this->assertEquals(500.00, $memo->fresh()->total_paid);
    }

    public function test_split_payments_with_per_payment_service_fees(): void
    {
        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $memo = Memo::factory()->vendorReceived()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
            'total' => 500.00,
            'grand_total' => 500.00,
            'balance_due' => 500.00,
            'charge_taxes' => false,
            'shipping_cost' => 0,
        ]);
        MemoItem::factory()->create([
            'memo_id' => $memo->id,
            'price' => 500.00,
            'is_returned' => false,
        ]);

        $response = $this->actingAs($this->user)->postJson("/memos/{$memo->id}/payment/process", [
            'payments' => [
                [
                    'payment_method' => 'cash',
                    'amount' => 200.00,
                    // No service fee for cash
                ],
                [
                    'payment_method' => 'card',
                    'amount' => 300.00,
                    'service_fee_value' => 3,
                    'service_fee_unit' => 'percent',
                    'reference' => 'TXN123456',
                ],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('is_fully_paid', true)
            ->assertJsonCount(2, 'payments');

        $payments = $memo->fresh()->payments;
        $this->assertCount(2, $payments);

        // Check the card payment has service fee
        $cardPayment = $payments->firstWhere('payment_method', 'card');
        $this->assertEquals(3, $cardPayment->service_fee_value);
        $this->assertEquals('percent', $cardPayment->service_fee_unit);
        $this->assertEquals(9.00, $cardPayment->service_fee_amount); // 3% of 300

        // Check the cash payment has no service fee
        $cashPayment = $payments->firstWhere('payment_method', 'cash');
        $this->assertNull($cashPayment->service_fee_value);
        $this->assertNull($cashPayment->service_fee_amount);
    }

    public function test_single_payment_with_service_fee(): void
    {
        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $memo = Memo::factory()->vendorReceived()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
            'total' => 500.00,
            'grand_total' => 500.00,
            'balance_due' => 500.00,
            'charge_taxes' => false,
            'shipping_cost' => 0,
        ]);
        MemoItem::factory()->create([
            'memo_id' => $memo->id,
            'price' => 500.00,
            'is_returned' => false,
        ]);

        $response = $this->actingAs($this->user)->postJson("/memos/{$memo->id}/payment/process", [
            'payment_method' => 'card',
            'amount' => 500.00,
            'service_fee_value' => 15,
            'service_fee_unit' => 'fixed',
            'reference' => 'TXN789',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('is_fully_paid', true);

        $payment = $memo->fresh()->payments->first();
        $this->assertEquals(15, $payment->service_fee_value);
        $this->assertEquals('fixed', $payment->service_fee_unit);
        $this->assertEquals(15.00, $payment->service_fee_amount);
    }

    public function test_completed_payment_creates_adhoc_product_for_items_without_product(): void
    {
        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $memo = Memo::factory()->vendorReceived()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
            'total' => 250.00,
            'grand_total' => 250.00,
            'balance_due' => 250.00,
            'total_paid' => 0,
            'charge_taxes' => false,
            'tax_rate' => 0,
            'shipping_cost' => 0,
        ]);

        // Create a custom memo item with no product_id
        MemoItem::factory()->create([
            'memo_id' => $memo->id,
            'product_id' => null,
            'title' => 'Custom Gold Ring',
            'sku' => null,
            'price' => 250.00,
            'cost' => 120.00,
            'is_returned' => false,
        ]);

        $service = app(MemoPaymentService::class);
        $service->processPayment($memo, [
            'payment_method' => Payment::METHOD_CASH,
            'amount' => 250.00,
        ], $this->user->id);

        $memo->refresh();

        // An ad-hoc product should have been created
        $memoItem = $memo->items->first();
        $this->assertNotNull($memoItem->product_id);

        $product = Product::find($memoItem->product_id);
        $this->assertNotNull($product);
        $this->assertEquals('Custom Gold Ring', $product->title);
        $this->assertEquals(Product::STATUS_SOLD, $product->status);
        $this->assertEquals(0, $product->quantity);
        $this->assertEquals($this->store->id, $product->store_id);

        // Variant should have been created with correct pricing
        $variant = $product->variants->first();
        $this->assertNotNull($variant);
        $this->assertEquals(250.00, (float) $variant->price);
        $this->assertEquals(120.00, (float) $variant->cost);

        // Order item should reference the new product
        $order = Order::find($memo->order_id);
        $orderItem = $order->items->first();
        $this->assertEquals($product->id, $orderItem->product_id);
    }

    public function test_completed_payment_creates_order_with_memo_number_as_invoice_number(): void
    {
        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $memo = Memo::factory()->vendorReceived()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
            'memo_number' => 'M-2024-0042',
            'total' => 300.00,
            'grand_total' => 300.00,
            'balance_due' => 300.00,
            'total_paid' => 0,
            'charge_taxes' => false,
            'tax_rate' => 0,
            'shipping_cost' => 0,
        ]);
        MemoItem::factory()->create([
            'memo_id' => $memo->id,
            'price' => 300.00,
            'cost' => 150.00,
            'is_returned' => false,
        ]);

        $service = app(MemoPaymentService::class);
        $service->processPayment($memo, [
            'payment_method' => Payment::METHOD_CASH,
            'amount' => 300.00,
        ], $this->user->id);

        $memo->refresh();

        $this->assertNotNull($memo->order_id);

        $order = Order::find($memo->order_id);
        $this->assertEquals('M-2024-0042', $order->invoice_number);
        $this->assertEquals('memo', $order->source_platform);
        $this->assertEquals(Order::STATUS_COMPLETED, $order->status);
    }

    public function test_completed_payment_marks_products_as_sold(): void
    {
        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_IN_MEMO,
            'quantity' => 0,
        ]);

        $memo = Memo::factory()->vendorReceived()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
            'total' => 200.00,
            'grand_total' => 200.00,
            'balance_due' => 200.00,
            'total_paid' => 0,
            'charge_taxes' => false,
            'tax_rate' => 0,
            'shipping_cost' => 0,
        ]);

        MemoItem::factory()->create([
            'memo_id' => $memo->id,
            'product_id' => $product->id,
            'price' => 200.00,
            'cost' => 100.00,
            'is_returned' => false,
        ]);

        $service = app(MemoPaymentService::class);
        $service->processPayment($memo, [
            'payment_method' => Payment::METHOD_CASH,
            'amount' => 200.00,
        ], $this->user->id);

        $product->refresh();
        $this->assertEquals(Product::STATUS_SOLD, $product->status);
    }

    public function test_completed_payment_does_not_mark_returned_items_as_sold(): void
    {
        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);

        $activeProduct = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_IN_MEMO,
            'quantity' => 0,
        ]);

        $returnedProduct = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
            'quantity' => 1,
        ]);

        $memo = Memo::factory()->vendorReceived()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
            'total' => 200.00,
            'grand_total' => 200.00,
            'balance_due' => 200.00,
            'total_paid' => 0,
            'charge_taxes' => false,
            'tax_rate' => 0,
            'shipping_cost' => 0,
        ]);

        MemoItem::factory()->create([
            'memo_id' => $memo->id,
            'product_id' => $activeProduct->id,
            'price' => 200.00,
            'cost' => 100.00,
            'is_returned' => false,
        ]);

        MemoItem::factory()->create([
            'memo_id' => $memo->id,
            'product_id' => $returnedProduct->id,
            'price' => 150.00,
            'cost' => 75.00,
            'is_returned' => true,
        ]);

        $service = app(MemoPaymentService::class);
        $service->processPayment($memo, [
            'payment_method' => Payment::METHOD_CASH,
            'amount' => 200.00,
        ], $this->user->id);

        $activeProduct->refresh();
        $returnedProduct->refresh();

        $this->assertEquals(Product::STATUS_SOLD, $activeProduct->status);
        $this->assertEquals(Product::STATUS_ACTIVE, $returnedProduct->status);
    }

    public function test_completed_payment_creates_customer_from_vendor(): void
    {
        $vendor = Vendor::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'John Smith',
            'company_name' => 'Smith Jewelers',
            'email' => 'john@smithjewelers.com',
            'phone' => '(555) 123-4567',
        ]);

        $memo = Memo::factory()->vendorReceived()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
            'total' => 300.00,
            'grand_total' => 300.00,
            'balance_due' => 300.00,
            'total_paid' => 0,
            'charge_taxes' => false,
            'tax_rate' => 0,
            'shipping_cost' => 0,
        ]);

        MemoItem::factory()->create([
            'memo_id' => $memo->id,
            'price' => 300.00,
            'cost' => 150.00,
            'is_returned' => false,
        ]);

        $service = app(MemoPaymentService::class);
        $result = $service->processPayment($memo, [
            'payment_method' => Payment::METHOD_CASH,
            'amount' => 300.00,
        ], $this->user->id);

        // Verify customer was created from vendor
        $this->assertDatabaseHas('customers', [
            'store_id' => $this->store->id,
            'first_name' => 'John',
            'last_name' => 'Smith',
            'company_name' => 'Smith Jewelers',
            'email' => 'john@smithjewelers.com',
        ]);

        // Verify invoice has customer_id
        $invoice = $memo->fresh()->invoice;
        $this->assertNotNull($invoice);
        $this->assertNotNull($invoice->customer_id);

        // Verify order has customer_id
        $order = $memo->fresh()->order;
        $this->assertNotNull($order);
        $this->assertNotNull($order->customer_id);
        $this->assertEquals($invoice->customer_id, $order->customer_id);
    }

    public function test_completed_payment_reuses_existing_customer_for_vendor(): void
    {
        $vendor = Vendor::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ]);

        // Pre-create a customer with the same email
        $existingCustomer = \App\Models\Customer::factory()->create([
            'store_id' => $this->store->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
        ]);

        $memo = Memo::factory()->vendorReceived()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
            'total' => 200.00,
            'grand_total' => 200.00,
            'balance_due' => 200.00,
            'total_paid' => 0,
            'charge_taxes' => false,
            'tax_rate' => 0,
            'shipping_cost' => 0,
        ]);

        MemoItem::factory()->create([
            'memo_id' => $memo->id,
            'price' => 200.00,
            'is_returned' => false,
        ]);

        $service = app(MemoPaymentService::class);
        $service->processPayment($memo, [
            'payment_method' => Payment::METHOD_CASH,
            'amount' => 200.00,
        ], $this->user->id);

        // Should reuse existing customer, not create a new one
        $this->assertEquals(1, \App\Models\Customer::where('email', 'jane@example.com')->count());

        $invoice = $memo->fresh()->invoice;
        $this->assertEquals($existingCustomer->id, $invoice->customer_id);
    }

    protected function createMemoWithItems(float $total = 500.00, bool $chargeTaxes = true): Memo
    {
        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $memo = Memo::factory()->vendorReceived()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
            'total' => $total,
            'charge_taxes' => $chargeTaxes,
            'tax_rate' => $chargeTaxes ? 0.08 : 0,
            'shipping_cost' => 0,
        ]);

        MemoItem::factory()->create([
            'memo_id' => $memo->id,
            'price' => $total,
            'is_returned' => false,
        ]);

        return $memo;
    }
}
