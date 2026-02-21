<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuysReportCategoryFilterTest extends TestCase
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
            'step' => 2, // Mark onboarding as complete
        ]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        $this->user->update(['current_store_id' => $this->store->id]);
        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_buys_report_index_returns_categories(): void
    {
        Category::factory()->count(3)->create(['store_id' => $this->store->id]);

        $response = $this->actingAs($this->user)->get('/reports/buys');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/buys/Index')
            ->has('categories', 3)
        );
    }

    public function test_buys_report_daily_returns_categories(): void
    {
        Category::factory()->count(3)->create(['store_id' => $this->store->id]);

        $response = $this->actingAs($this->user)->get('/reports/buys/daily');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/buys/Daily')
            ->has('categories', 3)
        );
    }

    public function test_buys_report_monthly_returns_categories(): void
    {
        Category::factory()->count(3)->create(['store_id' => $this->store->id]);

        $response = $this->actingAs($this->user)->get('/reports/buys/monthly');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/buys/Monthly')
            ->has('categories', 3)
        );
    }

    public function test_buys_report_yearly_returns_categories(): void
    {
        Category::factory()->count(3)->create(['store_id' => $this->store->id]);

        $response = $this->actingAs($this->user)->get('/reports/buys/yearly');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/buys/Yearly')
            ->has('categories', 3)
        );
    }

    public function test_buys_report_filters_by_category(): void
    {
        $category1 = Category::factory()->create(['store_id' => $this->store->id]);
        $category2 = Category::factory()->create(['store_id' => $this->store->id]);

        // Create transaction with items in category1
        $transaction1 = Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
            'payment_processed_at' => now(),
        ]);
        TransactionItem::factory()->create([
            'transaction_id' => $transaction1->id,
            'category_id' => $category1->id,
        ]);

        // Create transaction with items in category2
        $transaction2 = Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
            'payment_processed_at' => now(),
        ]);
        TransactionItem::factory()->create([
            'transaction_id' => $transaction2->id,
            'category_id' => $category2->id,
        ]);

        // Filter by category1
        $response = $this->actingAs($this->user)
            ->get('/reports/buys?category_id='.$category1->id);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/buys/Index')
            ->where('filters.category_id', (string) $category1->id)
        );
    }

    public function test_buys_report_includes_descendant_categories_in_filter(): void
    {
        // Create parent category
        $parentCategory = Category::factory()->create([
            'store_id' => $this->store->id,
            'parent_id' => null,
            'level' => 0,
        ]);

        // Create child category
        $childCategory = Category::factory()->create([
            'store_id' => $this->store->id,
            'parent_id' => $parentCategory->id,
            'level' => 1,
        ]);

        // Create grandchild category
        $grandchildCategory = Category::factory()->create([
            'store_id' => $this->store->id,
            'parent_id' => $childCategory->id,
            'level' => 2,
        ]);

        // Create transaction in child category
        $transaction = Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
            'payment_processed_at' => now(),
        ]);
        TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'category_id' => $childCategory->id,
        ]);

        // Filter by parent should include transactions in child categories
        $response = $this->actingAs($this->user)
            ->get('/reports/buys?category_id='.$parentCategory->id);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/buys/Index')
            ->has('categoryBreakdown')
        );
    }

    public function test_buys_report_returns_category_breakdown(): void
    {
        $category = Category::factory()->create(['store_id' => $this->store->id]);

        $transaction = Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
            'payment_processed_at' => now(),
        ]);
        TransactionItem::factory()->count(2)->create([
            'transaction_id' => $transaction->id,
            'category_id' => $category->id,
            'buy_price' => 100.00,
            'price' => 150.00,
        ]);

        $response = $this->actingAs($this->user)->get('/reports/buys');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/buys/Index')
            ->has('categoryBreakdown')
        );
    }

    public function test_categories_include_depth_information(): void
    {
        // Create parent category
        $parentCategory = Category::factory()->create([
            'store_id' => $this->store->id,
            'parent_id' => null,
            'level' => 0,
        ]);

        // Create child category
        $childCategory = Category::factory()->withParent($parentCategory)->create();

        $response = $this->actingAs($this->user)->get('/reports/buys');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/buys/Index')
            ->has('categories', 2)
            ->has('categories.0', fn ($category) => $category
                ->has('value')
                ->has('label')
                ->has('depth')
                ->has('isLeaf')
            )
        );
    }

    public function test_buys_daily_report_filters_by_category(): void
    {
        $category = Category::factory()->create(['store_id' => $this->store->id]);

        $transaction = Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
            'payment_processed_at' => now(),
        ]);
        TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'category_id' => $category->id,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/reports/buys/daily?category_id='.$category->id);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/buys/Daily')
            ->where('filters.category_id', (string) $category->id)
            ->has('categoryBreakdown')
        );
    }

    public function test_buys_monthly_report_filters_by_category(): void
    {
        $category = Category::factory()->create(['store_id' => $this->store->id]);

        $transaction = Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
            'payment_processed_at' => now(),
        ]);
        TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'category_id' => $category->id,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/reports/buys/monthly?category_id='.$category->id);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/buys/Monthly')
            ->where('filters.category_id', (string) $category->id)
            ->has('categoryBreakdown')
        );
    }

    public function test_buys_yearly_report_filters_by_category(): void
    {
        $category = Category::factory()->create(['store_id' => $this->store->id]);

        $transaction = Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
            'payment_processed_at' => now(),
        ]);
        TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'category_id' => $category->id,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/reports/buys/yearly?category_id='.$category->id);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/buys/Yearly')
            ->where('filters.category_id', (string) $category->id)
            ->has('categoryBreakdown')
        );
    }

    public function test_category_breakdown_includes_correct_aggregations(): void
    {
        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'parent_id' => null,
        ]);

        $transaction = Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
            'payment_processed_at' => now(),
        ]);

        TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'category_id' => $category->id,
            'buy_price' => 100.00,
            'price' => 150.00,
        ]);

        $response = $this->actingAs($this->user)->get('/reports/buys');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/buys/Index')
            ->has('categoryBreakdown', 1)
            ->has('categoryBreakdown.0', fn ($breakdown) => $breakdown
                ->has('category_id')
                ->has('category_name')
                ->has('parent_id')
                ->has('is_leaf')
                ->has('items_count')
                ->has('transactions_count')
                ->has('total_purchase')
                ->has('total_estimated_value')
                ->has('total_profit')
            )
        );
    }

    public function test_buys_reports_require_authentication(): void
    {
        $response = $this->get('/reports/buys');
        $response->assertRedirect('/login');

        $response = $this->get('/reports/buys/daily');
        $response->assertRedirect('/login');

        $response = $this->get('/reports/buys/monthly');
        $response->assertRedirect('/login');

        $response = $this->get('/reports/buys/yearly');
        $response->assertRedirect('/login');
    }
}
