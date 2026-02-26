<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventorySyncTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->warehouse = Warehouse::factory()->create([
            'store_id' => $this->store->id,
            'is_default' => true,
        ]);
    }

    public function test_inventory_save_syncs_variant_quantity(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id, 'quantity' => 0]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'quantity' => 0]);

        $inventory = Inventory::create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 25,
        ]);

        $this->assertEquals(25, $variant->fresh()->quantity);
        $this->assertEquals(25, $product->fresh()->quantity);
    }

    public function test_inventory_update_syncs_variant_quantity(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id, 'quantity' => 0]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'quantity' => 0]);

        $inventory = Inventory::create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 10,
        ]);

        $inventory->quantity = 42;
        $inventory->save();

        $this->assertEquals(42, $variant->fresh()->quantity);
        $this->assertEquals(42, $product->fresh()->quantity);
    }

    public function test_inventory_delete_syncs_variant_quantity(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id, 'quantity' => 0]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'quantity' => 0]);

        $warehouse2 = Warehouse::factory()->create(['store_id' => $this->store->id]);

        Inventory::create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 15,
        ]);

        $inventory2 = Inventory::create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $warehouse2->id,
            'quantity' => 10,
        ]);

        $this->assertEquals(25, $variant->fresh()->quantity);

        $inventory2->delete();

        $this->assertEquals(15, $variant->fresh()->quantity);
        $this->assertEquals(15, $product->fresh()->quantity);
    }

    public function test_multiple_warehouses_sum_correctly(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id, 'quantity' => 0]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'quantity' => 0]);

        $warehouse2 = Warehouse::factory()->create(['store_id' => $this->store->id]);
        $warehouse3 = Warehouse::factory()->create(['store_id' => $this->store->id]);

        Inventory::create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 10,
        ]);

        Inventory::create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $warehouse2->id,
            'quantity' => 20,
        ]);

        Inventory::create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $warehouse3->id,
            'quantity' => 7,
        ]);

        $this->assertEquals(37, $variant->fresh()->quantity);
        $this->assertEquals(37, $product->fresh()->quantity);
    }

    public function test_product_total_quantity_sums_from_variants(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'quantity' => 999, // Stale value should be ignored
            'has_variants' => false,
        ]);

        $variant1 = ProductVariant::factory()->create(['product_id' => $product->id, 'quantity' => 15]);
        $variant2 = ProductVariant::factory()->create(['product_id' => $product->id, 'quantity' => 25]);

        $product->load('variants');

        $this->assertEquals(40, $product->total_quantity);
    }

    public function test_explicit_sync_after_raw_update(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id, 'quantity' => 0]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'quantity' => 0]);

        $inventory = Inventory::create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 50,
        ]);

        // Simulate a raw DB update that bypasses model events
        Inventory::where('id', $inventory->id)->update([
            'quantity' => \Illuminate\Support\Facades\DB::raw('quantity - 10'),
        ]);

        // At this point variant is stale — explicit sync fixes it
        Inventory::syncVariantQuantity($variant->id);
        Inventory::syncProductQuantity($product->id);

        $this->assertEquals(40, $variant->fresh()->quantity);
        $this->assertEquals(40, $product->fresh()->quantity);
    }

    public function test_artisan_command_syncs_stale_quantities(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id, 'quantity' => 999]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'quantity' => 888]);

        // Create inventory with the real value, but bypass model events to keep stale variant/product
        Inventory::withoutEvents(function () use ($variant) {
            Inventory::create([
                'store_id' => $this->store->id,
                'product_variant_id' => $variant->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 27,
            ]);
        });

        // Variant and product still have stale values
        $this->assertEquals(888, $variant->fresh()->quantity);
        $this->assertEquals(999, $product->fresh()->quantity);

        $this->artisan('inventory:sync-quantities')
            ->expectsOutputToContain('synced successfully')
            ->assertSuccessful();

        $this->assertEquals(27, $variant->fresh()->quantity);
        $this->assertEquals(27, $product->fresh()->quantity);
    }

    public function test_adjust_quantity_propagates_to_variant_and_product(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id, 'quantity' => 0]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'quantity' => 0]);

        $inventory = Inventory::create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 0,
        ]);

        // adjustQuantity uses $this->save() which fires model events
        $inventory->adjustQuantity(30, 'manual', null, 'Test adjustment');

        $this->assertEquals(30, $variant->fresh()->quantity);
        $this->assertEquals(30, $product->fresh()->quantity);
    }

    public function test_multiple_variants_sync_product_quantity(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id, 'quantity' => 0]);
        $variant1 = ProductVariant::factory()->create(['product_id' => $product->id, 'quantity' => 0]);
        $variant2 = ProductVariant::factory()->create(['product_id' => $product->id, 'quantity' => 0]);

        Inventory::create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant1->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 10,
        ]);

        Inventory::create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant2->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 20,
        ]);

        $this->assertEquals(10, $variant1->fresh()->quantity);
        $this->assertEquals(20, $variant2->fresh()->quantity);
        $this->assertEquals(30, $product->fresh()->quantity);
    }
}
