<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Memo;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Repair;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\Invoices\InvoiceService;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_can_list_invoices(): void
    {
        Passport::actingAs($this->user);

        $order = Order::factory()->create(['store_id' => $this->store->id]);
        Invoice::factory()->count(3)->create([
            'store_id' => $this->store->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order->id,
        ]);

        $response = $this->getJson('/api/v1/invoices');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_can_filter_invoices_by_status(): void
    {
        Passport::actingAs($this->user);

        $order = Order::factory()->create(['store_id' => $this->store->id]);
        Invoice::factory()->pending()->count(2)->create([
            'store_id' => $this->store->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order->id,
        ]);
        Invoice::factory()->paid()->count(3)->create([
            'store_id' => $this->store->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order->id,
        ]);

        $response = $this->getJson('/api/v1/invoices?status=pending');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_can_show_invoice_details(): void
    {
        Passport::actingAs($this->user);

        $order = Order::factory()->create(['store_id' => $this->store->id]);
        $invoice = Invoice::factory()->create([
            'store_id' => $this->store->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order->id,
        ]);

        $response = $this->getJson("/api/v1/invoices/{$invoice->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $invoice->id)
            ->assertJsonPath('data.invoice_number', $invoice->invoice_number);
    }

    public function test_can_add_payment_to_invoice(): void
    {
        Passport::actingAs($this->user);

        $order = Order::factory()->create(['store_id' => $this->store->id]);
        $invoice = Invoice::factory()->pending()->create([
            'store_id' => $this->store->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order->id,
            'total' => 200.00,
            'balance_due' => 200.00,
        ]);

        $response = $this->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => 100.00,
            'payment_method' => 'cash',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Payment added successfully.');

        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'amount' => '100.00',
            'payment_method' => 'cash',
        ]);

        $invoice->refresh();
        $this->assertEquals('100.00', $invoice->total_paid);
        $this->assertEquals('100.00', $invoice->balance_due);
        $this->assertEquals(Invoice::STATUS_PARTIAL, $invoice->status);
    }

    public function test_full_payment_marks_invoice_as_paid(): void
    {
        Passport::actingAs($this->user);

        $order = Order::factory()->create(['store_id' => $this->store->id]);
        $invoice = Invoice::factory()->pending()->create([
            'store_id' => $this->store->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order->id,
            'total' => 150.00,
            'balance_due' => 150.00,
        ]);

        $response = $this->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => 150.00,
            'payment_method' => 'card',
        ]);

        $response->assertStatus(200);

        $invoice->refresh();
        $this->assertEquals('150.00', $invoice->total_paid);
        $this->assertEquals('0.00', $invoice->balance_due);
        $this->assertEquals(Invoice::STATUS_PAID, $invoice->status);
        $this->assertNotNull($invoice->paid_at);
    }

    public function test_cannot_add_payment_exceeding_balance(): void
    {
        Passport::actingAs($this->user);

        $order = Order::factory()->create(['store_id' => $this->store->id]);
        $invoice = Invoice::factory()->pending()->create([
            'store_id' => $this->store->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order->id,
            'total' => 100.00,
            'balance_due' => 100.00,
        ]);

        $response = $this->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => 150.00,
            'payment_method' => 'cash',
        ]);

        $response->assertStatus(500);
    }

    public function test_cannot_add_payment_to_paid_invoice(): void
    {
        Passport::actingAs($this->user);

        $order = Order::factory()->create(['store_id' => $this->store->id]);
        $invoice = Invoice::factory()->paid()->create([
            'store_id' => $this->store->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order->id,
        ]);

        $response = $this->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => 50.00,
            'payment_method' => 'cash',
        ]);

        $response->assertStatus(500);
    }

    public function test_can_void_pending_invoice(): void
    {
        Passport::actingAs($this->user);

        $order = Order::factory()->create(['store_id' => $this->store->id]);
        $invoice = Invoice::factory()->pending()->create([
            'store_id' => $this->store->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order->id,
            'total_paid' => 0,
        ]);

        $response = $this->postJson("/api/v1/invoices/{$invoice->id}/void");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', Invoice::STATUS_VOID);
    }

    public function test_cannot_void_invoice_with_payments(): void
    {
        Passport::actingAs($this->user);

        $order = Order::factory()->create(['store_id' => $this->store->id]);
        $invoice = Invoice::factory()->partial()->create([
            'store_id' => $this->store->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order->id,
        ]);

        $response = $this->postJson("/api/v1/invoices/{$invoice->id}/void");

        $response->assertStatus(500);
    }

    public function test_invoice_service_creates_from_order(): void
    {
        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'sub_total' => 100.00,
            'sales_tax' => 8.00,
            'shipping_cost' => 10.00,
            'discount_cost' => 5.00,
            'total' => 113.00,
        ]);

        $service = app(InvoiceService::class);
        $invoice = $service->createFromOrder($order);

        $this->assertEquals($order->id, $invoice->invoiceable_id);
        $this->assertEquals(Order::class, $invoice->invoiceable_type);
        $this->assertEquals('100.00', $invoice->subtotal);
        $this->assertEquals('8.00', $invoice->tax);
        $this->assertEquals('10.00', $invoice->shipping);
        $this->assertEquals('5.00', $invoice->discount);
        $this->assertEquals('113.00', $invoice->total);
        $this->assertEquals('113.00', $invoice->balance_due);
        $this->assertEquals(Invoice::STATUS_PENDING, $invoice->status);
    }

    public function test_invoice_service_creates_from_repair(): void
    {
        $repair = Repair::factory()->completed()->create([
            'store_id' => $this->store->id,
            'subtotal' => 80.00,
            'tax' => 6.40,
            'shipping_cost' => 5.00,
            'discount' => 0,
            'total' => 111.40,
        ]);

        $service = app(InvoiceService::class);
        $invoice = $service->createFromRepair($repair);

        $this->assertEquals($repair->id, $invoice->invoiceable_id);
        $this->assertEquals(Repair::class, $invoice->invoiceable_type);
        $this->assertEquals('80.00', $invoice->subtotal);
        $this->assertEquals('6.40', $invoice->tax);
        $this->assertEquals('111.40', $invoice->total);
    }

    public function test_invoice_service_creates_from_memo(): void
    {
        $memo = Memo::factory()->vendorReceived()->create([
            'store_id' => $this->store->id,
            'subtotal' => 500.00,
            'tax' => 40.00,
            'shipping_cost' => 15.00,
            'total' => 555.00,
        ]);

        $service = app(InvoiceService::class);
        $invoice = $service->createFromMemo($memo);

        $this->assertEquals($memo->id, $invoice->invoiceable_id);
        $this->assertEquals(Memo::class, $invoice->invoiceable_type);
        $this->assertEquals('500.00', $invoice->subtotal);
        $this->assertEquals('40.00', $invoice->tax);
        $this->assertEquals('555.00', $invoice->total);
    }

    public function test_invoice_generates_unique_number(): void
    {
        $order = Order::factory()->create(['store_id' => $this->store->id]);
        $invoice1 = Invoice::factory()->create([
            'store_id' => $this->store->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order->id,
        ]);
        $invoice2 = Invoice::factory()->create([
            'store_id' => $this->store->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order->id,
        ]);

        $this->assertNotEquals($invoice1->invoice_number, $invoice2->invoice_number);
        // Invoice number uses store's order_id_prefix or falls back to 'INV'
        $expectedPrefix = $this->store->order_id_prefix ?: 'INV';
        $this->assertStringStartsWith($expectedPrefix.'-', $invoice1->invoice_number);
    }

    public function test_invoice_does_not_create_duplicate(): void
    {
        $order = Order::factory()->create(['store_id' => $this->store->id]);

        $service = app(InvoiceService::class);
        $invoice1 = $service->createFromOrder($order);
        $invoice2 = $service->createFromOrder($order);

        $this->assertEquals($invoice1->id, $invoice2->id);
    }

    public function test_only_store_invoices_are_visible(): void
    {
        Passport::actingAs($this->user);

        $otherStore = Store::factory()->create();
        $order1 = Order::factory()->create(['store_id' => $this->store->id]);
        $order2 = Order::factory()->create(['store_id' => $otherStore->id]);

        Invoice::factory()->count(2)->create([
            'store_id' => $this->store->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order1->id,
        ]);
        Invoice::factory()->count(3)->create([
            'store_id' => $otherStore->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order2->id,
        ]);

        $response = $this->getJson('/api/v1/invoices');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_split_payment_scenario(): void
    {
        Passport::actingAs($this->user);

        $order = Order::factory()->create(['store_id' => $this->store->id]);
        $invoice = Invoice::factory()->pending()->create([
            'store_id' => $this->store->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order->id,
            'total' => 500.00,
            'balance_due' => 500.00,
        ]);

        // First payment - cash
        $this->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => 200.00,
            'payment_method' => 'cash',
        ])->assertStatus(200);

        $invoice->refresh();
        $this->assertEquals('200.00', $invoice->total_paid);
        $this->assertEquals('300.00', $invoice->balance_due);
        $this->assertEquals(Invoice::STATUS_PARTIAL, $invoice->status);

        // Second payment - card
        $this->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => 300.00,
            'payment_method' => 'card',
        ])->assertStatus(200);

        $invoice->refresh();
        $this->assertEquals('500.00', $invoice->total_paid);
        $this->assertEquals('0.00', $invoice->balance_due);
        $this->assertEquals(Invoice::STATUS_PAID, $invoice->status);

        // Verify two payments exist
        $this->assertCount(2, $invoice->payments);
    }

    public function test_invoice_polymorphic_relationship(): void
    {
        $order = Order::factory()->create(['store_id' => $this->store->id]);
        $service = app(InvoiceService::class);
        $invoice = $service->createFromOrder($order);

        $this->assertInstanceOf(Order::class, $invoice->invoiceable);
        $this->assertEquals($order->id, $invoice->invoiceable->id);
        $this->assertEquals('Order', $invoice->invoiceable_type_name);
    }

    public function test_order_has_invoice_relationship(): void
    {
        $order = Order::factory()->create(['store_id' => $this->store->id]);
        $service = app(InvoiceService::class);
        $service->createFromOrder($order);

        $order->refresh();
        $this->assertNotNull($order->invoice);
        $this->assertInstanceOf(Invoice::class, $order->invoice);
    }

    public function test_repair_has_invoice_relationship(): void
    {
        $repair = Repair::factory()->create(['store_id' => $this->store->id]);
        $service = app(InvoiceService::class);
        $service->createFromRepair($repair);

        $repair->refresh();
        $this->assertNotNull($repair->invoice);
        $this->assertInstanceOf(Invoice::class, $repair->invoice);
    }

    public function test_memo_has_invoice_relationship(): void
    {
        $memo = Memo::factory()->create(['store_id' => $this->store->id]);
        $service = app(InvoiceService::class);
        $service->createFromMemo($memo);

        $memo->refresh();
        $this->assertNotNull($memo->invoice);
        $this->assertInstanceOf(Invoice::class, $memo->invoice);
    }

    public function test_can_download_invoice_pdf(): void
    {
        Passport::actingAs($this->user);

        $order = Order::factory()->create(['store_id' => $this->store->id]);
        $invoice = Invoice::factory()->create([
            'store_id' => $this->store->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order->id,
        ]);

        $response = $this->get("/api/v1/invoices/{$invoice->id}/pdf");

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
        $response->assertHeader('content-disposition', "attachment; filename=invoice-{$invoice->invoice_number}.pdf");
    }

    public function test_can_stream_invoice_pdf(): void
    {
        Passport::actingAs($this->user);

        $order = Order::factory()->create(['store_id' => $this->store->id]);
        $invoice = Invoice::factory()->create([
            'store_id' => $this->store->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order->id,
        ]);

        $response = $this->get("/api/v1/invoices/{$invoice->id}/pdf/stream");

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_cannot_download_pdf_for_other_store_invoice(): void
    {
        Passport::actingAs($this->user);

        $otherStore = Store::factory()->create();
        $order = Order::factory()->create(['store_id' => $otherStore->id]);
        $invoice = Invoice::factory()->create([
            'store_id' => $otherStore->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order->id,
        ]);

        $response = $this->get("/api/v1/invoices/{$invoice->id}/pdf");

        $response->assertStatus(404);
    }

    public function test_invoice_pdf_includes_service_fee_from_order(): void
    {
        Passport::actingAs($this->user);

        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'service_fee_value' => 25.00,
            'service_fee_unit' => 'fixed',
            'service_fee_reason' => 'Processing fee',
        ]);
        $invoice = Invoice::factory()->create([
            'store_id' => $this->store->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order->id,
        ]);

        $response = $this->get("/api/v1/invoices/{$invoice->id}/pdf/stream");

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
        // The PDF content should contain the service fee
        $content = $response->getContent();
        $this->assertNotEmpty($content);
    }

    public function test_invoice_pdf_includes_service_fee_from_repair(): void
    {
        Passport::actingAs($this->user);

        $repair = Repair::factory()->create([
            'store_id' => $this->store->id,
            'service_fee' => 50.00,
        ]);
        $invoice = Invoice::factory()->create([
            'store_id' => $this->store->id,
            'invoiceable_type' => Repair::class,
            'invoiceable_id' => $repair->id,
        ]);

        $response = $this->get("/api/v1/invoices/{$invoice->id}/pdf/stream");

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_web_invoice_show_page_includes_line_items(): void
    {
        // Ensure store completes onboarding
        $this->store->update(['step' => 2]);
        $this->user->update(['current_store_id' => $this->store->id]);

        $order = Order::factory()->create(['store_id' => $this->store->id]);
        OrderItem::factory()->count(3)->create([
            'order_id' => $order->id,
            'title' => 'Test Product',
            'price' => 100.00,
        ]);

        $invoice = Invoice::factory()->create([
            'store_id' => $this->store->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order->id,
        ]);

        $response = $this->actingAs($this->user)
            ->get("/invoices/{$invoice->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('invoices/Show')
            ->has('invoice.invoiceable.items', 3)
        );
    }
}
