<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Layaway;
use App\Models\LayawayItem;
use App\Models\LayawaySchedule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\Layaways\LayawayService;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LayawayTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected StoreUser $storeUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id, 'step' => 2]);

        // Create default roles for the store
        Role::createDefaultRoles($this->store->id);

        // Get the owner role
        $ownerRole = Role::where('store_id', $this->store->id)
            ->where('slug', 'owner')
            ->first();

        // Create store user with owner role
        $this->storeUser = StoreUser::create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $ownerRole->id,
            'is_owner' => true,
            'status' => 'active',
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $this->user->email,
        ]);

        // Set current store on user
        $this->user->update(['current_store_id' => $this->store->id]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    protected function withStore()
    {
        return $this->withSession(['current_store_id' => $this->store->id]);
    }

    public function test_can_view_layaways_index_page(): void
    {
        $this->actingAs($this->user);

        Layaway::factory()->count(3)->create(['store_id' => $this->store->id]);

        $response = $this->withStore()->get('/layaways');

        $response->assertStatus(200);
    }

    public function test_can_view_create_wizard_page(): void
    {
        $this->actingAs($this->user);

        $response = $this->withStore()->get('/layaways/create');

        $response->assertStatus(200);
    }

    public function test_can_create_layaway_via_wizard(): void
    {
        $this->actingAs($this->user);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 500.00,
        ]);

        $response = $this->withStore()->post('/layaways', [
            'store_user_id' => $this->storeUser->id,
            'customer_id' => $customer->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'title' => 'Diamond Ring',
                    'quantity' => 1,
                    'price' => 500.00,
                ],
            ],
            'payment_type' => Layaway::PAYMENT_TYPE_FLEXIBLE,
            'term_days' => 90,
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('layaways', [
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'status' => Layaway::STATUS_PENDING,
            'payment_type' => Layaway::PAYMENT_TYPE_FLEXIBLE,
            'term_days' => 90,
        ]);

        $this->assertDatabaseHas('layaway_items', [
            'title' => 'Diamond Ring',
            'quantity' => 1,
            'price' => '500.00',
        ]);
    }

    public function test_can_view_layaway_details(): void
    {
        $this->actingAs($this->user);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);
        $layaway = Layaway::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
        ]);
        LayawayItem::factory()->count(2)->create(['layaway_id' => $layaway->id]);

        $response = $this->withStore()->get("/layaways/{$layaway->id}");

        $response->assertStatus(200);
    }

    public function test_layaway_generates_unique_number(): void
    {
        $layaway1 = Layaway::factory()->create(['store_id' => $this->store->id]);
        $layaway2 = Layaway::factory()->create(['store_id' => $this->store->id]);

        $this->assertNotEquals($layaway1->layaway_number, $layaway2->layaway_number);
        $this->assertStringStartsWith('LAY-', $layaway1->layaway_number);
        $this->assertStringStartsWith('LAY-', $layaway2->layaway_number);
    }

    public function test_layaway_calculates_totals(): void
    {
        $layaway = Layaway::factory()->create([
            'store_id' => $this->store->id,
            'tax_rate' => 0.08,
            'subtotal' => 0,
            'tax_amount' => 0,
            'total' => 0,
            'balance_due' => 0,
        ]);

        LayawayItem::factory()->create([
            'layaway_id' => $layaway->id,
            'quantity' => 1,
            'price' => 200.00,
            'line_total' => 200.00,
        ]);

        LayawayItem::factory()->create([
            'layaway_id' => $layaway->id,
            'quantity' => 2,
            'price' => 150.00,
            'line_total' => 300.00,
        ]);

        $layaway->calculateTotals();
        $layaway->refresh();

        // Subtotal: 200 + 300 = 500
        // Tax: 500 * 0.08 = 40
        // Total: 500 + 40 = 540
        $this->assertEquals('500.00', $layaway->subtotal);
        $this->assertEquals('40.00', $layaway->tax_amount);
        $this->assertEquals('540.00', $layaway->total);
        $this->assertEquals('540.00', $layaway->balance_due);
    }

    public function test_can_activate_pending_layaway(): void
    {
        $this->actingAs($this->user);

        $layaway = Layaway::factory()->create([
            'store_id' => $this->store->id,
            'status' => Layaway::STATUS_PENDING,
            'total' => 1000.00,
            'minimum_deposit_percent' => 10.00,
            'total_paid' => 100.00, // Meets 10% minimum deposit
            'balance_due' => 900.00,
        ]);

        $response = $this->withStore()->post("/layaways/{$layaway->id}/activate");

        $response->assertRedirect();

        $this->assertDatabaseHas('layaways', [
            'id' => $layaway->id,
            'status' => Layaway::STATUS_ACTIVE,
        ]);
    }

    public function test_cannot_activate_layaway_without_minimum_deposit(): void
    {
        $layaway = Layaway::factory()->create([
            'store_id' => $this->store->id,
            'status' => Layaway::STATUS_PENDING,
            'total' => 1000.00,
            'minimum_deposit_percent' => 10.00,
            'total_paid' => 50.00, // Below 10% minimum deposit
        ]);

        $service = app(LayawayService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Minimum deposit has not been met.');

        $service->activate($layaway);
    }

    public function test_can_complete_fully_paid_layaway(): void
    {
        $this->actingAs($this->user);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);
        $layaway = Layaway::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'status' => Layaway::STATUS_ACTIVE,
            'subtotal' => 925.93,
            'tax_rate' => 0.08,
            'tax_amount' => 74.07,
            'total' => 1000.00,
            'total_paid' => 1000.00,
            'balance_due' => 0.00,
        ]);

        // Add item so order can be created
        LayawayItem::factory()->create([
            'layaway_id' => $layaway->id,
            'title' => 'Test Item',
            'quantity' => 1,
            'price' => 925.93,
            'line_total' => 925.93,
        ]);

        $response = $this->withStore()->post("/layaways/{$layaway->id}/complete");

        $response->assertRedirect();

        $this->assertDatabaseHas('layaways', [
            'id' => $layaway->id,
            'status' => Layaway::STATUS_COMPLETED,
        ]);
    }

    public function test_cannot_complete_layaway_with_balance(): void
    {
        $layaway = Layaway::factory()->create([
            'store_id' => $this->store->id,
            'status' => Layaway::STATUS_ACTIVE,
            'total' => 1000.00,
            'total_paid' => 500.00,
            'balance_due' => 500.00,
        ]);

        $service = app(LayawayService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Layaway has outstanding balance.');

        $service->complete($layaway);
    }

    public function test_can_cancel_layaway(): void
    {
        $this->actingAs($this->user);

        $layaway = Layaway::factory()->active()->create(['store_id' => $this->store->id]);

        $response = $this->withStore()->post("/layaways/{$layaway->id}/cancel");

        $response->assertRedirect();

        $this->assertDatabaseHas('layaways', [
            'id' => $layaway->id,
            'status' => Layaway::STATUS_CANCELLED,
        ]);
    }

    public function test_cannot_cancel_completed_layaway(): void
    {
        $layaway = Layaway::factory()->completed()->create(['store_id' => $this->store->id]);

        $service = app(LayawayService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot cancel a completed layaway.');

        $service->cancel($layaway);
    }

    public function test_layaway_records_payment(): void
    {
        $layaway = Layaway::factory()->create([
            'store_id' => $this->store->id,
            'status' => Layaway::STATUS_ACTIVE,
            'total' => 500.00,
            'total_paid' => 100.00,
            'balance_due' => 400.00,
        ]);

        $layaway->recordPayment(150.00);
        $layaway->refresh();

        $this->assertEquals('250.00', $layaway->total_paid);
        $this->assertEquals('250.00', $layaway->balance_due);
    }

    public function test_layaway_activates_when_deposit_threshold_met(): void
    {
        $layaway = Layaway::factory()->create([
            'store_id' => $this->store->id,
            'status' => Layaway::STATUS_PENDING,
            'total' => 1000.00,
            'minimum_deposit_percent' => 10.00,
            'total_paid' => 0,
            'balance_due' => 1000.00,
        ]);

        $service = app(LayawayService::class);
        $service->recordPayment($layaway, 100.00); // 10% deposit

        $layaway->refresh();

        $this->assertEquals(Layaway::STATUS_ACTIVE, $layaway->status);
        $this->assertEquals('100.00', $layaway->deposit_amount);
    }

    public function test_layaway_completes_when_fully_paid(): void
    {
        $layaway = Layaway::factory()->create([
            'store_id' => $this->store->id,
            'status' => Layaway::STATUS_ACTIVE,
            'total' => 500.00,
            'total_paid' => 400.00,
            'balance_due' => 100.00,
        ]);

        $service = app(LayawayService::class);
        $service->recordPayment($layaway, 100.00); // Pays remaining balance

        $layaway->refresh();

        $this->assertEquals(Layaway::STATUS_COMPLETED, $layaway->status);
        $this->assertEquals('0.00', $layaway->balance_due);
    }

    public function test_layaway_detects_overdue_status(): void
    {
        $overdueLayaway = Layaway::factory()->overdue()->create(['store_id' => $this->store->id]);
        $activeLayaway = Layaway::factory()->active()->create(['store_id' => $this->store->id]);

        $this->assertTrue($overdueLayaway->isOverdue());
        $this->assertFalse($activeLayaway->isOverdue());
    }

    public function test_layaway_progress_percentage(): void
    {
        $layaway = Layaway::factory()->create([
            'store_id' => $this->store->id,
            'total' => 1000.00,
            'total_paid' => 250.00,
        ]);

        $this->assertEquals(25.0, $layaway->getProgressPercentage());
    }

    public function test_layaway_generates_payment_schedule(): void
    {
        $layaway = Layaway::factory()->scheduled()->create([
            'store_id' => $this->store->id,
            'total' => 1000.00,
            'deposit_amount' => 100.00,
            'minimum_deposit_percent' => 10.00,
        ]);

        $service = app(LayawayService::class);
        $schedules = $service->generatePaymentSchedule($layaway, 3, LayawaySchedule::FREQUENCY_MONTHLY);

        $this->assertCount(3, $schedules);

        // Remaining after deposit: 1000 - 100 = 900
        // Each payment: 900 / 3 = 300
        $this->assertEquals('300.00', $schedules[0]->amount_due);
        $this->assertEquals(1, $schedules[0]->installment_number);
        $this->assertEquals(LayawaySchedule::STATUS_PENDING, $schedules[0]->status);
    }

    public function test_payment_applied_to_schedules(): void
    {
        $layaway = Layaway::factory()->scheduled()->active()->create([
            'store_id' => $this->store->id,
            'total' => 400.00,
            'total_paid' => 100.00,
            'balance_due' => 300.00,
        ]);

        // Create schedules with explicit due dates to ensure correct ordering
        LayawaySchedule::factory()->create([
            'layaway_id' => $layaway->id,
            'installment_number' => 1,
            'due_date' => now()->addDays(7),
            'amount_due' => 100.00,
            'amount_paid' => 0,
            'status' => LayawaySchedule::STATUS_PENDING,
        ]);

        LayawaySchedule::factory()->create([
            'layaway_id' => $layaway->id,
            'installment_number' => 2,
            'due_date' => now()->addDays(14),
            'amount_due' => 100.00,
            'amount_paid' => 0,
            'status' => LayawaySchedule::STATUS_PENDING,
        ]);

        $service = app(LayawayService::class);
        $service->recordPayment($layaway, 150.00);

        $schedules = $layaway->schedules()->orderBy('installment_number')->get();

        // First schedule fully paid
        $this->assertEquals('100.00', $schedules[0]->amount_paid);
        $this->assertEquals(LayawaySchedule::STATUS_PAID, $schedules[0]->status);

        // Second schedule partially paid
        $this->assertEquals('50.00', $schedules[1]->amount_paid);
        $this->assertEquals(LayawaySchedule::STATUS_PENDING, $schedules[1]->status);
    }

    public function test_schedule_marks_overdue(): void
    {
        $schedule = LayawaySchedule::factory()->create([
            'status' => LayawaySchedule::STATUS_PENDING,
            'due_date' => now()->subDays(5),
            'amount_due' => 100.00,
            'amount_paid' => 0,
        ]);

        $schedule->markOverdue();
        $schedule->refresh();

        $this->assertEquals(LayawaySchedule::STATUS_OVERDUE, $schedule->status);
    }

    public function test_cancelling_layaway_releases_reserved_items(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'quantity' => 100,
        ]);

        $layaway = Layaway::factory()->active()->create(['store_id' => $this->store->id]);

        LayawayItem::factory()->create([
            'layaway_id' => $layaway->id,
            'product_id' => $product->id,
            'quantity' => 5,
            'is_reserved' => true,
        ]);

        $service = app(LayawayService::class);
        $service->cancel($layaway);

        $product->refresh();
        $this->assertEquals(105, $product->quantity); // Restored 5 items
    }

    public function test_cancellation_fee_calculation(): void
    {
        $layaway = Layaway::factory()->create([
            'store_id' => $this->store->id,
            'total_paid' => 200.00,
            'cancellation_fee_percent' => 10.00,
        ]);

        // Cancellation fee: 200 * 10% = 20
        $this->assertEquals(20.00, $layaway->cancellation_fee);
    }

    public function test_refund_amount_calculation(): void
    {
        $layaway = Layaway::factory()->create([
            'store_id' => $this->store->id,
            'total_paid' => 200.00,
            'cancellation_fee_percent' => 10.00,
        ]);

        $service = app(LayawayService::class);
        $refundAmount = $service->calculateRefundAmount($layaway);

        // Refund: 200 - 20 = 180
        $this->assertEquals(180.00, $refundAmount);
    }

    public function test_payable_interface_implementation(): void
    {
        $layaway = Layaway::factory()->create([
            'store_id' => $this->store->id,
            'subtotal' => 500.00,
            'total' => 540.00,
            'total_paid' => 100.00,
            'balance_due' => 440.00,
        ]);

        $this->assertEquals($this->store->id, $layaway->getStoreId());
        $this->assertEquals(500.00, $layaway->getSubtotal());
        $this->assertEquals(540.00, $layaway->getGrandTotal());
        $this->assertEquals(100.00, $layaway->getTotalPaid());
        $this->assertEquals(440.00, $layaway->getBalanceDue());
        $this->assertTrue($layaway->canReceivePayment());
        $this->assertFalse($layaway->isFullyPaid());
        $this->assertEquals('layaway', Layaway::getPayableTypeName());
    }

    public function test_only_store_layaways_are_visible(): void
    {
        $this->actingAs($this->user);

        $otherStore = Store::factory()->create();
        Layaway::factory()->count(2)->create(['store_id' => $this->store->id]);
        Layaway::factory()->count(3)->create(['store_id' => $otherStore->id]);

        // The widget/query should only return store layaways
        $layaways = Layaway::where('store_id', $this->store->id)->get();

        $this->assertCount(2, $layaways);
    }

    public function test_can_bulk_delete_pending_layaways(): void
    {
        $this->actingAs($this->user);

        $pending1 = Layaway::factory()->pending()->create(['store_id' => $this->store->id]);
        $pending2 = Layaway::factory()->pending()->create(['store_id' => $this->store->id]);

        $response = $this->withStore()->post('/layaways/bulk-action', [
            'action' => 'delete',
            'ids' => [$pending1->id, $pending2->id],
        ]);

        $response->assertRedirect();

        $this->assertSoftDeleted('layaways', ['id' => $pending1->id]);
        $this->assertSoftDeleted('layaways', ['id' => $pending2->id]);
    }

    public function test_status_helpers(): void
    {
        $pending = Layaway::factory()->pending()->create(['store_id' => $this->store->id]);
        $active = Layaway::factory()->active()->create(['store_id' => $this->store->id]);
        $completed = Layaway::factory()->completed()->create(['store_id' => $this->store->id]);
        $cancelled = Layaway::factory()->cancelled()->create(['store_id' => $this->store->id]);
        $defaulted = Layaway::factory()->defaulted()->create(['store_id' => $this->store->id]);

        $this->assertTrue($pending->isPending());
        $this->assertTrue($active->isActive());
        $this->assertTrue($completed->isCompleted());
        $this->assertTrue($cancelled->isCancelled());
        $this->assertTrue($defaulted->isDefaulted());
    }

    public function test_payment_type_helpers(): void
    {
        $flexible = Layaway::factory()->flexible()->create(['store_id' => $this->store->id]);
        $scheduled = Layaway::factory()->scheduled()->create(['store_id' => $this->store->id]);

        $this->assertTrue($flexible->isFlexible());
        $this->assertFalse($flexible->isScheduled());
        $this->assertTrue($scheduled->isScheduled());
        $this->assertFalse($scheduled->isFlexible());
    }

    public function test_minimum_deposit_calculation(): void
    {
        $layaway = Layaway::factory()->create([
            'store_id' => $this->store->id,
            'total' => 500.00,
            'minimum_deposit_percent' => 20.00,
        ]);

        // Minimum deposit: 500 * 20% = 100
        $this->assertEquals(100.00, $layaway->minimum_deposit);
    }

    public function test_get_next_scheduled_payment(): void
    {
        $layaway = Layaway::factory()->scheduled()->create(['store_id' => $this->store->id]);

        LayawaySchedule::factory()->paid()->create([
            'layaway_id' => $layaway->id,
            'installment_number' => 1,
            'due_date' => now()->subDays(30),
        ]);

        $nextSchedule = LayawaySchedule::factory()->pending()->create([
            'layaway_id' => $layaway->id,
            'installment_number' => 2,
            'due_date' => now()->addDays(7),
        ]);

        LayawaySchedule::factory()->pending()->create([
            'layaway_id' => $layaway->id,
            'installment_number' => 3,
            'due_date' => now()->addDays(37),
        ]);

        $next = $layaway->getNextScheduledPayment();

        $this->assertNotNull($next);
        $this->assertEquals($nextSchedule->id, $next->id);
        $this->assertEquals(2, $next->installment_number);
    }

    public function test_process_layaway_reminders_marks_overdue_schedules(): void
    {
        $layaway = Layaway::factory()->scheduled()->active()->create(['store_id' => $this->store->id]);

        // Create a pending schedule that's past due
        $overdueSchedule = LayawaySchedule::factory()->create([
            'layaway_id' => $layaway->id,
            'status' => LayawaySchedule::STATUS_PENDING,
            'due_date' => now()->subDays(3),
            'amount_due' => 100.00,
            'amount_paid' => 0,
        ]);

        // Run the reminders job
        $job = new \App\Jobs\ProcessLayawayReminders;
        $job->handle();

        $overdueSchedule->refresh();

        $this->assertEquals(LayawaySchedule::STATUS_OVERDUE, $overdueSchedule->status);
    }

    public function test_process_layaway_reminders_does_not_affect_paid_schedules(): void
    {
        $layaway = Layaway::factory()->scheduled()->active()->create(['store_id' => $this->store->id]);

        // Create a paid schedule even if it's past due date
        $paidSchedule = LayawaySchedule::factory()->paid()->create([
            'layaway_id' => $layaway->id,
            'due_date' => now()->subDays(3),
        ]);

        // Run the reminders job
        $job = new \App\Jobs\ProcessLayawayReminders;
        $job->handle();

        $paidSchedule->refresh();

        // Should still be paid
        $this->assertEquals(LayawaySchedule::STATUS_PAID, $paidSchedule->status);
    }
}
