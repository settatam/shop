<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionExportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id, 'step' => 2]);

        Role::createDefaultRoles($this->store->id);

        $ownerRole = Role::where('store_id', $this->store->id)
            ->where('slug', 'owner')
            ->first();

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

    public function test_can_export_transactions_as_csv(): void
    {
        $customer = Customer::factory()->create(['store_id' => $this->store->id]);
        $transactions = Transaction::factory()->count(3)->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
        ]);

        $response = $this->actingAs($this->user)->postJson('/transactions/export', [
            'ids' => $transactions->pluck('id')->toArray(),
        ]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $response->assertDownload();

        $content = $response->streamedContent();
        $this->assertStringContains('Transaction #', $content);
        $this->assertStringContains($transactions->first()->transaction_number, $content);
    }

    public function test_export_requires_ids(): void
    {
        $response = $this->actingAs($this->user)->postJson('/transactions/export', [
            'ids' => [],
        ]);

        $response->assertUnprocessable();
    }

    public function test_export_only_includes_store_transactions(): void
    {
        $otherStore = Store::factory()->create(['user_id' => $this->user->id]);
        $otherTransaction = Transaction::factory()->create([
            'store_id' => $otherStore->id,
        ]);
        $ownTransaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $response = $this->actingAs($this->user)->postJson('/transactions/export', [
            'ids' => [$otherTransaction->id, $ownTransaction->id],
        ]);

        $response->assertOk();
        $content = $response->streamedContent();

        // Should include own transaction
        $this->assertStringContains($ownTransaction->transaction_number, $content);
        // Should not include other store's transaction
        $this->assertStringNotContains($otherTransaction->transaction_number, $content);
    }

    public function test_export_includes_tags(): void
    {
        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
        ]);
        $tag = Tag::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'VIP',
        ]);
        $transaction->tags()->attach($tag);

        $response = $this->actingAs($this->user)->postJson('/transactions/export', [
            'ids' => [$transaction->id],
        ]);

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContains('VIP', $content);
    }

    protected function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'."
        );
    }

    protected function assertStringNotContains(string $needle, string $haystack): void
    {
        $this->assertFalse(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' does not contain '{$needle}'."
        );
    }
}
