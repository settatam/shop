<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryAllocationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected Warehouse $warehouse1;

    protected Warehouse $warehouse2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create([
            'user_id' => $this->user->id,
            'step' => 2,
        ]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        $this->warehouse1 = Warehouse::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Main Store',
            'is_default' => true,
        ]);
        $this->warehouse2 = Warehouse::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Vault',
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_page_loads_with_correct_props(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/inventory/allocations');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('inventory/Allocations')
            ->has('distribution')
            ->has('warehouses')
            ->has('transfers')
            ->has('stats')
            ->has('filters')
        );
    }

    public function test_distribution_shows_inventory_across_warehouses(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $this->warehouse1->id,
            'quantity' => 10,
            'unit_cost' => 50,
        ]);

        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $this->warehouse2->id,
            'quantity' => 5,
            'unit_cost' => 50,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/inventory/allocations');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('inventory/Allocations')
            ->has('distribution', 1)
            ->where('distribution.0.total_quantity', 15)
            ->where('distribution.0.variant_id', $variant->id)
        );
    }

    public function test_search_filter_works_on_distribution(): void
    {
        $product1 = Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Platinum Ring',
        ]);
        $variant1 = ProductVariant::factory()->create([
            'product_id' => $product1->id,
            'sku' => 'PLT-001',
        ]);

        $product2 = Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Gold Necklace',
        ]);
        $variant2 = ProductVariant::factory()->create([
            'product_id' => $product2->id,
            'sku' => 'GLD-001',
        ]);

        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant1->id,
            'warehouse_id' => $this->warehouse1->id,
            'quantity' => 10,
        ]);

        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant2->id,
            'warehouse_id' => $this->warehouse1->id,
            'quantity' => 5,
        ]);

        // Search by product title
        $response = $this->actingAs($this->user)
            ->get('/inventory/allocations?search=Platinum');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('distribution', 1)
            ->where('distribution.0.sku', 'PLT-001')
        );

        // Search by SKU
        $response = $this->actingAs($this->user)
            ->get('/inventory/allocations?search=GLD');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('distribution', 1)
            ->where('distribution.0.sku', 'GLD-001')
        );
    }

    public function test_status_filter_works_on_transfers(): void
    {
        InventoryTransfer::factory()->draft()->create([
            'store_id' => $this->store->id,
            'from_warehouse_id' => $this->warehouse1->id,
            'to_warehouse_id' => $this->warehouse2->id,
            'created_by' => $this->user->id,
        ]);

        InventoryTransfer::factory()->pending()->create([
            'store_id' => $this->store->id,
            'from_warehouse_id' => $this->warehouse1->id,
            'to_warehouse_id' => $this->warehouse2->id,
            'created_by' => $this->user->id,
        ]);

        InventoryTransfer::factory()->inTransit()->create([
            'store_id' => $this->store->id,
            'from_warehouse_id' => $this->warehouse1->id,
            'to_warehouse_id' => $this->warehouse2->id,
            'created_by' => $this->user->id,
        ]);

        // No filter: all transfers
        $response = $this->actingAs($this->user)
            ->get('/inventory/allocations');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('transfers.data', 3)
        );

        // Filter by draft
        $response = $this->actingAs($this->user)
            ->get('/inventory/allocations?status=draft');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('transfers.data', 1)
            ->where('transfers.data.0.status', 'draft')
        );

        // Filter by in_transit
        $response = $this->actingAs($this->user)
            ->get('/inventory/allocations?status=in_transit');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('transfers.data', 1)
            ->where('transfers.data.0.status', 'in_transit')
        );
    }

    public function test_stats_are_computed_correctly(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $this->warehouse1->id,
            'quantity' => 10,
            'unit_cost' => 100,
        ]);

        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $this->warehouse2->id,
            'quantity' => 5,
            'unit_cost' => 200,
        ]);

        InventoryTransfer::factory()->inTransit()->create([
            'store_id' => $this->store->id,
            'from_warehouse_id' => $this->warehouse1->id,
            'to_warehouse_id' => $this->warehouse2->id,
            'created_by' => $this->user->id,
        ]);

        InventoryTransfer::factory()->draft()->create([
            'store_id' => $this->store->id,
            'from_warehouse_id' => $this->warehouse1->id,
            'to_warehouse_id' => $this->warehouse2->id,
            'created_by' => $this->user->id,
        ]);

        InventoryTransfer::factory()->pending()->create([
            'store_id' => $this->store->id,
            'from_warehouse_id' => $this->warehouse1->id,
            'to_warehouse_id' => $this->warehouse2->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/inventory/allocations');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('stats.total_warehouses', 2)
            ->where('stats.items_in_transit', 1)
            ->where('stats.pending_transfers', 2)
            ->where('stats.total_allocated_value', 2000) // (10 * 100) + (5 * 200) = 2000
        );
    }

    public function test_transfer_search_filter_works(): void
    {
        InventoryTransfer::factory()->create([
            'store_id' => $this->store->id,
            'from_warehouse_id' => $this->warehouse1->id,
            'to_warehouse_id' => $this->warehouse2->id,
            'created_by' => $this->user->id,
            'reference' => 'TRF-000099',
        ]);

        InventoryTransfer::factory()->create([
            'store_id' => $this->store->id,
            'from_warehouse_id' => $this->warehouse1->id,
            'to_warehouse_id' => $this->warehouse2->id,
            'created_by' => $this->user->id,
            'reference' => 'TRF-000100',
        ]);

        $response = $this->actingAs($this->user)
            ->get('/inventory/allocations?transfer_search=000099');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('transfers.data', 1)
            ->where('transfers.data.0.reference', 'TRF-000099')
        );
    }

    public function test_requires_authentication(): void
    {
        $response = $this->get('/inventory/allocations');

        $response->assertRedirect('/login');
    }

    public function test_warehouses_are_returned(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/inventory/allocations');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('warehouses', 2)
        );
    }

    public function test_distribution_warehouse_quantities_are_pivoted(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $this->warehouse1->id,
            'quantity' => 7,
        ]);

        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $this->warehouse2->id,
            'quantity' => 3,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/inventory/allocations');

        $response->assertStatus(200);

        $data = $response->original->getData()['page']['props'];
        $row = $data['distribution'][0];

        $this->assertEquals(7, $row['warehouse_quantities'][$this->warehouse1->id]);
        $this->assertEquals(3, $row['warehouse_quantities'][$this->warehouse2->id]);
        $this->assertEquals(10, $row['total_quantity']);
    }

    public function test_transfers_include_items(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $transfer = InventoryTransfer::factory()->create([
            'store_id' => $this->store->id,
            'from_warehouse_id' => $this->warehouse1->id,
            'to_warehouse_id' => $this->warehouse2->id,
            'created_by' => $this->user->id,
        ]);

        InventoryTransferItem::factory()->create([
            'inventory_transfer_id' => $transfer->id,
            'product_variant_id' => $variant->id,
            'quantity_requested' => 5,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/inventory/allocations');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('transfers.data', 1)
            ->where('transfers.data.0.total_items', 5)
            ->has('transfers.data.0.items', 1)
        );
    }

    public function test_inactive_warehouses_are_excluded(): void
    {
        Warehouse::factory()->inactive()->create([
            'store_id' => $this->store->id,
            'name' => 'Inactive Warehouse',
        ]);

        $response = $this->actingAs($this->user)
            ->get('/inventory/allocations');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('warehouses', 2) // Only the 2 active warehouses from setUp
        );
    }
}
