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

class BuysTest extends TestCase
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

    public function test_can_view_buys_index_page(): void
    {
        $response = $this->actingAs($this->user)->get('/buys');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('buys/Index'));
    }

    public function test_can_view_buys_items_page(): void
    {
        $response = $this->actingAs($this->user)->get('/buys/items');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('buys/Items'));
    }

    public function test_buys_widget_returns_only_payment_processed_transactions(): void
    {
        // Create transactions with different statuses
        Transaction::factory()->pending()->create(['store_id' => $this->store->id]);
        Transaction::factory()->offerAccepted()->create(['store_id' => $this->store->id]);
        Transaction::factory()->paymentProcessed()->count(2)->create(['store_id' => $this->store->id]);

        $response = $this->actingAs($this->user)
            ->get('/widgets/view?type=Buys%5CBuysTable');

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertEquals(2, $data['pagination']['total']);
    }

    public function test_buys_widget_filters_by_payment_method(): void
    {
        Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
            'payment_method' => Transaction::PAYMENT_CASH,
        ]);
        Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
            'payment_method' => Transaction::PAYMENT_CHECK,
        ]);
        Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
            'payment_method' => Transaction::PAYMENT_CASH,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/widgets/view?type=Buys%5CBuysTable&payment_method=cash');

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertEquals(2, $data['pagination']['total']);
    }

    public function test_buys_widget_filters_by_amount_range(): void
    {
        Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
            'final_offer' => 100.00,
        ]);
        Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
            'final_offer' => 250.00,
        ]);
        Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
            'final_offer' => 500.00,
        ]);

        // Filter by min amount
        $response = $this->actingAs($this->user)
            ->get('/widgets/view?type=Buys%5CBuysTable&min_amount=200');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEquals(2, $data['pagination']['total']);

        // Filter by max amount
        $response = $this->actingAs($this->user)
            ->get('/widgets/view?type=Buys%5CBuysTable&max_amount=300');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEquals(2, $data['pagination']['total']);
    }

    public function test_buys_widget_filters_by_date_range(): void
    {
        Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
            'payment_processed_at' => now()->subDays(10),
        ]);
        Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
            'payment_processed_at' => now()->subDays(5),
        ]);
        Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
            'payment_processed_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->get('/widgets/view?type=Buys%5CBuysTable&from_date='.now()->subDays(6)->format('Y-m-d'));

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEquals(2, $data['pagination']['total']);
    }

    public function test_buy_items_widget_returns_items_from_payment_processed_transactions(): void
    {
        // Create a pending transaction with items (should not appear)
        $pendingTransaction = Transaction::factory()->pending()->create(['store_id' => $this->store->id]);
        TransactionItem::factory()->count(2)->create(['transaction_id' => $pendingTransaction->id]);

        // Create a payment processed transaction with items (should appear)
        $paidTransaction = Transaction::factory()->paymentProcessed()->create(['store_id' => $this->store->id]);
        TransactionItem::factory()->count(3)->create(['transaction_id' => $paidTransaction->id]);

        $response = $this->actingAs($this->user)
            ->get('/widgets/view?type=Buys%5CBuyItemsTable');

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertEquals(3, $data['pagination']['total']);
    }

    public function test_buy_items_widget_filters_by_category(): void
    {
        $category1 = Category::factory()->create(['store_id' => $this->store->id]);
        $category2 = Category::factory()->create(['store_id' => $this->store->id]);

        $transaction = Transaction::factory()->paymentProcessed()->create(['store_id' => $this->store->id]);
        TransactionItem::factory()->count(2)->create([
            'transaction_id' => $transaction->id,
            'category_id' => $category1->id,
        ]);
        TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'category_id' => $category2->id,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/widgets/view?type=Buys%5CBuyItemsTable&category_id='.$category1->id);

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertEquals(2, $data['pagination']['total']);
    }

    public function test_buy_items_widget_filters_by_buy_price_range(): void
    {
        $transaction = Transaction::factory()->paymentProcessed()->create(['store_id' => $this->store->id]);

        TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'buy_price' => 50.00,
        ]);
        TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'buy_price' => 150.00,
        ]);
        TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'buy_price' => 300.00,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/widgets/view?type=Buys%5CBuyItemsTable&min_amount=100&max_amount=200');

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertEquals(1, $data['pagination']['total']);
    }

    public function test_buys_only_show_transactions_from_current_store(): void
    {
        // Create another store
        $otherStore = Store::factory()->create(['user_id' => $this->user->id]);

        // Create transactions in both stores
        Transaction::factory()->paymentProcessed()->create(['store_id' => $this->store->id]);
        Transaction::factory()->paymentProcessed()->count(2)->create(['store_id' => $otherStore->id]);

        $response = $this->actingAs($this->user)
            ->get('/widgets/view?type=Buys%5CBuysTable');

        $response->assertStatus(200);
        $data = $response->json();

        // Should only show the transaction from current store
        $this->assertEquals(1, $data['pagination']['total']);
    }

    public function test_buys_requires_authentication(): void
    {
        $response = $this->get('/buys');
        $response->assertRedirect('/login');

        $response = $this->get('/buys/items');
        $response->assertRedirect('/login');
    }
}
