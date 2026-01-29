<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Memo;
use App\Models\Order;
use App\Models\Repair;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ActivityLogFormatter;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLogTest extends TestCase
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

    public function test_order_creation_logs_activity(): void
    {
        $this->actingAs($this->user);

        $order = Order::create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'status' => Order::STATUS_PENDING,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'store_id' => $this->store->id,
            'subject_type' => Order::class,
            'subject_id' => $order->id,
            'activity_slug' => Activity::ORDERS_CREATE,
        ]);
    }

    public function test_order_update_logs_activity(): void
    {
        $this->actingAs($this->user);

        $order = Order::create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'status' => Order::STATUS_PENDING,
        ]);

        $order->update(['status' => Order::STATUS_CONFIRMED]);

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => Order::class,
            'subject_id' => $order->id,
            'activity_slug' => Activity::ORDERS_UPDATE,
        ]);
    }

    public function test_transaction_creation_logs_activity(): void
    {
        $this->actingAs($this->user);

        $transaction = Transaction::create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'status' => Transaction::STATUS_PENDING,
            'type' => Transaction::TYPE_IN_STORE,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'store_id' => $this->store->id,
            'subject_type' => Transaction::class,
            'subject_id' => $transaction->id,
            'activity_slug' => Activity::TRANSACTIONS_CREATE,
        ]);
    }

    public function test_transaction_update_logs_activity(): void
    {
        $this->actingAs($this->user);

        $transaction = Transaction::create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'status' => Transaction::STATUS_PENDING,
            'type' => Transaction::TYPE_IN_STORE,
        ]);

        $transaction->update(['status' => Transaction::STATUS_OFFER_GIVEN]);

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => Transaction::class,
            'subject_id' => $transaction->id,
            'activity_slug' => Activity::TRANSACTIONS_UPDATE,
        ]);
    }

    public function test_memo_creation_logs_activity(): void
    {
        $this->actingAs($this->user);

        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);

        $memo = Memo::create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'vendor_id' => $vendor->id,
            'status' => Memo::STATUS_PENDING,
            'tenure' => 30,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'store_id' => $this->store->id,
            'subject_type' => Memo::class,
            'subject_id' => $memo->id,
            'activity_slug' => Activity::MEMOS_CREATE,
        ]);
    }

    public function test_repair_creation_logs_activity(): void
    {
        $this->actingAs($this->user);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        $repair = Repair::create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'customer_id' => $customer->id,
            'status' => Repair::STATUS_PENDING,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'store_id' => $this->store->id,
            'subject_type' => Repair::class,
            'subject_id' => $repair->id,
            'activity_slug' => Activity::REPAIRS_CREATE,
        ]);
    }

    public function test_customer_creation_logs_activity(): void
    {
        $this->actingAs($this->user);

        $customer = Customer::create([
            'store_id' => $this->store->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'store_id' => $this->store->id,
            'subject_type' => Customer::class,
            'subject_id' => $customer->id,
            'activity_slug' => Activity::CUSTOMERS_CREATE,
        ]);
    }

    public function test_vendor_creation_logs_activity(): void
    {
        $this->actingAs($this->user);

        $vendor = Vendor::create([
            'store_id' => $this->store->id,
            'name' => 'Test Vendor',
            'email' => 'vendor@example.com',
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'store_id' => $this->store->id,
            'subject_type' => Vendor::class,
            'subject_id' => $vendor->id,
            'activity_slug' => Activity::VENDORS_CREATE,
        ]);
    }

    public function test_activity_log_formatter_formats_logs_correctly(): void
    {
        $this->actingAs($this->user);

        $order = Order::create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'status' => Order::STATUS_PENDING,
        ]);

        $order->update(['status' => Order::STATUS_CONFIRMED]);
        $order->update(['notes' => 'Test notes']);

        $formatter = app(ActivityLogFormatter::class);
        $logs = $formatter->formatForSubject($order);

        $this->assertIsArray($logs);
        $this->assertNotEmpty($logs);

        // Check the structure
        $firstDay = $logs[0];
        $this->assertArrayHasKey('date', $firstDay);
        $this->assertArrayHasKey('dateTime', $firstDay);
        $this->assertArrayHasKey('items', $firstDay);

        $firstItem = $firstDay['items'][0];
        $this->assertArrayHasKey('id', $firstItem);
        $this->assertArrayHasKey('activity', $firstItem);
        $this->assertArrayHasKey('description', $firstItem);
        $this->assertArrayHasKey('user', $firstItem);
        $this->assertArrayHasKey('time', $firstItem);
        $this->assertArrayHasKey('icon', $firstItem);
        $this->assertArrayHasKey('color', $firstItem);
    }

    public function test_activity_log_formatter_groups_by_date(): void
    {
        $this->actingAs($this->user);

        $order = Order::create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'status' => Order::STATUS_PENDING,
        ]);

        // Create a log for today
        $todayLog = ActivityLog::forSubject($order)->first();
        $this->assertNotNull($todayLog);

        // Create a log for yesterday by inserting directly
        $yesterdayLog = ActivityLog::create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'activity_slug' => Activity::ORDERS_UPDATE,
            'subject_type' => Order::class,
            'subject_id' => $order->id,
        ]);
        // Force the created_at to be yesterday
        $yesterdayLog->created_at = now()->subDay();
        $yesterdayLog->save(['timestamps' => false]);

        $formatter = app(ActivityLogFormatter::class);
        $logs = $formatter->formatForSubject($order);

        $this->assertCount(2, $logs);
        $this->assertEquals('Today', $logs[0]['date']);
        $this->assertEquals('Yesterday', $logs[1]['date']);
    }

    public function test_activity_log_captures_changes(): void
    {
        $this->actingAs($this->user);

        $order = Order::create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'status' => Order::STATUS_PENDING,
        ]);

        $order->update(['status' => Order::STATUS_CONFIRMED]);

        $log = ActivityLog::forSubject($order)
            ->where('activity_slug', Activity::ORDERS_UPDATE)
            ->first();

        $this->assertNotNull($log);
        $this->assertNotNull($log->properties);
        $this->assertArrayHasKey('old', $log->properties);
        $this->assertArrayHasKey('new', $log->properties);
    }

    public function test_activity_log_records_user(): void
    {
        $this->actingAs($this->user);

        $order = Order::create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'status' => Order::STATUS_PENDING,
        ]);

        $log = ActivityLog::forSubject($order)->first();

        $this->assertNotNull($log);
        $this->assertEquals($this->user->id, $log->user_id);
        $this->assertEquals($this->user->id, $log->causer_id);
    }

    public function test_activity_log_belongs_to_store(): void
    {
        $this->actingAs($this->user);

        $order = Order::create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'status' => Order::STATUS_PENDING,
        ]);

        $log = ActivityLog::forSubject($order)->first();

        $this->assertNotNull($log);
        $this->assertEquals($this->store->id, $log->store_id);
    }
}
