<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Store;
use App\Models\Transaction;
use App\Services\StoreContext;
use App\Widget\Orders\OrdersTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTradeInFilterTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();

        $storeContext = $this->app->make(StoreContext::class);
        $storeContext->setCurrentStore($this->store);
    }

    public function test_filter_has_trade_in_yes_returns_only_trade_in_orders(): void
    {
        $transaction = Transaction::factory()->create(['store_id' => $this->store->id]);

        Order::factory()->create([
            'store_id' => $this->store->id,
            'trade_in_transaction_id' => $transaction->id,
        ]);

        Order::factory()->create([
            'store_id' => $this->store->id,
            'trade_in_transaction_id' => null,
        ]);

        $widget = new OrdersTable;
        $result = $widget->data(['has_trade_in' => 'yes', 'store_id' => $this->store->id]);

        $this->assertEquals(1, $result['total']);
    }

    public function test_filter_has_trade_in_no_returns_only_non_trade_in_orders(): void
    {
        $transaction = Transaction::factory()->create(['store_id' => $this->store->id]);

        Order::factory()->create([
            'store_id' => $this->store->id,
            'trade_in_transaction_id' => $transaction->id,
        ]);

        Order::factory()->create([
            'store_id' => $this->store->id,
            'trade_in_transaction_id' => null,
        ]);

        $widget = new OrdersTable;
        $result = $widget->data(['has_trade_in' => 'no', 'store_id' => $this->store->id]);

        $this->assertEquals(1, $result['total']);
    }

    public function test_filter_has_trade_in_empty_returns_all_orders(): void
    {
        $transaction = Transaction::factory()->create(['store_id' => $this->store->id]);

        Order::factory()->create([
            'store_id' => $this->store->id,
            'trade_in_transaction_id' => $transaction->id,
        ]);

        Order::factory()->create([
            'store_id' => $this->store->id,
            'trade_in_transaction_id' => null,
        ]);

        $widget = new OrdersTable;
        $result = $widget->data(['store_id' => $this->store->id]);

        $this->assertEquals(2, $result['total']);
    }
}
