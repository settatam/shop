<?php

namespace Tests\Feature;

use App\Models\Bucket;
use App\Models\BucketItem;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BucketService;
use App\Services\Orders\OrderCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BucketTest extends TestCase
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

        $this->user->update(['current_store_id' => $this->store->id]);
    }

    protected function withStore()
    {
        return $this->withSession(['current_store_id' => $this->store->id]);
    }

    public function test_can_view_buckets_index(): void
    {
        $this->actingAs($this->user);

        Bucket::factory()->count(3)->create(['store_id' => $this->store->id]);

        $response = $this->withStore()->get('/buckets');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('buckets/Index')
                ->has('buckets', 3)
            );
    }

    public function test_can_create_bucket(): void
    {
        $this->actingAs($this->user);

        $response = $this->withStore()->post('/buckets', [
            'name' => 'Junk Watches',
            'description' => 'Non-working watches for parts',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('buckets', [
            'store_id' => $this->store->id,
            'name' => 'Junk Watches',
            'description' => 'Non-working watches for parts',
            'total_value' => 0,
        ]);
    }

    public function test_bucket_requires_name(): void
    {
        $this->actingAs($this->user);

        $response = $this->withStore()->post('/buckets', [
            'description' => 'A bucket without a name',
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_can_view_bucket_show_page(): void
    {
        $this->actingAs($this->user);

        $bucket = Bucket::factory()->create(['store_id' => $this->store->id]);
        BucketItem::factory()->count(2)->create(['bucket_id' => $bucket->id]);

        $response = $this->withStore()->get("/buckets/{$bucket->id}");

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('buckets/Show')
                ->has('bucket.items', 2)
            );
    }

    public function test_can_update_bucket(): void
    {
        $this->actingAs($this->user);

        $bucket = Bucket::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Old Name',
        ]);

        $response = $this->withStore()->put("/buckets/{$bucket->id}", [
            'name' => 'Updated Name',
            'description' => 'Updated description',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('buckets', [
            'id' => $bucket->id,
            'name' => 'Updated Name',
            'description' => 'Updated description',
        ]);
    }

    public function test_can_delete_empty_bucket(): void
    {
        $this->actingAs($this->user);

        $bucket = Bucket::factory()->create(['store_id' => $this->store->id]);

        $response = $this->withStore()->delete("/buckets/{$bucket->id}");

        $response->assertRedirect('/buckets');

        $this->assertDatabaseMissing('buckets', ['id' => $bucket->id]);
    }

    public function test_cannot_delete_bucket_with_active_items(): void
    {
        $this->actingAs($this->user);

        $bucket = Bucket::factory()->create(['store_id' => $this->store->id]);
        BucketItem::factory()->create(['bucket_id' => $bucket->id, 'sold_at' => null]);

        $response = $this->withStore()->delete("/buckets/{$bucket->id}");

        $response->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('buckets', ['id' => $bucket->id]);
    }

    public function test_can_add_item_to_bucket(): void
    {
        $this->actingAs($this->user);

        $bucket = Bucket::factory()->create([
            'store_id' => $this->store->id,
            'total_value' => 0,
        ]);

        $response = $this->withStore()->post("/buckets/{$bucket->id}/items", [
            'title' => 'Broken Watch',
            'description' => 'Missing crystal',
            'value' => 50.00,
        ]);

        $response->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('bucket_items', [
            'bucket_id' => $bucket->id,
            'title' => 'Broken Watch',
            'value' => 50.00,
        ]);

        $bucket->refresh();
        $this->assertEquals(50.00, $bucket->total_value);
    }

    public function test_bucket_item_requires_title_and_value(): void
    {
        $this->actingAs($this->user);

        $bucket = Bucket::factory()->create(['store_id' => $this->store->id]);

        $response = $this->withStore()->post("/buckets/{$bucket->id}/items", [
            'description' => 'Only a description',
        ]);

        $response->assertSessionHasErrors(['title', 'value']);
    }

    public function test_can_remove_unsold_item_from_bucket(): void
    {
        $this->actingAs($this->user);

        $bucket = Bucket::factory()->create([
            'store_id' => $this->store->id,
            'total_value' => 75.00,
        ]);

        $item = BucketItem::factory()->create([
            'bucket_id' => $bucket->id,
            'value' => 75.00,
            'sold_at' => null,
        ]);

        $response = $this->withStore()->delete("/bucket-items/{$item->id}");

        $response->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('bucket_items', ['id' => $item->id]);

        $bucket->refresh();
        $this->assertEquals(0, $bucket->total_value);
    }

    public function test_cannot_remove_sold_item(): void
    {
        $this->actingAs($this->user);

        $bucket = Bucket::factory()->create(['store_id' => $this->store->id]);
        $item = BucketItem::factory()->sold()->create(['bucket_id' => $bucket->id]);

        $response = $this->withStore()->delete("/bucket-items/{$item->id}");

        $response->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('bucket_items', ['id' => $item->id]);
    }

    public function test_bucket_total_recalculates_correctly(): void
    {
        $bucket = Bucket::factory()->create([
            'store_id' => $this->store->id,
            'total_value' => 0,
        ]);

        BucketItem::factory()->create(['bucket_id' => $bucket->id, 'value' => 50.00, 'sold_at' => null]);
        BucketItem::factory()->create(['bucket_id' => $bucket->id, 'value' => 75.00, 'sold_at' => null]);
        BucketItem::factory()->sold()->create(['bucket_id' => $bucket->id, 'value' => 100.00]);

        $bucket->recalculateTotal();

        $this->assertEquals(125.00, $bucket->total_value);
    }

    public function test_bucket_service_add_item(): void
    {
        $service = app(BucketService::class);

        $bucket = Bucket::factory()->create([
            'store_id' => $this->store->id,
            'total_value' => 0,
        ]);

        $bucketItem = $service->addItem($bucket, [
            'title' => 'Test Item',
            'description' => 'Test description',
            'value' => 100.00,
        ]);

        $this->assertDatabaseHas('bucket_items', [
            'id' => $bucketItem->id,
            'bucket_id' => $bucket->id,
            'title' => 'Test Item',
        ]);

        $bucket->refresh();
        $this->assertEquals(100.00, $bucket->total_value);
    }

    public function test_bucket_service_remove_item(): void
    {
        $service = app(BucketService::class);

        $bucket = Bucket::factory()->create([
            'store_id' => $this->store->id,
            'total_value' => 100.00,
        ]);

        $item = BucketItem::factory()->create([
            'bucket_id' => $bucket->id,
            'value' => 100.00,
            'sold_at' => null,
        ]);

        $service->removeItem($item);

        $this->assertDatabaseMissing('bucket_items', ['id' => $item->id]);
        $bucket->refresh();
        $this->assertEquals(0, $bucket->total_value);
    }

    public function test_bucket_service_cannot_remove_sold_item(): void
    {
        $service = app(BucketService::class);

        $bucket = Bucket::factory()->create(['store_id' => $this->store->id]);
        $item = BucketItem::factory()->sold()->create(['bucket_id' => $bucket->id]);

        $this->expectException(\InvalidArgumentException::class);

        $service->removeItem($item);
    }

    public function test_transaction_item_can_be_moved_to_bucket(): void
    {
        $this->actingAs($this->user);

        $transaction = Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
        ]);

        $transactionItem = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'title' => 'Scrap Gold Ring',
            'buy_price' => 150.00,
            'is_added_to_inventory' => false,
            'is_added_to_bucket' => false,
        ]);

        $bucket = Bucket::factory()->create([
            'store_id' => $this->store->id,
            'total_value' => 0,
        ]);

        $response = $this->withStore()->post("/transactions/{$transaction->id}/items/{$transactionItem->id}/move-to-bucket", [
            'bucket_id' => $bucket->id,
            'value' => 150.00,
        ]);

        $response->assertRedirect()
            ->assertSessionHas('success');

        $transactionItem->refresh();
        $this->assertTrue($transactionItem->is_added_to_bucket);
        $this->assertEquals($bucket->id, $transactionItem->bucket_id);

        $this->assertDatabaseHas('bucket_items', [
            'bucket_id' => $bucket->id,
            'transaction_item_id' => $transactionItem->id,
            'title' => 'Scrap Gold Ring',
            'value' => 150.00,
        ]);

        $bucket->refresh();
        $this->assertEquals(150.00, $bucket->total_value);
    }

    public function test_transaction_item_can_be_moved_to_bucket_with_custom_value(): void
    {
        $this->actingAs($this->user);

        $transaction = Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
        ]);

        $transactionItem = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'title' => 'Gold Chain',
            'buy_price' => 200.00,
            'price' => 250.00,
            'is_added_to_inventory' => false,
            'is_added_to_bucket' => false,
        ]);

        $bucket = Bucket::factory()->create([
            'store_id' => $this->store->id,
            'total_value' => 0,
        ]);

        // User overrides the value to a custom amount
        $customValue = 175.50;

        $response = $this->withStore()->post("/transactions/{$transaction->id}/items/{$transactionItem->id}/move-to-bucket", [
            'bucket_id' => $bucket->id,
            'value' => $customValue,
        ]);

        $response->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('bucket_items', [
            'bucket_id' => $bucket->id,
            'transaction_item_id' => $transactionItem->id,
            'title' => 'Gold Chain',
            'value' => $customValue,
        ]);

        $bucket->refresh();
        $this->assertEquals($customValue, $bucket->total_value);
    }

    public function test_move_to_bucket_requires_value(): void
    {
        $this->actingAs($this->user);

        $transaction = Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
        ]);

        $transactionItem = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'is_added_to_inventory' => false,
            'is_added_to_bucket' => false,
        ]);

        $bucket = Bucket::factory()->create(['store_id' => $this->store->id]);

        $response = $this->withStore()->post("/transactions/{$transaction->id}/items/{$transactionItem->id}/move-to-bucket", [
            'bucket_id' => $bucket->id,
            // No value provided
        ]);

        $response->assertSessionHasErrors('value');
    }

    public function test_cannot_move_already_bucketed_item(): void
    {
        $this->actingAs($this->user);

        $transaction = Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
        ]);

        $bucket1 = Bucket::factory()->create(['store_id' => $this->store->id]);
        $bucket2 = Bucket::factory()->create(['store_id' => $this->store->id]);

        $transactionItem = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'is_added_to_bucket' => true,
            'bucket_id' => $bucket1->id,
        ]);

        $response = $this->withStore()->post("/transactions/{$transaction->id}/items/{$transactionItem->id}/move-to-bucket", [
            'bucket_id' => $bucket2->id,
            'value' => 100.00,
        ]);

        $response->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_cannot_move_inventory_item_to_bucket(): void
    {
        $this->actingAs($this->user);

        $transaction = Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
        ]);

        $transactionItem = TransactionItem::factory()->addedToInventory()->create([
            'transaction_id' => $transaction->id,
        ]);

        $bucket = Bucket::factory()->create(['store_id' => $this->store->id]);

        $response = $this->withStore()->post("/transactions/{$transaction->id}/items/{$transactionItem->id}/move-to-bucket", [
            'bucket_id' => $bucket->id,
            'value' => 100.00,
        ]);

        $response->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_bucket_search_endpoint(): void
    {
        $this->actingAs($this->user);

        Bucket::factory()->count(3)->create(['store_id' => $this->store->id]);

        $response = $this->withStore()->getJson('/buckets/search');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'buckets')
            ->assertJsonStructure([
                'buckets' => [
                    '*' => ['id', 'name', 'total_value'],
                ],
            ]);
    }

    public function test_only_store_buckets_are_visible(): void
    {
        $this->actingAs($this->user);

        $otherStore = Store::factory()->create();
        Bucket::factory()->count(2)->create(['store_id' => $this->store->id]);
        Bucket::factory()->count(3)->create(['store_id' => $otherStore->id]);

        $response = $this->withStore()->get('/buckets');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('buckets/Index')
                ->has('buckets', 2)
            );
    }

    public function test_bucket_item_can_be_sold_through_order(): void
    {
        $bucket = Bucket::factory()->create([
            'store_id' => $this->store->id,
            'total_value' => 100.00,
        ]);

        $bucketItem = BucketItem::factory()->create([
            'bucket_id' => $bucket->id,
            'title' => 'Junk Watch',
            'value' => 100.00,
            'sold_at' => null,
        ]);

        // Create a product for the order (required by validation)
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Test Product',
        ]);

        $variant = $product->variants()->create([
            'sku' => 'TEST-SKU',
            'price' => 50.00,
            'quantity' => 10,
        ]);

        // Stock validation checks Inventory records, not variant.quantity
        $warehouse = Warehouse::factory()->create([
            'store_id' => $this->store->id,
            'is_default' => true,
        ]);

        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 10,
            'reserved_quantity' => 0,
        ]);

        $storeUser = StoreUser::where('store_id', $this->store->id)->first();

        $orderService = app(OrderCreationService::class);
        $order = $orderService->createFromWizard([
            'store_user_id' => $storeUser->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'price' => 50.00,
                ],
            ],
            'bucket_items' => [
                [
                    'id' => $bucketItem->id,
                    'price' => 100.00,
                ],
            ],
            'tax_rate' => 0,
        ], $this->store);

        // Verify bucket item is marked as sold
        $bucketItem->refresh();
        $this->assertTrue($bucketItem->isSold());
        $this->assertNotNull($bucketItem->sold_at);
        $this->assertNotNull($bucketItem->order_item_id);

        // Verify bucket total is recalculated
        $bucket->refresh();
        $this->assertEquals(0, $bucket->total_value);

        // Verify order has the bucket item
        $this->assertEquals(2, $order->items->count());
        $this->assertTrue($order->items->contains('bucket_item_id', $bucketItem->id));
    }

    public function test_search_bucket_items_endpoint(): void
    {
        $this->actingAs($this->user);

        $bucket = Bucket::factory()->create(['store_id' => $this->store->id]);
        BucketItem::factory()->count(3)->create([
            'bucket_id' => $bucket->id,
            'sold_at' => null,
        ]);

        // Create a sold item that should not appear
        BucketItem::factory()->sold()->create(['bucket_id' => $bucket->id]);

        $response = $this->withStore()->getJson('/orders/search-bucket-items');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'buckets' => [
                    '*' => ['id', 'name', 'total_value', 'items'],
                ],
            ]);

        // Should only show buckets with active items (3 active items)
        $this->assertCount(1, $response->json('buckets'));
        $this->assertCount(3, $response->json('buckets.0.items'));
    }

    public function test_can_create_sale_from_bucket(): void
    {
        $this->actingAs($this->user);

        $bucket = Bucket::factory()->create([
            'store_id' => $this->store->id,
            'total_value' => 150.00,
        ]);

        $item1 = BucketItem::factory()->create([
            'bucket_id' => $bucket->id,
            'title' => 'Watch 1',
            'value' => 100.00,
            'sold_at' => null,
        ]);

        $item2 = BucketItem::factory()->create([
            'bucket_id' => $bucket->id,
            'title' => 'Watch 2',
            'value' => 50.00,
            'sold_at' => null,
        ]);

        $storeUser = StoreUser::where('store_id', $this->store->id)->first();

        $response = $this->withStore()->post("/buckets/{$bucket->id}/create-sale", [
            'store_user_id' => $storeUser->id,
            'item_ids' => [$item1->id, $item2->id],
            'prices' => [
                $item1->id => 100.00,
                $item2->id => 50.00,
            ],
            'tax_rate' => 0,
        ]);

        $response->assertRedirect();

        // Verify items are marked as sold
        $item1->refresh();
        $item2->refresh();
        $this->assertTrue($item1->isSold());
        $this->assertTrue($item2->isSold());

        // Verify bucket total is recalculated
        $bucket->refresh();
        $this->assertEquals(0, $bucket->total_value);
    }

    public function test_cannot_create_sale_with_already_sold_items(): void
    {
        $this->actingAs($this->user);

        $bucket = Bucket::factory()->create(['store_id' => $this->store->id]);

        $soldItem = BucketItem::factory()->sold()->create([
            'bucket_id' => $bucket->id,
        ]);

        $storeUser = StoreUser::where('store_id', $this->store->id)->first();

        $response = $this->withStore()->post("/buckets/{$bucket->id}/create-sale", [
            'store_user_id' => $storeUser->id,
            'item_ids' => [$soldItem->id],
            'prices' => [
                $soldItem->id => 100.00,
            ],
            'tax_rate' => 0,
        ]);

        $response->assertRedirect()
            ->assertSessionHas('error');
    }
}
