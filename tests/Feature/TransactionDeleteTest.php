<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

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

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_transaction_can_be_deleted_without_reason(): void
    {
        $transaction = Transaction::factory()->pending()->create(['store_id' => $this->store->id]);

        $response = $this->actingAs($this->user)
            ->delete("/transactions/{$transaction->id}");

        $response->assertRedirect(route('web.transactions.index'));

        $this->assertSoftDeleted('transactions', ['id' => $transaction->id]);
        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'deletion_reason' => null,
        ]);
    }

    public function test_transaction_can_be_deleted_with_reason(): void
    {
        $transaction = Transaction::factory()->pending()->create(['store_id' => $this->store->id]);

        $reason = 'Customer changed their mind about the trade-in.';

        $response = $this->actingAs($this->user)
            ->delete("/transactions/{$transaction->id}", [
                'deletion_reason' => $reason,
            ]);

        $response->assertRedirect(route('web.transactions.index'));

        $this->assertSoftDeleted('transactions', ['id' => $transaction->id]);
        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'deletion_reason' => $reason,
        ]);
    }

    public function test_deletion_reason_cannot_exceed_1000_characters(): void
    {
        $transaction = Transaction::factory()->pending()->create(['store_id' => $this->store->id]);

        $response = $this->actingAs($this->user)
            ->delete("/transactions/{$transaction->id}", [
                'deletion_reason' => str_repeat('a', 1001),
            ]);

        $response->assertSessionHasErrors('deletion_reason');
        $this->assertNotSoftDeleted('transactions', ['id' => $transaction->id]);
    }

    public function test_cannot_delete_another_stores_transaction(): void
    {
        $otherStore = Store::factory()->create();
        $transaction = Transaction::factory()->pending()->create(['store_id' => $otherStore->id]);

        $response = $this->actingAs($this->user)
            ->delete("/transactions/{$transaction->id}");

        $response->assertNotFound();
        $this->assertNotSoftDeleted('transactions', ['id' => $transaction->id]);
    }
}
