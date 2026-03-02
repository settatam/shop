<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\StoreContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionsReportCohortTest extends TestCase
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

    public function test_can_view_cohort_report(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/reports/transactions/cohort');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/transactions/Cohort')
            ->has('cohortData')
            ->has('totals')
            ->has('statuses')
            ->has('filters')
        );
    }

    public function test_can_view_cohort_report_with_date_range_filter(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/reports/transactions/cohort?start_date=2025-01-01&end_date=2025-06-30');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/transactions/Cohort')
            ->has('cohortData', 6)
            ->where('startDate', '2025-01-01')
            ->where('endDate', '2025-06-30')
        );
    }

    public function test_can_view_cohort_report_with_status_filter(): void
    {
        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        Transaction::factory()->mailIn()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'created_at' => now()->startOfMonth(),
            'status' => Transaction::STATUS_ITEMS_RECEIVED,
            'items_received_at' => now(),
        ]);

        Transaction::factory()->mailIn()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'created_at' => now()->startOfMonth(),
            'status' => Transaction::STATUS_KIT_REQUEST_REJECTED,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/reports/transactions/cohort?status='.Transaction::STATUS_ITEMS_RECEIVED);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/transactions/Cohort')
            ->has('totals', fn ($totals) => $totals
                ->where('kits_requested', 1)
                ->where('kits_received', 1)
                ->etc()
            )
            ->has('filters', fn ($filters) => $filters
                ->where('status', Transaction::STATUS_ITEMS_RECEIVED)
            )
        );
    }

    public function test_can_view_cohort_report_with_combined_filters(): void
    {
        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        // Transaction in January 2025 with matching status
        Transaction::factory()->mailIn()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'created_at' => Carbon::create(2025, 1, 15),
            'status' => Transaction::STATUS_ITEMS_RECEIVED,
            'items_received_at' => Carbon::create(2025, 1, 20),
        ]);

        // Transaction in March 2025 with different status - should be excluded
        Transaction::factory()->mailIn()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'created_at' => Carbon::create(2025, 3, 10),
            'status' => Transaction::STATUS_KIT_REQUEST_REJECTED,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/reports/transactions/cohort?start_date=2025-01-01&end_date=2025-03-31&status='.Transaction::STATUS_ITEMS_RECEIVED);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/transactions/Cohort')
            ->has('cohortData', 3)
            ->has('totals', fn ($totals) => $totals
                ->where('kits_requested', 1)
                ->where('kits_received', 1)
                ->etc()
            )
        );
    }

    public function test_cohort_tracks_milestones_by_creation_month(): void
    {
        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        // Create a mail-in transaction this month that has received items
        $transaction = Transaction::factory()->mailIn()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'created_at' => now()->startOfMonth(),
            'items_received_at' => now(),
            'status' => Transaction::STATUS_ITEMS_RECEIVED,
        ]);

        // Create a mail-in transaction this month that is still pending
        Transaction::factory()->mailIn()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'created_at' => now()->startOfMonth()->addDay(),
            'items_received_at' => null,
            'status' => Transaction::STATUS_PENDING_KIT_REQUEST,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/reports/transactions/cohort');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/transactions/Cohort')
            ->has('totals', fn ($totals) => $totals
                ->where('kits_requested', 2)
                ->where('kits_received', 1)
                ->etc()
            )
        );
    }

    public function test_cohort_counts_payment_processed_as_offers_accepted(): void
    {
        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        $transaction = Transaction::factory()->mailIn()->paymentProcessed()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'created_at' => now()->startOfMonth(),
            'final_offer' => 50,
        ]);

        TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'price' => 100,
            'quantity' => 1,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/reports/transactions/cohort');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/transactions/Cohort')
            ->has('totals', fn ($totals) => $totals
                ->where('offers_accepted', 1)
                ->where('estimated_value', 100)
                ->where('profit', 50)
                ->etc()
            )
        );
    }

    public function test_can_export_cohort_report_csv(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/reports/transactions/cohort/export');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    }

    public function test_can_export_cohort_report_csv_with_filters(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/reports/transactions/cohort/export?start_date=2025-01-01&end_date=2025-03-31&status='.Transaction::STATUS_ITEMS_RECEIVED);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    }

    public function test_unauthenticated_user_cannot_view_cohort_report(): void
    {
        $response = $this->get('/reports/transactions/cohort');

        $response->assertRedirect('/login');
    }

    public function test_cohort_drilldown_returns_transactions_for_metric(): void
    {
        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        Transaction::factory()->mailIn()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'created_at' => Carbon::create(2025, 3, 10),
            'status' => Transaction::STATUS_PENDING_KIT_REQUEST,
        ]);

        Transaction::factory()->mailIn()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'created_at' => Carbon::create(2025, 3, 15),
            'status' => Transaction::STATUS_ITEMS_RECEIVED,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/reports/transactions/cohort/drilldown?start_date=2025-03-01&end_date=2025-03-31&metric=kits_requested');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'transactions');
    }

    public function test_cohort_drilldown_filters_by_status_group(): void
    {
        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        Transaction::factory()->mailIn()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'created_at' => Carbon::create(2025, 3, 10),
            'status' => Transaction::STATUS_KIT_REQUEST_REJECTED,
        ]);

        Transaction::factory()->mailIn()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'created_at' => Carbon::create(2025, 3, 15),
            'status' => Transaction::STATUS_ITEMS_RECEIVED,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/reports/transactions/cohort/drilldown?start_date=2025-03-01&end_date=2025-03-31&metric=kits_declined');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'transactions');
        $response->assertJsonPath('transactions.0.status', 'kit_request_rejected');
    }

    public function test_cohort_drilldown_validates_required_params(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/reports/transactions/cohort/drilldown');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['start_date', 'end_date', 'metric']);
    }

    public function test_cohort_drilldown_validates_metric_value(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/reports/transactions/cohort/drilldown?start_date=2025-03-01&end_date=2025-03-31&metric=invalid_metric');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['metric']);
    }

    public function test_cohort_drilldown_respects_global_status_filter(): void
    {
        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        Transaction::factory()->mailIn()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'created_at' => Carbon::create(2025, 3, 10),
            'status' => Transaction::STATUS_ITEMS_RECEIVED,
        ]);

        Transaction::factory()->mailIn()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'created_at' => Carbon::create(2025, 3, 15),
            'status' => Transaction::STATUS_OFFER_GIVEN,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/reports/transactions/cohort/drilldown?start_date=2025-03-01&end_date=2025-03-31&metric=kits_requested&status=items_received');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'transactions');
        $response->assertJsonPath('transactions.0.status', 'items_received');
    }

    public function test_cohort_includes_actionable_lead_counts(): void
    {
        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        Transaction::factory()->mailIn()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'created_at' => now()->startOfMonth(),
            'status' => Transaction::STATUS_ITEMS_RECEIVED,
        ]);

        Transaction::factory()->mailIn()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'created_at' => now()->startOfMonth(),
            'status' => Transaction::STATUS_OFFER_GIVEN,
        ]);

        Transaction::factory()->mailIn()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'created_at' => now()->startOfMonth(),
            'status' => Transaction::STATUS_KIT_DELIVERED,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/reports/transactions/cohort');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/transactions/Cohort')
            ->has('totals', fn ($totals) => $totals
                ->where('actionable_received_no_offer', 1)
                ->where('actionable_offer_no_response', 1)
                ->where('actionable_delivered_not_received', 1)
                ->etc()
            )
        );
    }

    public function test_cohort_drilldown_actionable_received_no_offer(): void
    {
        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        Transaction::factory()->mailIn()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'created_at' => Carbon::create(2025, 3, 10),
            'status' => Transaction::STATUS_ITEMS_RECEIVED,
        ]);

        Transaction::factory()->mailIn()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'created_at' => Carbon::create(2025, 3, 12),
            'status' => Transaction::STATUS_ITEMS_REVIEWED,
        ]);

        Transaction::factory()->mailIn()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'created_at' => Carbon::create(2025, 3, 15),
            'status' => Transaction::STATUS_OFFER_GIVEN,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/reports/transactions/cohort/drilldown?start_date=2025-03-01&end_date=2025-03-31&metric=received_no_offer');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'transactions');
    }

    public function test_cohort_drilldown_actionable_offer_no_response(): void
    {
        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        Transaction::factory()->mailIn()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'created_at' => Carbon::create(2025, 3, 10),
            'status' => Transaction::STATUS_OFFER_GIVEN,
        ]);

        Transaction::factory()->mailIn()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'created_at' => Carbon::create(2025, 3, 15),
            'status' => Transaction::STATUS_OFFER_ACCEPTED,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/reports/transactions/cohort/drilldown?start_date=2025-03-01&end_date=2025-03-31&metric=offer_no_response');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'transactions');
        $response->assertJsonPath('transactions.0.status', 'offer_given');
    }

    public function test_cohort_drilldown_actionable_delivered_not_received(): void
    {
        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        Transaction::factory()->mailIn()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'created_at' => Carbon::create(2025, 3, 10),
            'status' => Transaction::STATUS_KIT_DELIVERED,
        ]);

        Transaction::factory()->mailIn()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'created_at' => Carbon::create(2025, 3, 15),
            'status' => Transaction::STATUS_ITEMS_RECEIVED,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/reports/transactions/cohort/drilldown?start_date=2025-03-01&end_date=2025-03-31&metric=delivered_not_received');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'transactions');
        $response->assertJsonPath('transactions.0.status', 'kit_delivered');
    }

    public function test_cohort_daily_granularity_returns_day_rows(): void
    {
        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        Transaction::factory()->mailIn()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'created_at' => Carbon::create(2025, 3, 5, 10, 0, 0),
            'status' => Transaction::STATUS_ITEMS_RECEIVED,
        ]);

        Transaction::factory()->mailIn()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'created_at' => Carbon::create(2025, 3, 7, 14, 0, 0),
            'status' => Transaction::STATUS_OFFER_GIVEN,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/reports/transactions/cohort?start_date=2025-03-05&end_date=2025-03-07&granularity=daily');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/transactions/Cohort')
            ->has('cohortData', 3)
            ->where('granularity', 'daily')
            ->has('totals', fn ($totals) => $totals
                ->where('kits_requested', 2)
                ->etc()
            )
        );
    }

    public function test_cohort_daily_granularity_default_is_current_month(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/reports/transactions/cohort?granularity=daily');

        $response->assertStatus(200);

        $expectedDays = (int) now()->format('j');
        $response->assertInertia(fn ($page) => $page
            ->component('reports/transactions/Cohort')
            ->has('cohortData', $expectedDays)
            ->where('granularity', 'daily')
        );
    }

    public function test_cohort_yearly_granularity_returns_year_rows(): void
    {
        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        Transaction::factory()->mailIn()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'created_at' => Carbon::create(2024, 6, 15),
            'status' => Transaction::STATUS_ITEMS_RECEIVED,
        ]);

        Transaction::factory()->mailIn()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'created_at' => Carbon::create(2025, 2, 10),
            'status' => Transaction::STATUS_OFFER_GIVEN,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/reports/transactions/cohort?start_date=2024-01-01&end_date=2025-12-31&granularity=yearly');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/transactions/Cohort')
            ->has('cohortData', 2)
            ->where('granularity', 'yearly')
            ->has('totals', fn ($totals) => $totals
                ->where('kits_requested', 2)
                ->etc()
            )
        );
    }

    public function test_cohort_yearly_granularity_default_is_past_five_years(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/reports/transactions/cohort?granularity=yearly');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/transactions/Cohort')
            ->has('cohortData', 5)
            ->where('granularity', 'yearly')
        );
    }

    public function test_cohort_daily_granularity_includes_actionable_leads(): void
    {
        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        Transaction::factory()->mailIn()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'created_at' => Carbon::create(2025, 3, 5, 10, 0, 0),
            'status' => Transaction::STATUS_ITEMS_RECEIVED,
        ]);

        Transaction::factory()->mailIn()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'created_at' => Carbon::create(2025, 3, 5, 14, 0, 0),
            'status' => Transaction::STATUS_KIT_DELIVERED,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/reports/transactions/cohort?start_date=2025-03-05&end_date=2025-03-05&granularity=daily');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/transactions/Cohort')
            ->has('cohortData', 1)
            ->has('totals', fn ($totals) => $totals
                ->where('actionable_received_no_offer', 1)
                ->where('actionable_delivered_not_received', 1)
                ->etc()
            )
        );
    }

    public function test_cohort_daily_drilldown_works_for_single_day(): void
    {
        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        Transaction::factory()->mailIn()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'created_at' => Carbon::create(2025, 3, 5, 10, 0, 0),
            'status' => Transaction::STATUS_ITEMS_RECEIVED,
        ]);

        Transaction::factory()->mailIn()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'created_at' => Carbon::create(2025, 3, 6, 10, 0, 0),
            'status' => Transaction::STATUS_ITEMS_RECEIVED,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/reports/transactions/cohort/drilldown?start_date=2025-03-05&end_date=2025-03-05&metric=received_no_offer');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'transactions');
    }

    public function test_cohort_default_granularity_is_monthly(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/reports/transactions/cohort');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/transactions/Cohort')
            ->where('granularity', 'monthly')
            ->has('cohortData', 13)
        );
    }
}
