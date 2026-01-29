<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Status;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionBulkActionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id, 'step' => 2]);

        // Create default roles for the store
        Role::createDefaultRoles($this->store->id);

        // Get the owner role
        $ownerRole = Role::where('store_id', $this->store->id)
            ->where('slug', 'owner')
            ->first();

        // Create store user with owner role
        StoreUser::create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $ownerRole->id,
            'is_owner' => true,
            'status' => 'active',
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $this->user->email,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_can_bulk_delete_transactions(): void
    {
        $transactions = Transaction::factory()->count(3)->create([
            'store_id' => $this->store->id,
        ]);

        $response = $this->actingAs($this->user)->post('/transactions/bulk-action', [
            'action' => 'delete',
            'ids' => $transactions->pluck('id')->toArray(),
        ]);

        $response->assertRedirect(route('web.transactions.index'));
        $response->assertSessionHas('success');

        foreach ($transactions as $transaction) {
            $this->assertSoftDeleted('transactions', ['id' => $transaction->id]);
        }
    }

    public function test_can_bulk_change_status(): void
    {
        $transactions = Transaction::factory()->count(3)->create([
            'store_id' => $this->store->id,
        ]);

        $newStatus = Status::factory()->create([
            'store_id' => $this->store->id,
            'entity_type' => 'transaction',
            'name' => 'Items Received',
            'slug' => 'items_received',
        ]);

        $response = $this->actingAs($this->user)->post('/transactions/bulk-action', [
            'action' => 'change_status',
            'ids' => $transactions->pluck('id')->toArray(),
            'config' => [
                'target_status_id' => $newStatus->id,
                'target_status_name' => 'Items Received',
            ],
        ]);

        $response->assertRedirect(route('web.transactions.index'));
        $response->assertSessionHas('success');

        foreach ($transactions as $transaction) {
            $this->assertEquals($newStatus->id, $transaction->fresh()->status_id);
        }
    }

    public function test_can_bulk_add_tag(): void
    {
        $transactions = Transaction::factory()->count(3)->create([
            'store_id' => $this->store->id,
        ]);

        $tag = Tag::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'High Value',
        ]);

        $response = $this->actingAs($this->user)->post('/transactions/bulk-action', [
            'action' => 'add_tag',
            'ids' => $transactions->pluck('id')->toArray(),
            'config' => [
                'tag_id' => $tag->id,
            ],
        ]);

        $response->assertRedirect(route('web.transactions.index'));
        $response->assertSessionHas('success');

        foreach ($transactions as $transaction) {
            $this->assertTrue($transaction->fresh()->tags->contains($tag));
        }
    }

    public function test_can_bulk_remove_tag(): void
    {
        $tag = Tag::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'High Value',
        ]);

        $transactions = Transaction::factory()->count(3)->create([
            'store_id' => $this->store->id,
        ]);

        foreach ($transactions as $transaction) {
            $transaction->tags()->attach($tag);
        }

        $response = $this->actingAs($this->user)->post('/transactions/bulk-action', [
            'action' => 'remove_tag',
            'ids' => $transactions->pluck('id')->toArray(),
            'config' => [
                'tag_id' => $tag->id,
            ],
        ]);

        $response->assertRedirect(route('web.transactions.index'));
        $response->assertSessionHas('success');

        foreach ($transactions as $transaction) {
            $this->assertFalse($transaction->fresh()->tags->contains($tag));
        }
    }

    public function test_bulk_action_requires_valid_ids(): void
    {
        $response = $this->actingAs($this->user)->post('/transactions/bulk-action', [
            'action' => 'delete',
            'ids' => [],
        ]);

        $response->assertSessionHasErrors('ids');
    }

    public function test_bulk_action_only_affects_store_transactions(): void
    {
        $otherStore = Store::factory()->create();

        $storeTransaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $otherStoreTransaction = Transaction::factory()->create([
            'store_id' => $otherStore->id,
        ]);

        $response = $this->actingAs($this->user)->post('/transactions/bulk-action', [
            'action' => 'delete',
            'ids' => [$storeTransaction->id, $otherStoreTransaction->id],
        ]);

        // Only the store's transaction should be deleted
        $this->assertSoftDeleted('transactions', ['id' => $storeTransaction->id]);
        $this->assertDatabaseHas('transactions', ['id' => $otherStoreTransaction->id, 'deleted_at' => null]);
    }
}
