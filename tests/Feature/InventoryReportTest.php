<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryAdjustment;
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

class InventoryReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create([
            'user_id' => $this->user->id,
            'step' => 2, // Complete onboarding
        ]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        $this->warehouse = Warehouse::factory()->create(['store_id' => $this->store->id]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_can_view_inventory_report(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/reports/inventory');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/inventory/Index')
            ->has('categoryData')
            ->has('totals')
            ->has('weeklyTrend')
        );
    }

    public function test_inventory_report_shows_category_data(): void
    {
        // Create a category with inventory
        $category = Category::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $category->id,
        ]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 100,
            'wholesale_price' => 80,
        ]);

        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 10,
            'unit_cost' => 50,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/reports/inventory');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/inventory/Index')
            ->has('categoryData', 1)
            ->where('totals.total_stock', 10)
        );

        // Verify the total_value is calculated correctly (quantity * unit_cost = 10 * 50 = 500)
        $data = $response->original->getData()['page']['props'];
        $this->assertEquals(500, $data['totals']['total_value']);
    }

    public function test_inventory_report_shows_weekly_additions(): void
    {
        // Create a category with inventory
        $category = Category::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $category->id,
        ]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
        ]);

        $inventory = Inventory::factory()->create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 10,
            'unit_cost' => 50,
        ]);

        // Create an adjustment from this week
        InventoryAdjustment::factory()->create([
            'store_id' => $this->store->id,
            'inventory_id' => $inventory->id,
            'quantity_change' => 5,
            'total_cost_impact' => 250,
            'type' => InventoryAdjustment::TYPE_RECEIVED,
            'created_at' => now()->subDays(2),
        ]);

        $response = $this->actingAs($this->user)
            ->get('/reports/inventory');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/inventory/Index')
            ->where('totals.added_this_week', 5)
        );

        // Verify the cost_added is calculated correctly
        $data = $response->original->getData()['page']['props'];
        $this->assertEquals(250, $data['totals']['cost_added']);
    }

    public function test_inventory_report_shows_weekly_deletions(): void
    {
        // Create a category with inventory
        $category = Category::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $category->id,
        ]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
        ]);

        $inventory = Inventory::factory()->create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 10,
            'unit_cost' => 50,
        ]);

        // Create a deletion adjustment from this week
        InventoryAdjustment::factory()->create([
            'store_id' => $this->store->id,
            'inventory_id' => $inventory->id,
            'quantity_change' => -3,
            'total_cost_impact' => -150,
            'type' => InventoryAdjustment::TYPE_DAMAGED,
            'created_at' => now()->subDays(1),
        ]);

        $response = $this->actingAs($this->user)
            ->get('/reports/inventory');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/inventory/Index')
            ->where('totals.deleted_this_week', 3)
        );

        // Verify the deleted_cost is calculated correctly
        $data = $response->original->getData()['page']['props'];
        $this->assertEquals(150, $data['totals']['deleted_cost']);
    }

    public function test_can_export_inventory_report_csv(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/reports/inventory/export');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    }

    public function test_inventory_report_calculates_projected_profit(): void
    {
        // Create a category with inventory
        $category = Category::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $category->id,
        ]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 150, // Retail price (what we sell for)
            'wholesale_price' => 100, // Cost basis (what we paid)
        ]);

        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 10,
            'unit_cost' => 50,
        ]);

        // Projected profit = retail_value - cost_basis
        // retail_value = price (150) * quantity (10) = 1500
        // cost_basis = wholesale_price (100) * quantity (10) = 1000 (uses wholesale_price as cost since it's set)
        // profit = 1500 - 1000 = 500

        $response = $this->actingAs($this->user)
            ->get('/reports/inventory');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/inventory/Index')
        );

        // Verify the projected profit calculation
        $data = $response->original->getData()['page']['props'];
        $totalValue = $data['totals']['total_value'];
        $projectedProfit = $data['totals']['projected_profit'];

        // Projected profit = retail_value - cost_basis
        // retail_value = price (150) * qty (10) = 1500
        // cost_basis = wholesale_price (100) * qty (10) = 1000
        // profit = 1500 - 1000 = 500
        $this->assertEquals(500, $totalValue, 'Total value should be 500 (10 qty * 50 unit_cost)');
        $this->assertEquals(500, $projectedProfit, 'Projected profit should be 500 (1500 retail - 1000 cost)');
    }

    public function test_inventory_report_requires_authentication(): void
    {
        $response = $this->get('/reports/inventory');

        $response->assertRedirect('/login');
    }

    public function test_can_view_weekly_inventory_report(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/reports/inventory/weekly');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/inventory/Weekly')
            ->has('weeklyData')
            ->has('totals')
        );
    }

    public function test_can_view_monthly_inventory_report(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/reports/inventory/monthly');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/inventory/Monthly')
            ->has('monthlyData')
            ->has('totals')
        );
    }

    public function test_can_view_yearly_inventory_report(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/reports/inventory/yearly');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/inventory/Yearly')
            ->has('yearlyData')
            ->has('totals')
        );
    }

    public function test_can_export_weekly_inventory_report_csv(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/reports/inventory/weekly/export');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    }

    public function test_can_export_monthly_inventory_report_csv(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/reports/inventory/monthly/export');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    }

    public function test_can_export_yearly_inventory_report_csv(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/reports/inventory/yearly/export');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    }

    public function test_weekly_report_shows_inventory_adjustments(): void
    {
        // Create a category with inventory
        $category = Category::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $category->id,
        ]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
        ]);

        $inventory = Inventory::factory()->create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 10,
            'unit_cost' => 50,
        ]);

        // Create an adjustment from this week
        InventoryAdjustment::factory()->create([
            'store_id' => $this->store->id,
            'inventory_id' => $inventory->id,
            'quantity_change' => 5,
            'total_cost_impact' => 250,
            'type' => InventoryAdjustment::TYPE_RECEIVED,
            'created_at' => now()->subDays(2),
        ]);

        $response = $this->actingAs($this->user)
            ->get('/reports/inventory/weekly');

        $response->assertStatus(200);

        // Verify the data is returned
        $data = $response->original->getData()['page']['props'];
        $this->assertGreaterThan(0, count($data['weeklyData']));
    }

    public function test_can_drill_down_into_category(): void
    {
        // Create a parent category with a child category
        $parentCategory = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Jewelry',
            'parent_id' => null,
        ]);

        $childCategory = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Rings',
            'parent_id' => $parentCategory->id,
        ]);

        // Create a product in the child category
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $childCategory->id,
        ]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
        ]);

        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 5,
            'unit_cost' => 100,
        ]);

        // Drill down into the parent category
        $response = $this->actingAs($this->user)
            ->get('/reports/inventory?category_id='.$parentCategory->id);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/inventory/Index')
            ->has('currentCategory')
            ->where('currentCategory.id', $parentCategory->id)
            ->where('currentCategory.name', 'Jewelry')
            ->has('categoryData', 1) // Should show the child category
        );

        // Verify the child category appears
        $data = $response->original->getData()['page']['props'];
        $this->assertEquals('Rings', $data['categoryData'][0]['category']);
    }

    public function test_hierarchical_view_shows_aggregated_descendant_data(): void
    {
        // Create parent -> child hierarchy
        $parentCategory = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Jewelry',
            'parent_id' => null,
        ]);

        $childCategory = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Rings',
            'parent_id' => $parentCategory->id,
        ]);

        $grandchildCategory = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Engagement Rings',
            'parent_id' => $childCategory->id,
        ]);

        // Create products in different levels
        // Product in child category
        $product1 = Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $childCategory->id,
        ]);
        $variant1 = ProductVariant::factory()->create(['product_id' => $product1->id]);
        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant1->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 5,
            'unit_cost' => 100,
        ]);

        // Product in grandchild category
        $product2 = Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $grandchildCategory->id,
        ]);
        $variant2 = ProductVariant::factory()->create(['product_id' => $product2->id]);
        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant2->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 3,
            'unit_cost' => 200,
        ]);

        // View root level - should aggregate all descendant data
        $response = $this->actingAs($this->user)
            ->get('/reports/inventory');

        $response->assertStatus(200);

        $data = $response->original->getData()['page']['props'];

        // Find the Jewelry category
        $jewelryData = collect($data['categoryData'])->firstWhere('category', 'Jewelry');
        $this->assertNotNull($jewelryData);

        // Should aggregate: 5 units (child) + 3 units (grandchild) = 8 total
        $this->assertEquals(8, $jewelryData['total_stock']);

        // Should aggregate value: (5 * 100) + (3 * 200) = 1100
        $this->assertEquals(1100, $jewelryData['total_value']);

        // Should indicate it has children
        $this->assertTrue($jewelryData['has_children']);
    }

    public function test_flat_view_shows_all_categories(): void
    {
        // Create hierarchical categories
        $parentCategory = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Jewelry',
            'parent_id' => null,
        ]);

        $childCategory = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Rings',
            'parent_id' => $parentCategory->id,
        ]);

        // Create products in both categories
        $product1 = Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $parentCategory->id,
        ]);
        $variant1 = ProductVariant::factory()->create(['product_id' => $product1->id]);
        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant1->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 5,
            'unit_cost' => 100,
        ]);

        $product2 = Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $childCategory->id,
        ]);
        $variant2 = ProductVariant::factory()->create(['product_id' => $product2->id]);
        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant2->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 3,
            'unit_cost' => 200,
        ]);

        // Request flat view
        $response = $this->actingAs($this->user)
            ->get('/reports/inventory?view_all=1');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/inventory/Index')
            ->where('viewAll', true)
        );

        $data = $response->original->getData()['page']['props'];

        // Both categories should appear in flat view
        $categoryNames = collect($data['categoryData'])->pluck('category')->toArray();
        $this->assertContains('Jewelry', $categoryNames);
        $this->assertContains('Rings', $categoryNames);
    }

    public function test_breadcrumb_shows_parent_path(): void
    {
        // Create parent -> child -> grandchild hierarchy
        $grandparent = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Jewelry',
            'parent_id' => null,
        ]);

        $parent = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Rings',
            'parent_id' => $grandparent->id,
        ]);

        $child = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Engagement',
            'parent_id' => $parent->id,
        ]);

        // Navigate to the child category
        $response = $this->actingAs($this->user)
            ->get('/reports/inventory?category_id='.$child->id);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/inventory/Index')
            ->has('breadcrumb', 2) // Should have grandparent and parent
            ->where('currentCategory.name', 'Engagement')
        );

        $data = $response->original->getData()['page']['props'];

        // Verify breadcrumb order
        $this->assertEquals('Jewelry', $data['breadcrumb'][0]['name']);
        $this->assertEquals('Rings', $data['breadcrumb'][1]['name']);
    }
}
