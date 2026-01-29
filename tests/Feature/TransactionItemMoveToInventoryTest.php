<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Image;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StoreContext;
use App\Services\Transactions\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionItemMoveToInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id, 'step' => 2]);
        $this->warehouse = Warehouse::factory()->create(['store_id' => $this->store->id, 'is_default' => true]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        $this->user->update(['current_store_id' => $this->store->id]);
        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_move_item_creates_product_as_draft(): void
    {
        $transaction = Transaction::factory()->paymentProcessed()->create(['store_id' => $this->store->id]);
        $item = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'title' => 'Gold Necklace',
            'buy_price' => 200.00,
            'price' => 500.00,
        ]);

        $service = app(TransactionService::class);
        $product = $service->moveItemToInventory($item);

        $this->assertFalse($product->is_published);
        $this->assertEquals('Gold Necklace', $product->title);
        $this->assertEquals($this->store->id, $product->store_id);

        $item->refresh();
        $this->assertTrue($item->is_added_to_inventory);
        $this->assertEquals($product->id, $item->product_id);
    }

    public function test_move_item_creates_variant_with_pricing(): void
    {
        $transaction = Transaction::factory()->paymentProcessed()->create(['store_id' => $this->store->id]);
        $item = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'price' => 500.00,
            'buy_price' => 200.00,
        ]);

        $service = app(TransactionService::class);
        $product = $service->moveItemToInventory($item);

        $variant = $product->variants->first();
        $this->assertNotNull($variant);
        $this->assertEquals(500.00, $variant->price);
        $this->assertEquals(200.00, $variant->cost);
    }

    public function test_move_item_transfers_images(): void
    {
        $transaction = Transaction::factory()->paymentProcessed()->create(['store_id' => $this->store->id]);
        $item = TransactionItem::factory()->create(['transaction_id' => $transaction->id]);

        Image::create([
            'store_id' => $this->store->id,
            'imageable_type' => TransactionItem::class,
            'imageable_id' => $item->id,
            'path' => 'test/image.jpg',
            'url' => 'http://example.com/image.jpg',
            'thumbnail_url' => 'http://example.com/thumb.jpg',
            'disk' => 'public',
            'size' => 1024,
            'mime_type' => 'image/jpeg',
            'is_primary' => true,
        ]);

        $service = app(TransactionService::class);
        $product = $service->moveItemToInventory($item);

        $this->assertEquals(1, $product->images()->count());
        $this->assertEquals(0, $item->images()->count());
    }

    public function test_move_item_creates_inventory_record(): void
    {
        $transaction = Transaction::factory()->paymentProcessed()->withWarehouse($this->warehouse)->create([
            'store_id' => $this->store->id,
        ]);
        $item = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'buy_price' => 150.00,
        ]);

        $service = app(TransactionService::class);
        $product = $service->moveItemToInventory($item);

        $variant = $product->variants->first();
        $inventory = $variant->inventories()->where('warehouse_id', $this->warehouse->id)->first();

        $this->assertNotNull($inventory);
        $this->assertEquals(1, $inventory->quantity);
    }

    public function test_move_item_sets_template_from_category(): void
    {
        $template = \App\Models\ProductTemplate::factory()->create(['store_id' => $this->store->id]);
        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'template_id' => $template->id,
        ]);

        $transaction = Transaction::factory()->paymentProcessed()->create(['store_id' => $this->store->id]);
        $item = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'category_id' => $category->id,
        ]);

        $service = app(TransactionService::class);
        $product = $service->moveItemToInventory($item);

        $this->assertEquals($template->id, $product->template_id);
    }

    public function test_cannot_move_already_inventoried_item(): void
    {
        $transaction = Transaction::factory()->create(['store_id' => $this->store->id]);
        $item = TransactionItem::factory()->addedToInventory()->create([
            'transaction_id' => $transaction->id,
        ]);

        $this->actingAs($this->user);

        $response = $this->post("/transactions/{$transaction->id}/items/{$item->id}/move-to-inventory");

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_cannot_move_item_via_post_from_different_store(): void
    {
        $otherStore = Store::factory()->create();
        $otherTransaction = Transaction::factory()->create(['store_id' => $otherStore->id]);
        $otherItem = TransactionItem::factory()->create(['transaction_id' => $otherTransaction->id]);

        $this->actingAs($this->user);

        $response = $this->post("/transactions/{$otherTransaction->id}/items/{$otherItem->id}/move-to-inventory");

        $response->assertStatus(404);
    }
}
