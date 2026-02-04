<?php

namespace Tests\Feature;

use App\Models\Bucket;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Memo;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductTemplate;
use App\Models\Repair;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Tests that all major web routes are accessible and properly ordered.
 *
 * This test helps catch route ordering issues where dynamic routes like
 * `/orders/{order}` accidentally match static routes like `/orders/create`.
 */
class RouteAccessibilityTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected Role $ownerRole;

    protected StoreUser $storeUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create([
            'user_id' => $this->user->id,
            'step' => 2,
        ]);

        $this->ownerRole = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        $this->storeUser = StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $this->ownerRole->id,
        ]);

        $this->user->update(['current_store_id' => $this->store->id]);
        app(StoreContext::class)->setCurrentStore($this->store);
    }

    // ==================== Product Routes ====================

    public function test_products_index_route_is_accessible(): void
    {
        $response = $this->actingAs($this->user)->get('/products');

        $response->assertStatus(200);
    }

    public function test_products_create_route_is_accessible(): void
    {
        $response = $this->actingAs($this->user)->get('/products/create');

        $response->assertStatus(200);
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('products/Create'));
    }

    public function test_products_show_route_is_accessible(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id]);

        $response = $this->actingAs($this->user)->get("/products/{$product->id}");

        $response->assertStatus(200);
    }

    public function test_products_create_route_takes_priority_over_dynamic_route(): void
    {
        // This specifically tests that /products/create doesn't match /products/{product}
        $response = $this->actingAs($this->user)->get('/products/create');

        // Should return 200, not 404 (which would happen if 'create' was treated as a product ID)
        $response->assertStatus(200);
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('products/Create'));
    }

    // ==================== Order Routes ====================

    public function test_orders_index_route_is_accessible(): void
    {
        $response = $this->actingAs($this->user)->get('/orders');

        $response->assertStatus(200);
    }

    public function test_orders_create_route_is_accessible(): void
    {
        $response = $this->actingAs($this->user)->get('/orders/create');

        $response->assertStatus(200);
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('orders/CreateWizard'));
    }

    public function test_orders_show_route_is_accessible(): void
    {
        $order = Order::factory()->create(['store_id' => $this->store->id]);

        $response = $this->actingAs($this->user)->get("/orders/{$order->id}");

        $response->assertStatus(200);
    }

    public function test_orders_create_route_takes_priority_over_dynamic_route(): void
    {
        $response = $this->actingAs($this->user)->get('/orders/create');

        $response->assertStatus(200);
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('orders/CreateWizard'));
    }

    // ==================== Transaction Routes ====================

    public function test_transactions_index_route_is_accessible(): void
    {
        $response = $this->actingAs($this->user)->get('/transactions');

        $response->assertStatus(200);
    }

    public function test_transactions_buy_route_is_accessible(): void
    {
        $response = $this->actingAs($this->user)->get('/transactions/buy');

        $response->assertStatus(200);
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('transactions/CreateWizard'));
    }

    public function test_transactions_create_route_is_accessible(): void
    {
        $response = $this->actingAs($this->user)->get('/transactions/create');

        // This route may redirect to a different page based on store settings
        $response->assertStatus(302);
        $response->assertRedirectContains('/transactions');
    }

    public function test_transactions_show_route_is_accessible(): void
    {
        $transaction = Transaction::factory()->create(['store_id' => $this->store->id]);

        $response = $this->actingAs($this->user)->get("/transactions/{$transaction->id}");

        $response->assertStatus(200);
    }

    public function test_transactions_buy_route_takes_priority_over_dynamic_route(): void
    {
        $response = $this->actingAs($this->user)->get('/transactions/buy');

        $response->assertStatus(200);
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('transactions/CreateWizard'));
    }

    // ==================== Memo Routes ====================

    public function test_memos_index_route_is_accessible(): void
    {
        $response = $this->actingAs($this->user)->get('/memos');

        $response->assertStatus(200);
    }

    public function test_memos_create_route_is_accessible(): void
    {
        $response = $this->actingAs($this->user)->get('/memos/create');

        $response->assertStatus(200);
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('memos/CreateWizard'));
    }

    public function test_memos_show_route_is_accessible(): void
    {
        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $memo = Memo::factory()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
        ]);

        $response = $this->actingAs($this->user)->get("/memos/{$memo->id}");

        $response->assertStatus(200);
    }

    public function test_memos_create_route_takes_priority_over_dynamic_route(): void
    {
        $response = $this->actingAs($this->user)->get('/memos/create');

        $response->assertStatus(200);
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('memos/CreateWizard'));
    }

    // ==================== Layaway Routes ====================

    public function test_layaways_index_route_is_accessible(): void
    {
        $response = $this->actingAs($this->user)->get('/layaways');

        $response->assertStatus(200);
    }

    public function test_layaways_create_route_is_accessible(): void
    {
        $response = $this->actingAs($this->user)->get('/layaways/create');

        $response->assertStatus(200);
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('layaways/CreateWizard'));
    }

    public function test_layaways_show_route_is_accessible(): void
    {
        $customer = Customer::factory()->create(['store_id' => $this->store->id]);
        $layaway = Layaway::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
        ]);

        $response = $this->actingAs($this->user)->get("/layaways/{$layaway->id}");

        $response->assertStatus(200);
    }

    public function test_layaways_create_route_takes_priority_over_dynamic_route(): void
    {
        $response = $this->actingAs($this->user)->get('/layaways/create');

        $response->assertStatus(200);
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('layaways/CreateWizard'));
    }

    // ==================== Repair Routes ====================

    public function test_repairs_index_route_is_accessible(): void
    {
        $response = $this->actingAs($this->user)->get('/repairs');

        $response->assertStatus(200);
    }

    public function test_repairs_create_route_is_accessible(): void
    {
        $response = $this->actingAs($this->user)->get('/repairs/create');

        $response->assertStatus(200);
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('repairs/CreateWizard'));
    }

    public function test_repairs_show_route_is_accessible(): void
    {
        $customer = Customer::factory()->create(['store_id' => $this->store->id]);
        $repair = Repair::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
        ]);

        $response = $this->actingAs($this->user)->get("/repairs/{$repair->id}");

        $response->assertStatus(200);
    }

    public function test_repairs_create_route_takes_priority_over_dynamic_route(): void
    {
        $response = $this->actingAs($this->user)->get('/repairs/create');

        $response->assertStatus(200);
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('repairs/CreateWizard'));
    }

    // ==================== Template Routes ====================

    public function test_templates_index_route_is_accessible(): void
    {
        $response = $this->actingAs($this->user)->get('/templates');

        $response->assertStatus(200);
    }

    public function test_templates_create_route_is_accessible(): void
    {
        $response = $this->actingAs($this->user)->get('/templates/create');

        $response->assertStatus(200);
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('templates/Create'));
    }

    public function test_templates_show_route_is_accessible(): void
    {
        $template = ProductTemplate::factory()->create(['store_id' => $this->store->id]);

        $response = $this->actingAs($this->user)->get("/templates/{$template->id}");

        $response->assertStatus(200);
    }

    public function test_templates_create_route_takes_priority_over_dynamic_route(): void
    {
        $response = $this->actingAs($this->user)->get('/templates/create');

        $response->assertStatus(200);
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('templates/Create'));
    }

    // ==================== Vendor Routes ====================

    public function test_vendors_index_route_is_accessible(): void
    {
        $response = $this->actingAs($this->user)->get('/vendors');

        $response->assertStatus(200);
    }

    public function test_vendors_export_route_is_accessible(): void
    {
        $response = $this->actingAs($this->user)->get('/vendors/export');

        // Export returns a download response, so 200 is expected
        $response->assertStatus(200);
    }

    public function test_vendors_show_route_is_accessible(): void
    {
        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);

        $response = $this->actingAs($this->user)->get("/vendors/{$vendor->id}");

        $response->assertStatus(200);
    }

    public function test_vendors_export_route_takes_priority_over_dynamic_route(): void
    {
        // This specifically tests that /vendors/export doesn't match /vendors/{vendor}
        $response = $this->actingAs($this->user)->get('/vendors/export');

        // Should return 200 (export response), not 404
        $response->assertStatus(200);
    }

    // ==================== Customer Routes ====================

    public function test_customers_index_route_is_accessible(): void
    {
        $response = $this->actingAs($this->user)->get('/customers');

        $response->assertStatus(200);
    }

    public function test_customers_show_route_is_accessible(): void
    {
        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        $response = $this->actingAs($this->user)->get("/customers/{$customer->id}");

        $response->assertStatus(200);
    }

    // ==================== Bucket Routes ====================

    public function test_buckets_index_route_is_accessible(): void
    {
        $response = $this->actingAs($this->user)->get('/buckets');

        $response->assertStatus(200);
    }

    public function test_buckets_search_route_is_accessible(): void
    {
        $response = $this->actingAs($this->user)->get('/buckets/search');

        $response->assertStatus(200);
    }

    public function test_buckets_show_route_is_accessible(): void
    {
        $bucket = Bucket::factory()->create(['store_id' => $this->store->id]);

        $response = $this->actingAs($this->user)->get("/buckets/{$bucket->id}");

        $response->assertStatus(200);
    }

    public function test_buckets_search_route_takes_priority_over_dynamic_route(): void
    {
        $response = $this->actingAs($this->user)->get('/buckets/search');

        $response->assertStatus(200);
    }

    // ==================== Warehouse Routes ====================

    public function test_warehouses_index_route_is_accessible(): void
    {
        $response = $this->actingAs($this->user)->get('/warehouses');

        $response->assertStatus(200);
    }

    public function test_warehouses_create_route_is_accessible(): void
    {
        $response = $this->actingAs($this->user)->get('/warehouses/create');

        $response->assertStatus(200);
    }

    // ==================== Report Routes ====================

    public function test_sales_reports_daily_route_is_accessible(): void
    {
        $response = $this->actingAs($this->user)->get('/reports/sales/daily');

        $response->assertStatus(200);
    }

    public function test_inventory_reports_route_is_accessible(): void
    {
        $response = $this->actingAs($this->user)->get('/reports/inventory');

        $response->assertStatus(200);
    }

    // ==================== Integration Routes ====================

    public function test_integrations_route_is_accessible(): void
    {
        $response = $this->actingAs($this->user)->get('/integrations');

        $response->assertStatus(200);
    }

    // ==================== Dashboard Route ====================

    public function test_dashboard_route_is_accessible(): void
    {
        $response = $this->actingAs($this->user)->get('/dashboard');

        $response->assertStatus(200);
    }

    // ==================== Permission Denial Tests ====================

    public function test_user_without_permission_cannot_access_products_create(): void
    {
        $limitedRole = Role::factory()->create([
            'store_id' => $this->store->id,
            'permissions' => ['products.view'], // No create permission
        ]);
        $this->storeUser->update(['role_id' => $limitedRole->id, 'is_owner' => false]);

        $response = $this->actingAs($this->user)->get('/products/create');

        $response->assertStatus(403);
    }

    public function test_user_without_permission_cannot_access_orders_create(): void
    {
        $limitedRole = Role::factory()->create([
            'store_id' => $this->store->id,
            'permissions' => ['orders.view'], // No create permission
        ]);
        $this->storeUser->update(['role_id' => $limitedRole->id, 'is_owner' => false]);

        $response = $this->actingAs($this->user)->get('/orders/create');

        $response->assertStatus(403);
    }

    public function test_user_without_permission_cannot_access_reports(): void
    {
        $limitedRole = Role::factory()->create([
            'store_id' => $this->store->id,
            'permissions' => ['products.view'], // No reports permission
        ]);
        $this->storeUser->update(['role_id' => $limitedRole->id, 'is_owner' => false]);

        $response = $this->actingAs($this->user)->get('/reports/sales/daily');

        $response->assertStatus(403);
    }
}
