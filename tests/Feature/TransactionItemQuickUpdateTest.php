<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionItemQuickUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected Transaction $transaction;

    protected TransactionItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id, 'step' => 2]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        $this->user->update(['current_store_id' => $this->store->id]);
        app(StoreContext::class)->setCurrentStore($this->store);

        $this->transaction = Transaction::factory()->create(['store_id' => $this->store->id]);
        $this->item = TransactionItem::factory()->create([
            'transaction_id' => $this->transaction->id,
            'title' => 'Gold Ring 14K',
            'price' => 100.00,
            'buy_price' => 80.00,
        ]);
    }

    public function test_can_quick_update_price(): void
    {
        $response = $this->actingAs($this->user)
            ->patchJson("/transactions/{$this->transaction->id}/items/{$this->item->id}/quick-update", [
                'price' => 150.00,
            ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'item' => [
                'id' => $this->item->id,
                'price' => 150.00,
            ],
        ]);

        $this->item->refresh();
        $this->assertEquals(150.00, $this->item->price);
        $this->assertEquals(80.00, $this->item->buy_price); // Should remain unchanged
    }

    public function test_can_quick_update_buy_price(): void
    {
        $response = $this->actingAs($this->user)
            ->patchJson("/transactions/{$this->transaction->id}/items/{$this->item->id}/quick-update", [
                'buy_price' => 90.00,
            ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'item' => [
                'id' => $this->item->id,
                'buy_price' => 90.00,
            ],
        ]);

        $this->item->refresh();
        $this->assertEquals(100.00, $this->item->price); // Should remain unchanged
        $this->assertEquals(90.00, $this->item->buy_price);
    }

    public function test_can_quick_update_both_prices(): void
    {
        $response = $this->actingAs($this->user)
            ->patchJson("/transactions/{$this->transaction->id}/items/{$this->item->id}/quick-update", [
                'price' => 200.00,
                'buy_price' => 160.00,
            ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'item' => [
                'id' => $this->item->id,
                'price' => 200.00,
                'buy_price' => 160.00,
            ],
        ]);

        $this->item->refresh();
        $this->assertEquals(200.00, $this->item->price);
        $this->assertEquals(160.00, $this->item->buy_price);
    }

    public function test_quick_update_validates_numeric_values(): void
    {
        $response = $this->actingAs($this->user)
            ->patchJson("/transactions/{$this->transaction->id}/items/{$this->item->id}/quick-update", [
                'price' => 'invalid',
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['price']);
    }

    public function test_quick_update_validates_non_negative_values(): void
    {
        $response = $this->actingAs($this->user)
            ->patchJson("/transactions/{$this->transaction->id}/items/{$this->item->id}/quick-update", [
                'buy_price' => -50.00,
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['buy_price']);
    }

    public function test_quick_update_updates_transaction_totals(): void
    {
        // Create another item to verify totals are recalculated
        TransactionItem::factory()->create([
            'transaction_id' => $this->transaction->id,
            'buy_price' => 50.00,
        ]);

        $response = $this->actingAs($this->user)
            ->patchJson("/transactions/{$this->transaction->id}/items/{$this->item->id}/quick-update", [
                'buy_price' => 120.00,
            ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertEquals(170, $response->json('transaction.total_buy_price')); // 120 + 50
    }

    public function test_cannot_quick_update_buy_price_when_payment_processed(): void
    {
        $transaction = Transaction::factory()->paymentProcessed()->create(['store_id' => $this->store->id]);
        $item = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'price' => 100.00,
            'buy_price' => 80.00,
        ]);

        $response = $this->actingAs($this->user)
            ->patchJson("/transactions/{$transaction->id}/items/{$item->id}/quick-update", [
                'buy_price' => 120.00,
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['buy_price']);

        $item->refresh();
        $this->assertEquals(80.00, $item->buy_price);
    }

    public function test_can_quick_update_price_when_payment_processed(): void
    {
        $transaction = Transaction::factory()->paymentProcessed()->create(['store_id' => $this->store->id]);
        $item = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'price' => 100.00,
            'buy_price' => 80.00,
        ]);

        $response = $this->actingAs($this->user)
            ->patchJson("/transactions/{$transaction->id}/items/{$item->id}/quick-update", [
                'price' => 200.00,
            ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'item' => [
                'price' => 200.00,
            ],
        ]);

        $item->refresh();
        $this->assertEquals(200.00, $item->price);
        $this->assertEquals(80.00, $item->buy_price); // Should remain unchanged
    }

    public function test_cannot_full_update_buy_price_when_payment_processed(): void
    {
        $transaction = Transaction::factory()->paymentProcessed()->create(['store_id' => $this->store->id]);
        $item = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'title' => 'Gold Ring',
            'price' => 100.00,
            'buy_price' => 80.00,
        ]);

        $response = $this->actingAs($this->user)
            ->put("/transactions/{$transaction->id}/items/{$item->id}", [
                'title' => 'Gold Ring',
                'buy_price' => 120.00,
            ]);

        $response->assertSessionHasErrors(['buy_price']);

        $item->refresh();
        $this->assertEquals(80.00, $item->buy_price);
    }

    public function test_can_full_update_other_fields_when_payment_processed(): void
    {
        $transaction = Transaction::factory()->paymentProcessed()->create(['store_id' => $this->store->id]);
        $item = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'title' => 'Gold Ring',
            'price' => 100.00,
            'buy_price' => 80.00,
        ]);

        $response = $this->actingAs($this->user)
            ->put("/transactions/{$transaction->id}/items/{$item->id}", [
                'title' => 'Updated Gold Ring',
                'price' => 200.00,
            ]);

        $response->assertRedirect();

        $item->refresh();
        $this->assertEquals('Updated Gold Ring', $item->title);
        $this->assertEquals(200.00, $item->price);
        $this->assertEquals(80.00, $item->buy_price); // Should remain unchanged
    }
}
