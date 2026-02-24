<?php

namespace Tests\Feature\Widget;

use App\Models\Customer;
use App\Models\Role;
use App\Models\Status;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\StoreContext;
use App\Widget\Buys\BuyItemsTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuyItemsTableTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected Status $paymentProcessedStatus;

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

        // Create transaction statuses
        $this->paymentProcessedStatus = Status::factory()->create([
            'store_id' => $this->store->id,
            'entity_type' => 'transaction',
            'name' => 'Payment Processed',
            'slug' => 'payment_processed',
            'color' => '#22c55e',
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_buy_items_table_returns_items_from_payment_processed_transactions(): void
    {
        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        // Create a payment processed transaction with items
        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'status_id' => $this->paymentProcessedStatus->id,
            'type' => Transaction::TYPE_IN_STORE,
            'payment_method' => Transaction::PAYMENT_CASH,
        ]);

        TransactionItem::factory()->count(3)->create([
            'transaction_id' => $transaction->id,
        ]);

        $widget = new BuyItemsTable;
        $result = $widget->render(['store_id' => $this->store->id]);

        $this->assertCount(3, $result['data']['items']);
    }

    public function test_buy_items_table_excludes_items_from_non_payment_processed_transactions(): void
    {
        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        $pendingStatus = Status::factory()->create([
            'store_id' => $this->store->id,
            'entity_type' => 'transaction',
            'name' => 'Pending',
            'slug' => 'pending',
        ]);

        // Create a pending transaction (should not appear)
        $pendingTransaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'status_id' => $pendingStatus->id,
        ]);
        TransactionItem::factory()->count(2)->create([
            'transaction_id' => $pendingTransaction->id,
        ]);

        // Create a payment processed transaction (should appear)
        $processedTransaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'status_id' => $this->paymentProcessedStatus->id,
        ]);
        TransactionItem::factory()->count(1)->create([
            'transaction_id' => $processedTransaction->id,
        ]);

        $widget = new BuyItemsTable;
        $result = $widget->render(['store_id' => $this->store->id]);

        $this->assertCount(1, $result['data']['items']);
    }

    public function test_buy_items_table_filters_by_store(): void
    {
        $otherStore = Store::factory()->create();
        $otherStatus = Status::factory()->create([
            'store_id' => $otherStore->id,
            'entity_type' => 'transaction',
            'name' => 'Payment Processed',
            'slug' => 'payment_processed',
        ]);

        // Create items in current store
        $transaction1 = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'status_id' => $this->paymentProcessedStatus->id,
        ]);
        TransactionItem::factory()->count(2)->create([
            'transaction_id' => $transaction1->id,
        ]);

        // Create items in other store (should not appear)
        $transaction2 = Transaction::factory()->create([
            'store_id' => $otherStore->id,
            'status_id' => $otherStatus->id,
        ]);
        TransactionItem::factory()->count(5)->create([
            'transaction_id' => $transaction2->id,
        ]);

        $widget = new BuyItemsTable;
        $result = $widget->render(['store_id' => $this->store->id]);

        $this->assertCount(2, $result['data']['items']);
    }

    public function test_buy_items_table_returns_correct_fields(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'status_id' => $this->paymentProcessedStatus->id,
            'type' => Transaction::TYPE_IN_STORE,
            'payment_method' => Transaction::PAYMENT_CASH,
        ]);

        TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'title' => 'Test Item',
            'price' => 1000.00,
            'buy_price' => 500.00,
        ]);

        $widget = new BuyItemsTable;
        $result = $widget->render(['store_id' => $this->store->id]);

        $item = $result['data']['items'][0];
        $this->assertArrayHasKey('purchase_date', $item);
        $this->assertArrayHasKey('image', $item);
        $this->assertArrayHasKey('transaction_number', $item);
        $this->assertArrayHasKey('title', $item);
        $this->assertArrayHasKey('est_value', $item);
        $this->assertArrayHasKey('amount_paid', $item);
        $this->assertArrayHasKey('profit', $item);
        $this->assertArrayHasKey('customer', $item);
        $this->assertArrayHasKey('payment_type', $item);
        $this->assertArrayHasKey('type', $item);
        $this->assertArrayHasKey('status', $item);

        // Verify profit calculation
        $this->assertEquals(1000.00, $item['est_value']['data']);
        $this->assertEquals(500.00, $item['amount_paid']['data']);
        $this->assertEquals(500.00, $item['profit']['data']);
    }

    public function test_buy_items_table_applies_search_filter(): void
    {
        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'status_id' => $this->paymentProcessedStatus->id,
        ]);

        TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'title' => 'Gold Rolex Watch',
        ]);

        TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'title' => 'Silver Bracelet',
        ]);

        $widget = new BuyItemsTable;
        $result = $widget->render(['store_id' => $this->store->id, 'term' => 'Rolex']);

        $this->assertCount(1, $result['data']['items']);
        $this->assertEquals('Gold Rolex Watch', $result['data']['items'][0]['title']['data']);
    }

    public function test_buy_items_table_applies_payment_method_filter(): void
    {
        $cashTransaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'status_id' => $this->paymentProcessedStatus->id,
            'payment_method' => Transaction::PAYMENT_CASH,
        ]);
        TransactionItem::factory()->count(2)->create([
            'transaction_id' => $cashTransaction->id,
        ]);

        $checkTransaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'status_id' => $this->paymentProcessedStatus->id,
            'payment_method' => Transaction::PAYMENT_CHECK,
        ]);
        TransactionItem::factory()->count(3)->create([
            'transaction_id' => $checkTransaction->id,
        ]);

        $widget = new BuyItemsTable;
        $result = $widget->render(['store_id' => $this->store->id, 'payment_method' => 'cash']);

        $this->assertCount(2, $result['data']['items']);
    }

    public function test_buy_items_table_supports_pagination(): void
    {
        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'status_id' => $this->paymentProcessedStatus->id,
        ]);

        TransactionItem::factory()->count(25)->create([
            'transaction_id' => $transaction->id,
        ]);

        $widget = new BuyItemsTable;
        $result = $widget->render(['store_id' => $this->store->id, 'per_page' => 10, 'page' => 1]);

        $this->assertCount(10, $result['data']['items']);
        $this->assertEquals(25, $result['pagination']['total']);
        $this->assertEquals(1, $result['pagination']['current_page']);
    }

    public function test_buy_items_table_instantiation(): void
    {
        $table = new BuyItemsTable;

        $this->assertIsArray($table->fields());
        $this->assertNotEmpty($table->fields());
        $this->assertEquals('Buy Items', $table->title([]));
    }

    public function test_buy_items_table_returns_available_filters(): void
    {
        $widget = new BuyItemsTable;
        $result = $widget->render(['store_id' => $this->store->id]);

        $filters = $result['filters']['available'];

        $this->assertArrayHasKey('payment_methods', $filters);
        $this->assertArrayHasKey('categories', $filters);
        $this->assertArrayHasKey('statuses', $filters);
        $this->assertArrayHasKey('types', $filters);
    }

    public function test_buy_items_table_includes_item_link_to_item_page(): void
    {
        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'status_id' => $this->paymentProcessedStatus->id,
        ]);

        $item = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'title' => 'Test Item',
        ]);

        $widget = new BuyItemsTable;
        $result = $widget->render(['store_id' => $this->store->id]);

        // Verify title links to the item page
        $this->assertEquals('link', $result['data']['items'][0]['title']['type']);
        $this->assertEquals("/transactions/{$transaction->id}/items/{$item->id}", $result['data']['items'][0]['title']['href']);
    }

    public function test_buy_items_table_filters_uncategorized_items(): void
    {
        $category = \App\Models\Category::factory()->create(['store_id' => $this->store->id]);

        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'status_id' => $this->paymentProcessedStatus->id,
        ]);

        // Create categorized items
        TransactionItem::factory()->count(2)->create([
            'transaction_id' => $transaction->id,
            'category_id' => $category->id,
        ]);

        // Create uncategorized items
        TransactionItem::factory()->count(3)->create([
            'transaction_id' => $transaction->id,
            'category_id' => null,
        ]);

        $widget = new BuyItemsTable;
        $result = $widget->render(['store_id' => $this->store->id, 'parent_category_id' => '0']);

        $this->assertCount(3, $result['data']['items']);
    }

    public function test_buy_items_table_applies_transaction_type_filter(): void
    {
        $inStoreTransaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'status_id' => $this->paymentProcessedStatus->id,
            'type' => Transaction::TYPE_IN_STORE,
        ]);
        TransactionItem::factory()->count(2)->create([
            'transaction_id' => $inStoreTransaction->id,
        ]);

        $mailInTransaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'status_id' => $this->paymentProcessedStatus->id,
            'type' => Transaction::TYPE_MAIL_IN,
        ]);
        TransactionItem::factory()->count(3)->create([
            'transaction_id' => $mailInTransaction->id,
        ]);

        $widget = new BuyItemsTable;
        $result = $widget->render(['store_id' => $this->store->id, 'transaction_type' => Transaction::TYPE_IN_STORE]);

        $this->assertCount(2, $result['data']['items']);
    }
}
