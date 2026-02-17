<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderPrintInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->onboarded()->create(['user_id' => $this->user->id]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_order_print_invoice_redirects_to_invoice_print_when_invoice_exists(): void
    {
        $this->actingAs($this->user);

        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
        ]);

        $invoice = Invoice::factory()->create([
            'store_id' => $this->store->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order->id,
        ]);

        $response = $this->get(route('web.orders.print-invoice', $order));

        $response->assertRedirect(route('invoices.print', $invoice));
    }

    public function test_order_print_invoice_creates_invoice_when_none_exists(): void
    {
        $this->actingAs($this->user);

        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'invoice_number' => 'ORD-123',
            'sub_total' => 100.00,
            'sales_tax' => 7.00,
            'shipping_cost' => 5.00,
            'discount_cost' => 0,
            'total' => 112.00,
        ]);

        // Ensure no invoice exists
        $this->assertNull($order->invoice);

        $response = $this->get(route('web.orders.print-invoice', $order));

        // An invoice should now exist
        $order->refresh();
        $this->assertNotNull($order->invoice);

        // Should redirect to the invoice print route
        $response->assertRedirect(route('invoices.print', $order->invoice));
    }

    public function test_order_print_invoice_returns_404_for_other_store(): void
    {
        $this->actingAs($this->user);

        $otherStore = Store::factory()->create();
        $order = Order::factory()->create([
            'store_id' => $otherStore->id,
        ]);

        $response = $this->get(route('web.orders.print-invoice', $order));

        $response->assertStatus(404);
    }
}
