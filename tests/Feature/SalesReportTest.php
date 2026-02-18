<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesReportTest extends TestCase
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
            'step' => 2, // Complete onboarding
        ]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_can_view_daily_sales_report(): void
    {
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

        $response = $this->actingAs($this->user)
            ->get('/reports/sales/daily?date='.now()->format('Y-m-d'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/sales/Daily')
            ->has('orders')
            ->has('totals')
            ->has('date')
        );
    }

    public function test_can_view_daily_items_sales_report(): void
    {
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

        $response = $this->actingAs($this->user)
            ->get('/reports/sales/daily-items?date='.now()->format('Y-m-d'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/sales/DailyItems')
            ->has('items')
            ->has('totals')
            ->has('date')
        );
    }

    public function test_can_export_daily_items_sales_report_csv(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/reports/sales/daily-items/export?date='.now()->format('Y-m-d'));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    }

    public function test_can_view_monthly_sales_report(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/reports/sales/monthly');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/sales/Monthly')
            ->has('monthlyData')
            ->has('totals')
        );
    }

    public function test_can_view_month_to_date_sales_report(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/reports/sales/mtd');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/sales/MonthToDate')
            ->has('dailyData')
            ->has('totals')
            ->has('month')
        );
    }

    public function test_can_export_daily_sales_report_csv(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/reports/sales/daily/export?date='.now()->format('Y-m-d'));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    }

    public function test_can_view_in_store_buys_report(): void
    {
        $customer = Customer::factory()->create(['store_id' => $this->store->id]);
        Transaction::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'type' => Transaction::TYPE_IN_STORE,
            'status' => Transaction::STATUS_PAYMENT_PROCESSED,
            'estimated_value' => 500,
            'final_offer' => 400,
            'payment_processed_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->get('/reports/buys/in-store');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/buys/InStore')
            ->has('dailyData')
            ->has('totals')
            ->has('month')
        );
    }

    public function test_can_view_in_store_monthly_buys_report(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/reports/buys/in-store/monthly');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/buys/InStoreMonthly')
            ->has('monthlyData')
            ->has('totals')
        );
    }

    public function test_can_view_online_buys_report(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/reports/buys/online');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/buys/Online')
            ->has('dailyData')
            ->has('totals')
            ->has('month')
        );
    }

    public function test_can_view_trade_in_buys_report(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/reports/buys/trade-in');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/buys/TradeIn')
            ->has('dailyData')
            ->has('totals')
            ->has('month')
        );
    }

    public function test_can_export_in_store_buys_report_csv(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/reports/buys/in-store/export');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    }

    public function test_can_view_unified_buys_report(): void
    {
        $customer = Customer::factory()->create(['store_id' => $this->store->id]);
        Transaction::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'type' => Transaction::TYPE_IN_STORE,
            'status' => Transaction::STATUS_PAYMENT_PROCESSED,
            'estimated_value' => 500,
            'final_offer' => 400,
            'payment_processed_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->get('/reports/buys');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/buys/Index')
            ->has('dailyData')
            ->has('totals')
            ->has('month')
        );
    }

    public function test_can_view_unified_buys_monthly_report(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/reports/buys/monthly');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/buys/Monthly')
            ->has('monthlyData')
            ->has('totals')
        );
    }

    public function test_can_view_unified_buys_yearly_report(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/reports/buys/yearly');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/buys/Yearly')
            ->has('yearlyData')
            ->has('totals')
        );
    }

    public function test_can_view_leads_funnel_report(): void
    {
        $customer = Customer::factory()->create(['store_id' => $this->store->id]);
        Transaction::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'type' => Transaction::TYPE_MAIL_IN,
            'status' => Transaction::STATUS_OFFER_GIVEN,
            'estimated_value' => 500,
            'offer_given_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->get('/reports/leads');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/leads/Index')
            ->has('dailyData')
            ->has('totals')
            ->has('month')
        );
    }

    public function test_can_view_leads_monthly_report(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/reports/leads/monthly');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/leads/Monthly')
            ->has('monthlyData')
            ->has('totals')
        );
    }

    public function test_can_view_leads_yearly_report(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/reports/leads/yearly');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/leads/Yearly')
            ->has('yearlyData')
            ->has('totals')
        );
    }

    public function test_can_view_daily_kits_report(): void
    {
        // Create a transaction with kit delivered but not returned
        Transaction::factory()->create([
            'store_id' => $this->store->id,
            'type' => Transaction::TYPE_MAIL_IN,
            'source' => Transaction::SOURCE_ONLINE,
            'status' => Transaction::STATUS_KIT_DELIVERED,
            'kit_sent_at' => now()->subDays(5),
            'kit_delivered_at' => now()->subDays(3),
            'items_received_at' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/reports/leads/daily-kits');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/leads/DailyKits')
            ->has('kits')
            ->has('daysBack')
        );
    }

    public function test_can_export_leads_funnel_csv(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/reports/leads/export');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    }
}
