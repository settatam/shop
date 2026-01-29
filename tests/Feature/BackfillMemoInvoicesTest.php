<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Memo;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillMemoInvoicesTest extends TestCase
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

    public function test_backfill_creates_invoices_and_payments_for_completed_memos(): void
    {
        // Create 2 completed memos without invoices
        $memo1 = Memo::factory()->paymentReceived()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'total' => 500.00,
        ]);

        $memo2 = Memo::factory()->paymentReceived()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'total' => 250.00,
        ]);

        // Verify no invoices or payments exist
        $this->assertEquals(0, Invoice::count());
        $this->assertEquals(0, Payment::count());

        // Run the backfill command
        $this->artisan('app:backfill-memo-invoices')
            ->assertSuccessful();

        // Verify invoices were created
        $this->assertEquals(2, Invoice::count());

        // Verify payments were created
        $this->assertEquals(2, Payment::count());

        // Check memo1's invoice
        $invoice1 = $memo1->fresh()->invoice;
        $this->assertNotNull($invoice1);
        $this->assertEquals(Invoice::STATUS_PAID, $invoice1->status);
        $this->assertEquals('500.00', $invoice1->total);
        $this->assertEquals('500.00', $invoice1->total_paid);
        $this->assertEquals('0.00', $invoice1->balance_due);

        // Check memo1's payment
        $payment1 = Payment::where('payable_type', Memo::class)
            ->where('payable_id', $memo1->id)
            ->first();
        $this->assertNotNull($payment1);
        $this->assertEquals(Payment::STATUS_COMPLETED, $payment1->status);
        $this->assertEquals('500.00', $payment1->amount);
        $this->assertEquals($invoice1->id, $payment1->invoice_id);

        // Check memo2's invoice
        $invoice2 = $memo2->fresh()->invoice;
        $this->assertNotNull($invoice2);
        $this->assertEquals('250.00', $invoice2->total);
    }

    public function test_backfill_skips_memos_that_already_have_invoices(): void
    {
        // Create a completed memo with existing invoice
        $memo = Memo::factory()->paymentReceived()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'total' => 300.00,
        ]);

        // Create invoice manually
        Invoice::create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'invoiceable_type' => Memo::class,
            'invoiceable_id' => $memo->id,
            'total' => 300.00,
            'balance_due' => 0,
            'status' => Invoice::STATUS_PAID,
        ]);

        $this->assertEquals(1, Invoice::count());

        // Run the backfill command
        $this->artisan('app:backfill-memo-invoices')
            ->assertSuccessful()
            ->expectsOutput('No memos need backfilling.');

        // Should still be only 1 invoice
        $this->assertEquals(1, Invoice::count());
    }

    public function test_backfill_skips_memos_that_are_not_completed(): void
    {
        // Create memos in various non-completed statuses
        Memo::factory()->pending()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
        ]);

        Memo::factory()->sentToVendor()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
        ]);

        Memo::factory()->vendorReceived()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
        ]);

        // Run the backfill command
        $this->artisan('app:backfill-memo-invoices')
            ->assertSuccessful()
            ->expectsOutput('No memos need backfilling.');

        // No invoices or payments should be created
        $this->assertEquals(0, Invoice::count());
        $this->assertEquals(0, Payment::count());
    }

    public function test_dry_run_does_not_create_records(): void
    {
        Memo::factory()->paymentReceived()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'total' => 100.00,
        ]);

        // Run with --dry-run flag
        $this->artisan('app:backfill-memo-invoices', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutput('Running in dry-run mode - no changes will be made.');

        // No records should be created
        $this->assertEquals(0, Invoice::count());
        $this->assertEquals(0, Payment::count());
    }

    public function test_backfill_uses_grand_total_when_greater_than_zero(): void
    {
        // Create a memo with grand_total set
        $memo = Memo::factory()->paymentReceived()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'total' => 100.00,
            'grand_total' => 150.00,
        ]);

        $this->artisan('app:backfill-memo-invoices')->assertSuccessful();

        $payment = Payment::where('payable_type', Memo::class)
            ->where('payable_id', $memo->id)
            ->first();

        // Should use grand_total when > 0
        $this->assertEquals('150.00', $payment->amount);
    }

    public function test_backfill_falls_back_to_total_when_grand_total_is_zero(): void
    {
        // Create a memo with grand_total = 0 (old data pattern)
        $memo = Memo::factory()->paymentReceived()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'total' => 200.00,
            'grand_total' => 0.00,
        ]);

        $this->artisan('app:backfill-memo-invoices')->assertSuccessful();

        $payment = Payment::where('payable_type', Memo::class)
            ->where('payable_id', $memo->id)
            ->first();

        // Should fall back to total
        $this->assertEquals('200.00', $payment->amount);
    }
}
