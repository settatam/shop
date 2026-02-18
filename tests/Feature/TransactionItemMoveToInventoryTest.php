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

    public function test_move_item_copies_images(): void
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

        // Images are copied, not transferred - both item and product have the images
        $this->assertEquals(1, $product->images()->count());
        $this->assertEquals(1, $item->images()->count());

        // Verify the product image has the same URL as the original
        $productImage = $product->images()->first();
        $this->assertEquals('http://example.com/image.jpg', $productImage->url);
        $this->assertEquals('http://example.com/thumb.jpg', $productImage->thumbnail_url);
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

    public function test_move_item_creates_inventory_with_custom_quantity(): void
    {
        $transaction = Transaction::factory()->paymentProcessed()->withWarehouse($this->warehouse)->create([
            'store_id' => $this->store->id,
        ]);
        $item = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'quantity' => 5,
            'buy_price' => 100.00,
        ]);

        $service = app(TransactionService::class);
        $product = $service->moveItemToInventory($item);

        $variant = $product->variants->first();
        $inventory = $variant->inventories()->where('warehouse_id', $this->warehouse->id)->first();

        $this->assertNotNull($inventory);
        $this->assertEquals(5, $inventory->quantity);
    }

    public function test_transaction_item_quantity_defaults_to_one(): void
    {
        $transaction = Transaction::factory()->create(['store_id' => $this->store->id]);
        $item = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
        ]);

        $this->assertEquals(1, $item->quantity);
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

    public function test_move_item_copies_attributes(): void
    {
        $template = \App\Models\ProductTemplate::factory()->create(['store_id' => $this->store->id]);
        $field1 = \App\Models\ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'name' => 'brand',
            'label' => 'Brand',
            'type' => 'text',
        ]);
        $field2 = \App\Models\ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'name' => 'model',
            'label' => 'Model',
            'type' => 'text',
        ]);

        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'template_id' => $template->id,
        ]);

        $transaction = Transaction::factory()->paymentProcessed()->create(['store_id' => $this->store->id]);
        $item = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'category_id' => $category->id,
            'attributes' => [
                $field1->id => 'Rolex',
                $field2->id => 'Submariner',
            ],
        ]);

        $service = app(TransactionService::class);
        $product = $service->moveItemToInventory($item);

        // Verify attributes were copied to product_attribute_values
        $this->assertEquals(2, $product->attributeValues()->count());

        $brandValue = $product->attributeValues()->where('product_template_field_id', $field1->id)->first();
        $this->assertNotNull($brandValue);
        $this->assertEquals('Rolex', $brandValue->value);

        $modelValue = $product->attributeValues()->where('product_template_field_id', $field2->id)->first();
        $this->assertNotNull($modelValue);
        $this->assertEquals('Submariner', $modelValue->value);
    }

    public function test_move_item_via_post_requires_vendor_id(): void
    {
        $transaction = Transaction::factory()->paymentProcessed()->create(['store_id' => $this->store->id]);
        $item = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
        ]);

        $this->actingAs($this->user);

        $response = $this->post("/transactions/{$transaction->id}/items/{$item->id}/move-to-inventory", [
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 1,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('vendor_id');
    }

    public function test_move_item_via_post_requires_warehouse_id(): void
    {
        $vendor = \App\Models\Vendor::factory()->create(['store_id' => $this->store->id]);
        $transaction = Transaction::factory()->paymentProcessed()->create(['store_id' => $this->store->id]);
        $item = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
        ]);

        $this->actingAs($this->user);

        $response = $this->post("/transactions/{$transaction->id}/items/{$item->id}/move-to-inventory", [
            'vendor_id' => $vendor->id,
            'quantity' => 1,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('warehouse_id');
    }

    public function test_move_item_via_post_requires_quantity(): void
    {
        $vendor = \App\Models\Vendor::factory()->create(['store_id' => $this->store->id]);
        $transaction = Transaction::factory()->paymentProcessed()->create(['store_id' => $this->store->id]);
        $item = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
        ]);

        $this->actingAs($this->user);

        $response = $this->post("/transactions/{$transaction->id}/items/{$item->id}/move-to-inventory", [
            'vendor_id' => $vendor->id,
            'warehouse_id' => $this->warehouse->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('quantity');
    }

    public function test_move_item_via_post_with_all_required_fields(): void
    {
        $vendor = \App\Models\Vendor::factory()->create(['store_id' => $this->store->id]);
        $transaction = Transaction::factory()->paymentProcessed()->create(['store_id' => $this->store->id]);
        $item = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'title' => 'Gold Chain',
        ]);

        $this->actingAs($this->user);

        $response = $this->post("/transactions/{$transaction->id}/items/{$item->id}/move-to-inventory", [
            'vendor_id' => $vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 3,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $item->refresh();
        $this->assertTrue($item->is_added_to_inventory);
        $this->assertNotNull($item->product_id);

        $product = \App\Models\Product::find($item->product_id);
        $this->assertEquals($vendor->id, $product->vendor_id);

        // Check inventory was created with correct quantity
        $variant = $product->variants()->first();
        $inventory = \App\Models\Inventory::where('product_variant_id', $variant->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();
        $this->assertNotNull($inventory);
        $this->assertEquals(3, $inventory->quantity);
    }

    public function test_cannot_move_already_inventoried_item(): void
    {
        $vendor = \App\Models\Vendor::factory()->create(['store_id' => $this->store->id]);
        $transaction = Transaction::factory()->create(['store_id' => $this->store->id]);
        $item = TransactionItem::factory()->addedToInventory()->create([
            'transaction_id' => $transaction->id,
        ]);

        $this->actingAs($this->user);

        $response = $this->post("/transactions/{$transaction->id}/items/{$item->id}/move-to-inventory", [
            'vendor_id' => $vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 1,
        ]);

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
