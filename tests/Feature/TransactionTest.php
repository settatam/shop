<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\StoreContext;
use App\Services\Transactions\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class TransactionTest extends TestCase
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

    public function test_can_list_transactions(): void
    {
        Passport::actingAs($this->user);

        Transaction::factory()->count(3)->create(['store_id' => $this->store->id]);

        $response = $this->getJson('/api/v1/transactions');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_can_filter_transactions_by_status(): void
    {
        Passport::actingAs($this->user);

        Transaction::factory()->pending()->count(2)->create(['store_id' => $this->store->id]);
        Transaction::factory()->offerAccepted()->count(3)->create(['store_id' => $this->store->id]);

        $response = $this->getJson('/api/v1/transactions?status=pending');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_can_filter_transactions_by_type(): void
    {
        Passport::actingAs($this->user);

        Transaction::factory()->count(2)->create([
            'store_id' => $this->store->id,
            'type' => Transaction::TYPE_IN_STORE,
        ]);
        Transaction::factory()->count(1)->create([
            'store_id' => $this->store->id,
            'type' => Transaction::TYPE_MAIL_IN,
        ]);

        $response = $this->getJson('/api/v1/transactions?type=in_store');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_can_create_transaction_via_api(): void
    {
        Passport::actingAs($this->user);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        $response = $this->postJson('/api/v1/transactions', [
            'customer_id' => $customer->id,
            'type' => Transaction::TYPE_IN_STORE,
            'items' => [
                [
                    'title' => 'Gold Ring',
                    'precious_metal' => TransactionItem::METAL_GOLD_14K,
                    'dwt' => 5.5,
                    'price' => 150.00,
                    'buy_price' => 100.00,
                ],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', Transaction::STATUS_PENDING)
            ->assertJsonPath('data.customer_id', $customer->id);

        $this->assertDatabaseHas('transactions', [
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'status' => Transaction::STATUS_PENDING,
        ]);

        $this->assertDatabaseHas('transaction_items', [
            'title' => 'Gold Ring',
            'precious_metal' => TransactionItem::METAL_GOLD_14K,
        ]);
    }

    public function test_can_show_transaction_details(): void
    {
        Passport::actingAs($this->user);

        $transaction = Transaction::factory()->create(['store_id' => $this->store->id]);
        TransactionItem::factory()->count(2)->create(['transaction_id' => $transaction->id]);

        $response = $this->getJson("/api/v1/transactions/{$transaction->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $transaction->id)
            ->assertJsonCount(2, 'data.items');
    }

    public function test_can_update_transaction(): void
    {
        Passport::actingAs($this->user);

        $transaction = Transaction::factory()->pending()->create(['store_id' => $this->store->id]);

        $response = $this->putJson("/api/v1/transactions/{$transaction->id}", [
            'bin_location' => 'BIN-A1',
            'internal_notes' => 'High value items',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.bin_location', 'BIN-A1')
            ->assertJsonPath('data.internal_notes', 'High value items');
    }

    public function test_can_add_item_to_transaction(): void
    {
        Passport::actingAs($this->user);

        $transaction = Transaction::factory()->pending()->create(['store_id' => $this->store->id]);

        $response = $this->postJson("/api/v1/transactions/{$transaction->id}/items", [
            'title' => 'Silver Bracelet',
            'precious_metal' => TransactionItem::METAL_SILVER,
            'dwt' => 3.2,
            'price' => 50.00,
            'buy_price' => 30.00,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Item added successfully.');

        $this->assertDatabaseHas('transaction_items', [
            'transaction_id' => $transaction->id,
            'title' => 'Silver Bracelet',
            'precious_metal' => TransactionItem::METAL_SILVER,
        ]);
    }

    public function test_can_update_transaction_item(): void
    {
        Passport::actingAs($this->user);

        $transaction = Transaction::factory()->pending()->create(['store_id' => $this->store->id]);
        $item = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'title' => 'Gold Necklace',
            'buy_price' => 100.00,
        ]);

        $response = $this->putJson("/api/v1/transactions/{$transaction->id}/items/{$item->id}", [
            'buy_price' => 120.00,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Item updated successfully.');

        $this->assertDatabaseHas('transaction_items', [
            'id' => $item->id,
            'buy_price' => '120.00',
        ]);
    }

    public function test_can_remove_transaction_item(): void
    {
        Passport::actingAs($this->user);

        $transaction = Transaction::factory()->pending()->create(['store_id' => $this->store->id]);
        $item = TransactionItem::factory()->create(['transaction_id' => $transaction->id]);

        $response = $this->deleteJson("/api/v1/transactions/{$transaction->id}/items/{$item->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Item removed successfully.');

        $this->assertDatabaseMissing('transaction_items', ['id' => $item->id]);
    }

    public function test_can_submit_offer(): void
    {
        Passport::actingAs($this->user);

        $transaction = Transaction::factory()->pending()->create(['store_id' => $this->store->id]);
        TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'buy_price' => 100.00,
        ]);

        $response = $this->postJson("/api/v1/transactions/{$transaction->id}/offer", [
            'offer' => 150.00,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', Transaction::STATUS_OFFER_GIVEN)
            ->assertJsonPath('data.final_offer', '150.00');
    }

    public function test_can_accept_offer(): void
    {
        Passport::actingAs($this->user);

        $transaction = Transaction::factory()->offerGiven()->create([
            'store_id' => $this->store->id,
            'final_offer' => 200.00,
        ]);

        $response = $this->postJson("/api/v1/transactions/{$transaction->id}/accept");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', Transaction::STATUS_OFFER_ACCEPTED);
    }

    public function test_can_decline_offer(): void
    {
        Passport::actingAs($this->user);

        $transaction = Transaction::factory()->offerGiven()->create([
            'store_id' => $this->store->id,
            'final_offer' => 200.00,
        ]);

        $response = $this->postJson("/api/v1/transactions/{$transaction->id}/decline", [
            'reason' => 'Customer wanted more',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', Transaction::STATUS_OFFER_DECLINED);
    }

    public function test_can_process_payment(): void
    {
        Passport::actingAs($this->user);

        $transaction = Transaction::factory()->offerAccepted()->create([
            'store_id' => $this->store->id,
            'final_offer' => 250.00,
        ]);

        $response = $this->postJson("/api/v1/transactions/{$transaction->id}/process", [
            'payment_method' => Transaction::PAYMENT_CASH,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', Transaction::STATUS_PAYMENT_PROCESSED)
            ->assertJsonPath('data.payment_method', Transaction::PAYMENT_CASH);
    }

    public function test_can_move_item_to_inventory(): void
    {
        Passport::actingAs($this->user);

        $category = Category::factory()->create(['store_id' => $this->store->id]);

        $transaction = Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
        ]);
        $item = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'title' => 'Gold Watch',
            'buy_price' => 500.00,
            'category_id' => $category->id,
        ]);

        $response = $this->postJson("/api/v1/transactions/{$transaction->id}/items/{$item->id}/inventory", [
            'title' => 'Vintage Gold Watch',
            'price' => 750.00,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Item moved to inventory successfully.');

        $this->assertDatabaseHas('products', [
            'store_id' => $this->store->id,
            'title' => 'Vintage Gold Watch',
        ]);

        $this->assertDatabaseHas('transaction_items', [
            'id' => $item->id,
            'is_added_to_inventory' => true,
        ]);
    }

    public function test_can_delete_pending_transaction(): void
    {
        Passport::actingAs($this->user);

        $transaction = Transaction::factory()->pending()->create(['store_id' => $this->store->id]);

        $response = $this->deleteJson("/api/v1/transactions/{$transaction->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('transactions', ['id' => $transaction->id]);
    }

    public function test_transaction_service_calculates_preliminary_offer(): void
    {
        $transaction = Transaction::factory()->create(['store_id' => $this->store->id]);

        TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'buy_price' => 100.00,
        ]);
        TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'buy_price' => 150.00,
        ]);

        $service = app(TransactionService::class);
        $offer = $service->calculatePreliminaryOffer($transaction);

        $this->assertEquals(250.00, $offer);
    }

    public function test_transaction_generates_unique_number(): void
    {
        $transaction1 = Transaction::factory()->create(['store_id' => $this->store->id]);
        $transaction2 = Transaction::factory()->create(['store_id' => $this->store->id]);

        $this->assertNotEquals($transaction1->transaction_number, $transaction2->transaction_number);
        $this->assertStringStartsWith('TXN-', $transaction1->transaction_number);
    }

    public function test_only_store_transactions_are_visible(): void
    {
        Passport::actingAs($this->user);

        $otherStore = Store::factory()->create();
        Transaction::factory()->count(2)->create(['store_id' => $this->store->id]);
        Transaction::factory()->count(3)->create(['store_id' => $otherStore->id]);

        $response = $this->getJson('/api/v1/transactions');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }
}
