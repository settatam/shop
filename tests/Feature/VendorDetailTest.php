<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Memo;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Repair;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorDetailTest extends TestCase
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
            'step' => 2,
        ]);

        $this->warehouse = Warehouse::factory()->create(['store_id' => $this->store->id]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_vendor_show_page_includes_memos_count(): void
    {
        $this->actingAs($this->user);

        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);

        Memo::factory()->count(3)->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->get("/vendors/{$vendor->id}");

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('vendors/Show')
                ->where('vendor.memos_count', 3)
            );
    }

    public function test_vendor_show_page_includes_repairs_count(): void
    {
        $this->actingAs($this->user);

        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        Repair::factory()->count(2)->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->get("/vendors/{$vendor->id}");

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('vendors/Show')
                ->where('vendor.repairs_count', 2)
            );
    }

    public function test_vendor_show_page_includes_products_count(): void
    {
        $this->actingAs($this->user);

        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);

        Product::factory()->count(5)->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
        ]);

        $response = $this->get("/vendors/{$vendor->id}");

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('vendors/Show')
                ->where('vendor.products_count', 5)
            );
    }

    public function test_vendor_memos_helper_returns_correct_data(): void
    {
        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);

        $memo = Memo::factory()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'user_id' => $this->user->id,
            'memo_number' => 'MEM-001',
            'status' => 'pending',
        ]);

        $memos = $vendor->memos()->with('user:id,name')->get();

        $this->assertCount(1, $memos);
        $this->assertEquals('MEM-001', $memos->first()->memo_number);
        $this->assertEquals('pending', $memos->first()->status);
    }

    public function test_vendor_repairs_helper_returns_correct_data(): void
    {
        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        $repair = Repair::factory()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'repair_number' => 'REP-001',
            'status' => 'pending',
        ]);

        $repairs = $vendor->repairs()->with(['user:id,name', 'customer:id,first_name,last_name'])->get();

        $this->assertCount(1, $repairs);
        $this->assertEquals('REP-001', $repairs->first()->repair_number);
        $this->assertEquals('pending', $repairs->first()->status);
    }

    public function test_vendor_products_returns_correct_data(): void
    {
        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);

        $productWithStock = Product::factory()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
            'quantity' => 10,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $productWithStock->id,
            'quantity' => 10,
        ]);

        $productNoStock = Product::factory()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
            'quantity' => 0,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $productNoStock->id,
            'quantity' => 0,
        ]);

        // All products from vendor
        $allProducts = $vendor->products()->get();
        $this->assertCount(2, $allProducts);

        // Products with stock
        $productsWithStock = $vendor->products()
            ->with(['variants' => fn ($q) => $q->orderBy('sort_order')->limit(1)])
            ->where(function ($query) {
                $query->where('quantity', '>', 0)
                    ->orWhereHas('variants', fn ($q) => $q->where('quantity', '>', 0));
            })
            ->get();
        $this->assertCount(1, $productsWithStock);
    }

    public function test_vendor_sold_items_profit_calculation(): void
    {
        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
        ]);

        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'TEST-SKU',
        ]);

        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'warehouse_id' => $this->warehouse->id,
            'user_id' => $this->user->id,
            'status' => 'completed',
            'invoice_number' => 'INV-001',
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'sku' => 'TEST-SKU',
            'price' => 150.00,
            'cost' => 100.00,
            'quantity' => 1,
        ]);

        // Verify the query logic works
        $soldItems = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where('products.vendor_id', $vendor->id)
            ->whereIn('orders.status', ['completed', 'shipped', 'delivered'])
            ->get();

        $this->assertCount(1, $soldItems);

        $item = $soldItems->first();
        $cost = (float) $item->cost;
        $price = (float) $item->price;
        $profit = $price - $cost;
        $profitPercent = ($profit / $cost) * 100;

        $this->assertEquals(100.00, $cost);
        $this->assertEquals(150.00, $price);
        $this->assertEquals(50.00, $profit);
        $this->assertEquals(50.0, $profitPercent);
    }

    public function test_vendor_relationships_are_properly_defined(): void
    {
        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $vendor->memos());
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $vendor->repairs());
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $vendor->products());
    }
}
