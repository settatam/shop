<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\InventoryAdjustment;
use App\Models\InventoryTransfer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class WarehouseTest extends TestCase
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

    public function test_can_list_warehouses(): void
    {
        Passport::actingAs($this->user);

        Warehouse::factory()->count(3)->create(['store_id' => $this->store->id]);

        $response = $this->getJson('/api/v1/warehouses');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_can_create_warehouse(): void
    {
        Passport::actingAs($this->user);

        $response = $this->postJson('/api/v1/warehouses', [
            'name' => 'Main Warehouse',
            'code' => 'WH-001',
            'address_line1' => '123 Main St',
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10001',
            'country' => 'US',
            'is_default' => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'name' => 'Main Warehouse',
                'code' => 'WH-001',
            ]);

        $this->assertDatabaseHas('warehouses', [
            'store_id' => $this->store->id,
            'name' => 'Main Warehouse',
            'code' => 'WH-001',
        ]);
    }

    public function test_can_update_warehouse(): void
    {
        Passport::actingAs($this->user);

        $warehouse = Warehouse::factory()->create(['store_id' => $this->store->id]);

        $response = $this->putJson("/api/v1/warehouses/{$warehouse->id}", [
            'name' => 'Updated Warehouse',
            'city' => 'Los Angeles',
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'name' => 'Updated Warehouse',
                'city' => 'Los Angeles',
            ]);
    }

    public function test_can_delete_empty_warehouse(): void
    {
        Passport::actingAs($this->user);

        $warehouse = Warehouse::factory()->create(['store_id' => $this->store->id]);

        $response = $this->deleteJson("/api/v1/warehouses/{$warehouse->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted('warehouses', ['id' => $warehouse->id]);
    }

    public function test_cannot_delete_warehouse_with_inventory(): void
    {
        Passport::actingAs($this->user);

        $warehouse = Warehouse::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'quantity' => 10,
        ]);

        $response = $this->deleteJson("/api/v1/warehouses/{$warehouse->id}");

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Cannot delete warehouse with existing inventory',
            ]);
    }

    public function test_can_make_warehouse_default(): void
    {
        Passport::actingAs($this->user);

        $warehouse1 = Warehouse::factory()->default()->create(['store_id' => $this->store->id]);
        $warehouse2 = Warehouse::factory()->create(['store_id' => $this->store->id]);

        $response = $this->postJson("/api/v1/warehouses/{$warehouse2->id}/make-default");

        $response->assertStatus(200);

        $warehouse1->refresh();
        $warehouse2->refresh();

        $this->assertFalse($warehouse1->is_default);
        $this->assertTrue($warehouse2->is_default);
    }

    public function test_inventory_adjustments_track_changes(): void
    {
        $warehouse = Warehouse::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $inventory = Inventory::factory()->create([
            'store_id' => $this->store->id,
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'quantity' => 100,
        ]);

        $adjustment = $inventory->adjustQuantity(
            -10,
            InventoryAdjustment::TYPE_DAMAGED,
            $this->user->id,
            'Damaged during shipping'
        );

        $this->assertEquals(90, $inventory->fresh()->quantity);
        $this->assertEquals(100, $adjustment->quantity_before);
        $this->assertEquals(-10, $adjustment->quantity_change);
        $this->assertEquals(90, $adjustment->quantity_after);
        $this->assertEquals(InventoryAdjustment::TYPE_DAMAGED, $adjustment->type);
    }

    public function test_can_adjust_inventory_via_api(): void
    {
        Passport::actingAs($this->user);

        $warehouse = Warehouse::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $response = $this->postJson('/api/v1/inventory/adjust', [
            'product_variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'adjustment' => 50,
            'type' => 'received',
            'reason' => 'Initial stock',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('inventory', [
            'product_variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 50,
        ]);
    }

    public function test_inventory_reserve_and_release(): void
    {
        $warehouse = Warehouse::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $inventory = Inventory::factory()->create([
            'store_id' => $this->store->id,
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'quantity' => 100,
            'reserved_quantity' => 0,
        ]);

        // Reserve stock
        $this->assertTrue($inventory->reserve(20));
        $this->assertEquals(80, $inventory->fresh()->available_quantity);

        // Cannot reserve more than available
        $this->assertFalse($inventory->fresh()->reserve(90));

        // Release reservation
        $inventory->releaseReservation(10);
        $this->assertEquals(90, $inventory->fresh()->available_quantity);
    }

    public function test_inventory_fulfill_reduces_stock(): void
    {
        $warehouse = Warehouse::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $inventory = Inventory::factory()->create([
            'store_id' => $this->store->id,
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'quantity' => 100,
            'reserved_quantity' => 20,
        ]);

        $this->assertTrue($inventory->fulfill(20));
        $inventory->refresh();

        $this->assertEquals(80, $inventory->quantity);
        $this->assertEquals(0, $inventory->reserved_quantity);
        $this->assertNotNull($inventory->last_sold_at);
    }

    public function test_inventory_transfer_workflow(): void
    {
        Passport::actingAs($this->user);

        $warehouse1 = Warehouse::factory()->create(['store_id' => $this->store->id]);
        $warehouse2 = Warehouse::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        // Create initial inventory at source warehouse
        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'warehouse_id' => $warehouse1->id,
            'product_variant_id' => $variant->id,
            'quantity' => 100,
        ]);

        // Create transfer
        $response = $this->postJson('/api/v1/inventory-transfers', [
            'from_warehouse_id' => $warehouse1->id,
            'to_warehouse_id' => $warehouse2->id,
            'items' => [
                [
                    'product_variant_id' => $variant->id,
                    'quantity_requested' => 30,
                ],
            ],
        ]);

        $response->assertStatus(201);
        $transfer = InventoryTransfer::first();

        $this->assertEquals(InventoryTransfer::STATUS_DRAFT, $transfer->status);

        // Submit transfer
        $this->postJson("/api/v1/inventory-transfers/{$transfer->id}/submit")
            ->assertStatus(200);

        $transfer->refresh();
        $this->assertEquals(InventoryTransfer::STATUS_PENDING, $transfer->status);

        // Ship transfer
        $this->postJson("/api/v1/inventory-transfers/{$transfer->id}/ship")
            ->assertStatus(200);

        $transfer->refresh();
        $this->assertEquals(InventoryTransfer::STATUS_IN_TRANSIT, $transfer->status);

        // Verify source inventory decreased
        $sourceInventory = Inventory::where('warehouse_id', $warehouse1->id)
            ->where('product_variant_id', $variant->id)
            ->first();
        $this->assertEquals(70, $sourceInventory->quantity);

        // Receive transfer
        $this->postJson("/api/v1/inventory-transfers/{$transfer->id}/receive")
            ->assertStatus(200);

        $transfer->refresh();
        $this->assertEquals(InventoryTransfer::STATUS_RECEIVED, $transfer->status);

        // Verify destination inventory increased
        $destInventory = Inventory::where('warehouse_id', $warehouse2->id)
            ->where('product_variant_id', $variant->id)
            ->first();
        $this->assertEquals(30, $destInventory->quantity);
    }

    public function test_low_stock_detection(): void
    {
        $warehouse = Warehouse::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $inventory = Inventory::factory()->create([
            'store_id' => $this->store->id,
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'quantity' => 5,
            'safety_stock' => 10,
        ]);

        $this->assertTrue($inventory->isLowStock());
    }

    public function test_needs_reorder_detection(): void
    {
        $warehouse = Warehouse::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $inventory = Inventory::factory()->create([
            'store_id' => $this->store->id,
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'quantity' => 8,
            'reorder_point' => 10,
        ]);

        $this->assertTrue($inventory->needsReorder());
    }

    public function test_cannot_create_transfer_to_same_warehouse(): void
    {
        Passport::actingAs($this->user);

        $warehouse = Warehouse::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $response = $this->postJson('/api/v1/inventory-transfers', [
            'from_warehouse_id' => $warehouse->id,
            'to_warehouse_id' => $warehouse->id,
            'items' => [
                [
                    'product_variant_id' => $variant->id,
                    'quantity_requested' => 10,
                ],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_can_list_inventory_by_variant(): void
    {
        Passport::actingAs($this->user);

        $warehouse1 = Warehouse::factory()->create(['store_id' => $this->store->id]);
        $warehouse2 = Warehouse::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'warehouse_id' => $warehouse1->id,
            'product_variant_id' => $variant->id,
            'quantity' => 50,
        ]);

        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'warehouse_id' => $warehouse2->id,
            'product_variant_id' => $variant->id,
            'quantity' => 30,
        ]);

        $response = $this->getJson("/api/v1/inventory/by-variant/{$variant->id}");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('summary.total_quantity', 80);
    }
}
