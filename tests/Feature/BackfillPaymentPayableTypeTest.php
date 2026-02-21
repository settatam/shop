<?php

namespace Tests\Feature;

use App\Models\Memo;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Repair;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillPaymentPayableTypeTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected User $user;

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
    }

    public function test_backfill_updates_payment_to_memo_for_mem_prefix(): void
    {
        // Create a memo with MEM prefix
        $memo = Memo::factory()->create([
            'store_id' => $this->store->id,
        ]);

        // Create an order with the memo's invoice_number
        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'invoice_number' => $memo->memo_number,
        ]);

        // Create a payment pointing to the order (simulating legacy data)
        $payment = Payment::factory()->create([
            'store_id' => $this->store->id,
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'order_id' => $order->id,
        ]);

        // Run the backfill command
        $this->artisan('payments:backfill-payable-type', [
            '--store-id' => $this->store->id,
        ])->assertSuccessful();

        // Verify the payment was updated
        $payment->refresh();
        $this->assertEquals(Memo::class, $payment->payable_type);
        $this->assertEquals($memo->id, $payment->payable_id);
    }

    public function test_backfill_updates_payment_to_repair_for_rep_prefix(): void
    {
        // Create a repair with REP prefix
        $repair = Repair::factory()->create([
            'store_id' => $this->store->id,
        ]);

        // Create an order with the repair's invoice_number
        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'invoice_number' => $repair->repair_number,
        ]);

        // Create a payment pointing to the order (simulating legacy data)
        $payment = Payment::factory()->create([
            'store_id' => $this->store->id,
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'order_id' => $order->id,
        ]);

        // Run the backfill command
        $this->artisan('payments:backfill-payable-type', [
            '--store-id' => $this->store->id,
        ])->assertSuccessful();

        // Verify the payment was updated
        $payment->refresh();
        $this->assertEquals(Repair::class, $payment->payable_type);
        $this->assertEquals($repair->id, $payment->payable_id);
    }

    public function test_backfill_does_not_change_regular_order_payments(): void
    {
        // Create an order with a regular invoice number (not MEM or REP prefix)
        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'invoice_number' => 'INV-12345',
        ]);

        // Create a payment for this order
        $payment = Payment::factory()->create([
            'store_id' => $this->store->id,
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'order_id' => $order->id,
        ]);

        // Run the backfill command
        $this->artisan('payments:backfill-payable-type', [
            '--store-id' => $this->store->id,
        ])->assertSuccessful();

        // Verify the payment was NOT changed
        $payment->refresh();
        $this->assertEquals(Order::class, $payment->payable_type);
        $this->assertEquals($order->id, $payment->payable_id);
    }

    public function test_dry_run_does_not_make_changes(): void
    {
        // Create a memo with MEM prefix
        $memo = Memo::factory()->create([
            'store_id' => $this->store->id,
        ]);

        // Create an order with the memo's invoice_number
        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'invoice_number' => $memo->memo_number,
        ]);

        // Create a payment pointing to the order
        $payment = Payment::factory()->create([
            'store_id' => $this->store->id,
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'order_id' => $order->id,
        ]);

        // Run the backfill command with dry-run
        $this->artisan('payments:backfill-payable-type', [
            '--store-id' => $this->store->id,
            '--dry-run' => true,
        ])->assertSuccessful();

        // Verify the payment was NOT changed
        $payment->refresh();
        $this->assertEquals(Order::class, $payment->payable_type);
        $this->assertEquals($order->id, $payment->payable_id);
    }

    public function test_backfill_only_affects_specified_store(): void
    {
        $otherStore = Store::factory()->create(['user_id' => $this->user->id]);

        // Create a memo in the other store
        $memo = Memo::factory()->create([
            'store_id' => $otherStore->id,
        ]);

        // Create an order with the memo's invoice_number in the other store
        $order = Order::factory()->create([
            'store_id' => $otherStore->id,
            'invoice_number' => $memo->memo_number,
        ]);

        // Create a payment in the other store
        $payment = Payment::factory()->create([
            'store_id' => $otherStore->id,
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'order_id' => $order->id,
        ]);

        // Run the backfill command for THIS store only
        $this->artisan('payments:backfill-payable-type', [
            '--store-id' => $this->store->id,
        ])->assertSuccessful();

        // Verify the payment in the OTHER store was NOT changed
        $payment->refresh();
        $this->assertEquals(Order::class, $payment->payable_type);
    }

    public function test_backfill_handles_missing_memo(): void
    {
        // Create an order with a MEM prefix but no matching memo
        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'invoice_number' => 'MEM-99999', // No matching memo exists
        ]);

        // Create a payment for this order
        $payment = Payment::factory()->create([
            'store_id' => $this->store->id,
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'order_id' => $order->id,
        ]);

        // Run the backfill command - should complete without error
        $this->artisan('payments:backfill-payable-type', [
            '--store-id' => $this->store->id,
        ])->assertSuccessful();

        // Verify the payment was NOT changed (memo not found)
        $payment->refresh();
        $this->assertEquals(Order::class, $payment->payable_type);
    }

    public function test_backfill_handles_missing_repair(): void
    {
        // Create an order with a REP prefix but no matching repair
        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'invoice_number' => 'REP-99999', // No matching repair exists
        ]);

        // Create a payment for this order
        $payment = Payment::factory()->create([
            'store_id' => $this->store->id,
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'order_id' => $order->id,
        ]);

        // Run the backfill command - should complete without error
        $this->artisan('payments:backfill-payable-type', [
            '--store-id' => $this->store->id,
        ])->assertSuccessful();

        // Verify the payment was NOT changed (repair not found)
        $payment->refresh();
        $this->assertEquals(Order::class, $payment->payable_type);
    }

    public function test_backfill_without_store_id_processes_all_stores(): void
    {
        // Create a memo with MEM prefix
        $memo = Memo::factory()->create([
            'store_id' => $this->store->id,
        ]);

        // Create an order with the memo's invoice_number
        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'invoice_number' => $memo->memo_number,
        ]);

        // Create a payment pointing to the order
        $payment = Payment::factory()->create([
            'store_id' => $this->store->id,
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'order_id' => $order->id,
        ]);

        // Run the backfill command WITHOUT store-id
        $this->artisan('payments:backfill-payable-type')
            ->assertSuccessful();

        // Verify the payment was updated
        $payment->refresh();
        $this->assertEquals(Memo::class, $payment->payable_type);
        $this->assertEquals($memo->id, $payment->payable_id);
    }
}
