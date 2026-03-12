<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Customer;
use App\Models\LeadSource;
use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\Status;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Reports\BuysReportService;
use App\Services\Reports\SalesReportService;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyReportNotificationTest extends TestCase
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

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_activity_constants_are_registered(): void
    {
        $this->assertEquals('reports.daily_buy', Activity::REPORTS_DAILY_BUY);
        $this->assertEquals('reports.daily_sales', Activity::REPORTS_DAILY_SALES);

        $definitions = Activity::getDefinitions();
        $this->assertArrayHasKey(Activity::REPORTS_DAILY_BUY, $definitions);
        $this->assertArrayHasKey(Activity::REPORTS_DAILY_SALES, $definitions);
        $this->assertEquals('reports', $definitions[Activity::REPORTS_DAILY_BUY]['category']);
        $this->assertEquals('reports', $definitions[Activity::REPORTS_DAILY_SALES]['category']);
    }

    public function test_buys_report_includes_lead_status_and_payment_method(): void
    {
        $leadSource = LeadSource::factory()->create(['store_id' => $this->store->id]);
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'lead_source_id' => $leadSource->id,
        ]);

        $paymentProcessedStatus = Status::factory()->forTransaction()->create([
            'store_id' => $this->store->id,
            'name' => 'Payment Processed',
            'slug' => 'payment_processed',
        ]);

        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'status_id' => $paymentProcessedStatus->id,
            'status' => Transaction::STATUS_PAYMENT_PROCESSED,
            'type' => Transaction::TYPE_IN_STORE,
            'final_offer' => 400,
            'payment_method' => 'cash',
            'payment_processed_at' => now(),
        ]);

        TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'price' => 500,
        ]);

        $service = new BuysReportService;
        $results = $service->getDailyBuys(
            $this->store->id,
            now()->startOfDay(),
            now()->endOfDay()
        );

        $this->assertCount(1, $results);
        $row = $results->first();

        $this->assertEquals($leadSource->name, $row['lead']);
        $this->assertEquals('Payment Processed', $row['status']);
        $this->assertEquals('Cash', $row['payment_method']);
    }

    public function test_sales_report_includes_vendor(): void
    {
        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
        ]);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);
        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'status' => Order::STATUS_COMPLETED,
            'sub_total' => 100,
            'total' => 107,
            'sales_tax' => 7,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 100,
        ]);

        Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'store_id' => $this->store->id,
            'amount' => 107,
            'status' => Payment::STATUS_COMPLETED,
        ]);

        $service = new SalesReportService;
        $results = $service->getDailySales(
            $this->store->id,
            now()->startOfDay(),
            now()->endOfDay()
        );

        $this->assertCount(1, $results);
        $row = $results->first();

        $this->assertEquals($vendor->name, $row['vendor']);
    }

    public function test_sales_aggregation_includes_shopify_reb_split(): void
    {
        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        // Shopify order
        $shopifyOrder = Order::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'status' => Order::STATUS_COMPLETED,
            'sub_total' => 200,
            'total' => 200,
            'source_platform' => 'shopify',
        ]);

        OrderItem::factory()->create([
            'order_id' => $shopifyOrder->id,
            'quantity' => 1,
            'price' => 200,
        ]);

        Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $shopifyOrder->id,
            'store_id' => $this->store->id,
            'amount' => 200,
            'status' => Payment::STATUS_COMPLETED,
        ]);

        // In-store order (non-shopify = Reb)
        $inStoreOrder = Order::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'status' => Order::STATUS_COMPLETED,
            'sub_total' => 300,
            'total' => 300,
            'source_platform' => null,
        ]);

        OrderItem::factory()->create([
            'order_id' => $inStoreOrder->id,
            'quantity' => 1,
            'price' => 300,
        ]);

        Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $inStoreOrder->id,
            'store_id' => $this->store->id,
            'amount' => 300,
            'status' => Payment::STATUS_COMPLETED,
        ]);

        $service = new SalesReportService;
        $data = $service->getDailyAggregatedData(
            $this->store->id,
            now()->startOfDay(),
            now()->endOfDay()
        );

        $totals = $service->calculateAggregatedTotals($data);

        $this->assertEquals(200, $totals['total_shopify']);
        $this->assertEquals(300, $totals['total_reb']);
    }

    public function test_default_templates_include_report_templates(): void
    {
        $templates = NotificationTemplate::getDefaultTemplates();
        $slugs = collect($templates)->pluck('slug')->toArray();

        $this->assertContains('daily-buy-report', $slugs);
        $this->assertContains('daily-sales-report', $slugs);

        $buyTemplate = collect($templates)->firstWhere('slug', 'daily-buy-report');
        $this->assertEquals('reports', $buyTemplate['category']);
        $this->assertStringContains('report_html|raw', $buyTemplate['content']);

        $salesTemplate = collect($templates)->firstWhere('slug', 'daily-sales-report');
        $this->assertEquals('reports', $salesTemplate['category']);
        $this->assertStringContains('report_html|raw', $salesTemplate['content']);
    }

    public function test_default_subscriptions_include_report_subscriptions(): void
    {
        $subscriptions = NotificationSubscription::getDefaultSubscriptions();

        $this->assertArrayHasKey('daily-buy-report', $subscriptions);
        $this->assertArrayHasKey('daily-sales-report', $subscriptions);

        $this->assertEquals(Activity::REPORTS_DAILY_BUY, $subscriptions['daily-buy-report']['activity']);
        $this->assertEquals(Activity::REPORTS_DAILY_SALES, $subscriptions['daily-sales-report']['activity']);
    }

    public function test_send_daily_reports_command_with_dry_run(): void
    {
        // Set up template and subscription
        NotificationChannel::factory()->email()->create([
            'store_id' => $this->store->id,
        ]);

        $template = NotificationTemplate::create([
            'store_id' => $this->store->id,
            'slug' => 'daily-buy-report',
            'name' => 'Daily Buy Report',
            'channel' => NotificationChannel::TYPE_EMAIL,
            'category' => 'reports',
            'subject' => '{{ date }}',
            'content' => '{{ report_html|raw }}',
            'available_variables' => ['date', 'report_html', 'store'],
            'is_system' => true,
            'is_enabled' => true,
        ]);

        NotificationSubscription::create([
            'store_id' => $this->store->id,
            'notification_template_id' => $template->id,
            'activity' => Activity::REPORTS_DAILY_BUY,
            'name' => 'Daily Buy Report',
            'recipients' => [['type' => 'owner']],
            'schedule_type' => NotificationSubscription::SCHEDULE_IMMEDIATE,
            'is_enabled' => true,
        ]);

        $this->artisan('reports:send-daily', [
            '--store' => $this->store->id,
            '--type' => 'buy',
            '--date' => now()->format('Y-m-d'),
            '--dry-run' => true,
        ])->assertSuccessful();
    }

    public function test_send_daily_reports_command_skips_stores_without_subscriptions(): void
    {
        $this->artisan('reports:send-daily', [
            '--store' => $this->store->id,
            '--type' => 'buy',
            '--dry-run' => true,
        ])->assertSuccessful();
    }

    /**
     * Assert that a string contains another string.
     */
    protected function assertStringContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            $message ?: "Failed asserting that '{$haystack}' contains '{$needle}'"
        );
    }
}
