<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected Vendor $vendor;

    protected Warehouse $warehouse;

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

        $this->vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $this->warehouse = Warehouse::factory()->create(['store_id' => $this->store->id]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_can_list_purchase_orders(): void
    {
        Passport::actingAs($this->user);

        PurchaseOrder::factory()->count(3)->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson('/api/v1/purchase-orders');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_can_filter_purchase_orders_by_status(): void
    {
        Passport::actingAs($this->user);

        PurchaseOrder::factory()->count(2)->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
        ]);
        PurchaseOrder::factory()->submitted()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson('/api/v1/purchase-orders?status=draft');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_can_create_purchase_order_with_items(): void
    {
        Passport::actingAs($this->user);

        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $response = $this->postJson('/api/v1/purchase-orders', [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->format('Y-m-d'),
            'expected_date' => now()->addDays(7)->format('Y-m-d'),
            'shipping_method' => 'Ground',
            'vendor_notes' => 'Please ship ASAP',
            'items' => [
                [
                    'product_variant_id' => $variant->id,
                    'vendor_sku' => 'VENDOR-SKU-1',
                    'quantity_ordered' => 10,
                    'unit_cost' => 25.00,
                ],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'status' => PurchaseOrder::STATUS_DRAFT,
                'vendor_notes' => 'Please ship ASAP',
            ]);

        $this->assertDatabaseHas('purchase_orders', [
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
        ]);

        $this->assertDatabaseHas('purchase_order_items', [
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 10,
            'unit_cost' => 25.00,
        ]);
    }

    public function test_can_show_purchase_order(): void
    {
        Passport::actingAs($this->user);

        $po = PurchaseOrder::factory()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson("/api/v1/purchase-orders/{$po->id}");

        $response->assertStatus(200)
            ->assertJsonFragment([
                'id' => $po->id,
                'po_number' => $po->po_number,
            ]);
    }

    public function test_can_update_draft_purchase_order(): void
    {
        Passport::actingAs($this->user);

        $po = PurchaseOrder::factory()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
        ]);

        $response = $this->putJson("/api/v1/purchase-orders/{$po->id}", [
            'vendor_notes' => 'Updated notes',
            'shipping_method' => 'Express',
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'vendor_notes' => 'Updated notes',
                'shipping_method' => 'Express',
            ]);
    }

    public function test_cannot_update_non_draft_purchase_order(): void
    {
        Passport::actingAs($this->user);

        $po = PurchaseOrder::factory()->submitted()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->putJson("/api/v1/purchase-orders/{$po->id}", [
            'vendor_notes' => 'Should not update',
        ]);

        $response->assertStatus(403);
    }

    public function test_can_delete_draft_purchase_order(): void
    {
        Passport::actingAs($this->user);

        $po = PurchaseOrder::factory()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
        ]);

        $response = $this->deleteJson("/api/v1/purchase-orders/{$po->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted('purchase_orders', ['id' => $po->id]);
    }

    public function test_cannot_delete_non_draft_purchase_order(): void
    {
        Passport::actingAs($this->user);

        $po = PurchaseOrder::factory()->submitted()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->deleteJson("/api/v1/purchase-orders/{$po->id}");

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Only draft purchase orders can be deleted',
            ]);
    }

    public function test_can_add_item_to_purchase_order(): void
    {
        Passport::actingAs($this->user);

        $po = PurchaseOrder::factory()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
        ]);

        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $response = $this->postJson("/api/v1/purchase-orders/{$po->id}/items", [
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 5,
            'unit_cost' => 15.00,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('purchase_order_items', [
            'purchase_order_id' => $po->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 5,
        ]);
    }

    public function test_can_update_purchase_order_item(): void
    {
        Passport::actingAs($this->user);

        $po = PurchaseOrder::factory()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
        ]);

        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $item = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 10,
            'unit_cost' => 20.00,
        ]);

        $response = $this->putJson("/api/v1/purchase-orders/{$po->id}/items/{$item->id}", [
            'quantity_ordered' => 15,
            'unit_cost' => 18.00,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('purchase_order_items', [
            'id' => $item->id,
            'quantity_ordered' => 15,
            'unit_cost' => 18.00,
        ]);
    }

    public function test_can_remove_item_from_purchase_order(): void
    {
        Passport::actingAs($this->user);

        $po = PurchaseOrder::factory()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
        ]);

        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $item = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'product_variant_id' => $variant->id,
        ]);

        $response = $this->deleteJson("/api/v1/purchase-orders/{$po->id}/items/{$item->id}");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('purchase_order_items', ['id' => $item->id]);
    }

    public function test_can_submit_draft_purchase_order(): void
    {
        Passport::actingAs($this->user);

        $po = PurchaseOrder::factory()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
        ]);

        $response = $this->postJson("/api/v1/purchase-orders/{$po->id}/submit");

        $response->assertStatus(200)
            ->assertJsonFragment([
                'status' => PurchaseOrder::STATUS_SUBMITTED,
            ]);

        $this->assertNotNull($po->fresh()->submitted_at);
    }

    public function test_cannot_submit_non_draft_purchase_order(): void
    {
        Passport::actingAs($this->user);

        $po = PurchaseOrder::factory()->submitted()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson("/api/v1/purchase-orders/{$po->id}/submit");

        $response->assertStatus(422);
    }

    public function test_can_approve_submitted_purchase_order(): void
    {
        Passport::actingAs($this->user);

        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $po = PurchaseOrder::factory()->submitted()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
        ]);

        PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 10,
        ]);

        $response = $this->postJson("/api/v1/purchase-orders/{$po->id}/approve");

        $response->assertStatus(200)
            ->assertJsonFragment([
                'status' => PurchaseOrder::STATUS_APPROVED,
            ]);

        $this->assertNotNull($po->fresh()->approved_at);
        $this->assertEquals($this->user->id, $po->fresh()->approved_by);
    }

    public function test_approve_increases_incoming_quantity(): void
    {
        Passport::actingAs($this->user);

        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $po = PurchaseOrder::factory()->submitted()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
        ]);

        PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 25,
        ]);

        $this->postJson("/api/v1/purchase-orders/{$po->id}/approve");

        $inventory = Inventory::where('product_variant_id', $variant->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();

        $this->assertNotNull($inventory);
        $this->assertEquals(25, $inventory->incoming_quantity);
    }

    public function test_cannot_approve_non_submitted_purchase_order(): void
    {
        Passport::actingAs($this->user);

        $po = PurchaseOrder::factory()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
        ]);

        $response = $this->postJson("/api/v1/purchase-orders/{$po->id}/approve");

        $response->assertStatus(422);
    }

    public function test_can_cancel_draft_purchase_order(): void
    {
        Passport::actingAs($this->user);

        $po = PurchaseOrder::factory()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
        ]);

        $response = $this->postJson("/api/v1/purchase-orders/{$po->id}/cancel");

        $response->assertStatus(200)
            ->assertJsonFragment([
                'status' => PurchaseOrder::STATUS_CANCELLED,
            ]);
    }

    public function test_can_cancel_approved_purchase_order(): void
    {
        Passport::actingAs($this->user);

        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        // Create inventory with incoming quantity
        Inventory::create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 0,
            'reserved_quantity' => 0,
            'incoming_quantity' => 10,
        ]);

        $po = PurchaseOrder::factory()->approved()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'approved_by' => $this->user->id,
        ]);

        PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 10,
            'quantity_received' => 0,
        ]);

        $response = $this->postJson("/api/v1/purchase-orders/{$po->id}/cancel");

        $response->assertStatus(200);

        // Verify incoming quantity was decreased
        $inventory = Inventory::where('product_variant_id', $variant->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();

        $this->assertEquals(0, $inventory->incoming_quantity);
    }

    public function test_cannot_cancel_received_purchase_order(): void
    {
        Passport::actingAs($this->user);

        $po = PurchaseOrder::factory()->received()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson("/api/v1/purchase-orders/{$po->id}/cancel");

        $response->assertStatus(422);
    }

    public function test_can_close_partial_purchase_order(): void
    {
        Passport::actingAs($this->user);

        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        Inventory::create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 5,
            'reserved_quantity' => 0,
            'incoming_quantity' => 5,
        ]);

        $po = PurchaseOrder::factory()->partial()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
        ]);

        PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 10,
            'quantity_received' => 5,
        ]);

        $response = $this->postJson("/api/v1/purchase-orders/{$po->id}/close");

        $response->assertStatus(200)
            ->assertJsonFragment([
                'status' => PurchaseOrder::STATUS_CLOSED,
            ]);

        // Verify remaining incoming quantity was removed
        $inventory = Inventory::where('product_variant_id', $variant->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();

        $this->assertEquals(0, $inventory->incoming_quantity);
    }

    public function test_cannot_close_draft_purchase_order(): void
    {
        Passport::actingAs($this->user);

        $po = PurchaseOrder::factory()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
        ]);

        $response = $this->postJson("/api/v1/purchase-orders/{$po->id}/close");

        $response->assertStatus(422);
    }

    public function test_cannot_access_purchase_order_from_another_store(): void
    {
        Passport::actingAs($this->user);

        $otherStore = Store::factory()->create();
        $otherVendor = Vendor::factory()->create(['store_id' => $otherStore->id]);
        $otherWarehouse = Warehouse::factory()->create(['store_id' => $otherStore->id]);
        $otherUser = User::factory()->create();

        $po = PurchaseOrder::factory()->create([
            'store_id' => $otherStore->id,
            'vendor_id' => $otherVendor->id,
            'warehouse_id' => $otherWarehouse->id,
            'created_by' => $otherUser->id,
        ]);

        $response = $this->getJson("/api/v1/purchase-orders/{$po->id}");

        $response->assertStatus(404);
    }

    public function test_purchase_order_generates_po_number_automatically(): void
    {
        Passport::actingAs($this->user);

        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $response = $this->postJson('/api/v1/purchase-orders', [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'items' => [
                [
                    'product_variant_id' => $variant->id,
                    'quantity_ordered' => 1,
                    'unit_cost' => 10.00,
                ],
            ],
        ]);

        $response->assertStatus(201);

        $po = PurchaseOrder::latest()->first();
        $this->assertNotEmpty($po->po_number);
        $this->assertStringStartsWith('PO-', $po->po_number);
    }

    public function test_web_can_list_purchase_orders(): void
    {
        $this->actingAs($this->user);

        PurchaseOrder::factory()->count(3)->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->get('/purchase-orders');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('purchase-orders/Index')
                ->has('purchaseOrders.data', 3)
            );
    }

    public function test_web_can_show_purchase_order(): void
    {
        $this->actingAs($this->user);

        $po = PurchaseOrder::factory()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->get("/purchase-orders/{$po->id}");

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('purchase-orders/Show')
                ->has('purchaseOrder')
                ->where('purchaseOrder.id', $po->id)
            );
    }

    public function test_web_can_create_purchase_order(): void
    {
        $this->actingAs($this->user);

        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $response = $this->post('/purchase-orders', [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->format('Y-m-d'),
            'items' => [
                [
                    'product_variant_id' => $variant->id,
                    'quantity_ordered' => 10,
                    'unit_cost' => 25.00,
                ],
            ],
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('purchase_orders', [
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
        ]);
    }

    public function test_web_can_edit_draft_purchase_order(): void
    {
        $this->actingAs($this->user);

        $po = PurchaseOrder::factory()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
        ]);

        $response = $this->get("/purchase-orders/{$po->id}/edit");

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('purchase-orders/Edit')
                ->has('purchaseOrder')
            );
    }

    public function test_web_cannot_edit_non_draft_purchase_order(): void
    {
        $this->actingAs($this->user);

        $po = PurchaseOrder::factory()->submitted()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->get("/purchase-orders/{$po->id}/edit");

        $response->assertRedirect(route('web.purchase-orders.show', $po));
    }

    public function test_web_can_update_draft_purchase_order(): void
    {
        $this->actingAs($this->user);

        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $po = PurchaseOrder::factory()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
        ]);

        $response = $this->put("/purchase-orders/{$po->id}", [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'vendor_notes' => 'Updated via web',
            'items' => [
                [
                    'product_variant_id' => $variant->id,
                    'quantity_ordered' => 20,
                    'unit_cost' => 30.00,
                ],
            ],
        ]);

        $response->assertRedirect(route('web.purchase-orders.show', $po));

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $po->id,
            'vendor_notes' => 'Updated via web',
        ]);

        $this->assertDatabaseHas('purchase_order_items', [
            'purchase_order_id' => $po->id,
            'quantity_ordered' => 20,
        ]);
    }

    public function test_web_can_delete_draft_purchase_order(): void
    {
        $this->actingAs($this->user);

        $po = PurchaseOrder::factory()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
        ]);

        $response = $this->delete("/purchase-orders/{$po->id}");

        $response->assertRedirect(route('web.purchase-orders.index'));
        $this->assertSoftDeleted('purchase_orders', ['id' => $po->id]);
    }

    public function test_web_can_submit_purchase_order(): void
    {
        $this->actingAs($this->user);

        $po = PurchaseOrder::factory()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
        ]);

        $response = $this->post("/purchase-orders/{$po->id}/submit");

        $response->assertRedirect();
        $this->assertEquals(PurchaseOrder::STATUS_SUBMITTED, $po->fresh()->status);
    }

    public function test_web_can_approve_purchase_order(): void
    {
        $this->actingAs($this->user);

        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $po = PurchaseOrder::factory()->submitted()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
        ]);

        PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'product_variant_id' => $variant->id,
        ]);

        $response = $this->post("/purchase-orders/{$po->id}/approve");

        $response->assertRedirect();
        $this->assertEquals(PurchaseOrder::STATUS_APPROVED, $po->fresh()->status);
    }

    public function test_web_can_cancel_purchase_order(): void
    {
        $this->actingAs($this->user);

        $po = PurchaseOrder::factory()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
        ]);

        $response = $this->post("/purchase-orders/{$po->id}/cancel");

        $response->assertRedirect();
        $this->assertEquals(PurchaseOrder::STATUS_CANCELLED, $po->fresh()->status);
    }

    // === Receiving Tests ===

    public function test_can_receive_items_on_approved_purchase_order(): void
    {
        Passport::actingAs($this->user);

        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $po = PurchaseOrder::factory()->approved()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'approved_by' => $this->user->id,
        ]);

        $item = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 10,
            'quantity_received' => 0,
            'unit_cost' => 25.00,
        ]);

        // Create initial inventory with incoming quantity
        Inventory::create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 0,
            'reserved_quantity' => 0,
            'incoming_quantity' => 10,
        ]);

        $response = $this->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
            'notes' => 'Received in good condition',
            'items' => [
                [
                    'purchase_order_item_id' => $item->id,
                    'quantity_received' => 5,
                    'unit_cost' => 25.00,
                ],
            ],
        ]);

        $response->assertStatus(200);

        // Check receipt was created
        $this->assertDatabaseHas('purchase_order_receipts', [
            'purchase_order_id' => $po->id,
            'received_by' => $this->user->id,
            'notes' => 'Received in good condition',
        ]);

        // Check item quantity_received was updated
        $this->assertEquals(5, $item->fresh()->quantity_received);

        // Check inventory was updated
        $inventory = Inventory::where('product_variant_id', $variant->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();

        $this->assertEquals(5, $inventory->quantity);
        $this->assertEquals(5, $inventory->incoming_quantity);

        // Check PO status is partial
        $this->assertEquals(PurchaseOrder::STATUS_PARTIAL, $po->fresh()->status);
    }

    public function test_receiving_all_items_changes_status_to_received(): void
    {
        Passport::actingAs($this->user);

        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $po = PurchaseOrder::factory()->approved()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'approved_by' => $this->user->id,
        ]);

        $item = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 10,
            'quantity_received' => 0,
            'unit_cost' => 20.00,
        ]);

        Inventory::create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 0,
            'reserved_quantity' => 0,
            'incoming_quantity' => 10,
        ]);

        $response = $this->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
            'items' => [
                [
                    'purchase_order_item_id' => $item->id,
                    'quantity_received' => 10,
                ],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertEquals(PurchaseOrder::STATUS_RECEIVED, $po->fresh()->status);
    }

    public function test_cannot_receive_items_on_non_receivable_purchase_order(): void
    {
        Passport::actingAs($this->user);

        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $po = PurchaseOrder::factory()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
        ]);

        $item = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'product_variant_id' => $variant->id,
        ]);

        $response = $this->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
            'items' => [
                [
                    'purchase_order_item_id' => $item->id,
                    'quantity_received' => 5,
                ],
            ],
        ]);

        $response->assertStatus(403);
    }

    public function test_receiving_updates_weighted_average_cost(): void
    {
        Passport::actingAs($this->user);

        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $po = PurchaseOrder::factory()->approved()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'approved_by' => $this->user->id,
        ]);

        $item = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 10,
            'quantity_received' => 0,
            'unit_cost' => 30.00,
        ]);

        // Start with existing inventory at $20/unit
        Inventory::create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 5,
            'unit_cost' => 20.00,
            'reserved_quantity' => 0,
            'incoming_quantity' => 10,
        ]);

        $this->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
            'items' => [
                [
                    'purchase_order_item_id' => $item->id,
                    'quantity_received' => 5,
                    'unit_cost' => 30.00,
                ],
            ],
        ]);

        $inventory = Inventory::where('product_variant_id', $variant->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();

        // (5 * $20 + 5 * $30) / 10 = $25
        $this->assertEquals(25.00, (float) $inventory->unit_cost);
        $this->assertEquals(10, $inventory->quantity);
    }

    public function test_web_can_show_receive_page(): void
    {
        $this->actingAs($this->user);

        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $po = PurchaseOrder::factory()->approved()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'approved_by' => $this->user->id,
        ]);

        PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'product_variant_id' => $variant->id,
        ]);

        $response = $this->get("/purchase-orders/{$po->id}/receive");

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('purchase-orders/Receive')
                ->has('purchaseOrder')
            );
    }

    public function test_web_can_receive_items(): void
    {
        $this->actingAs($this->user);

        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $po = PurchaseOrder::factory()->approved()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'approved_by' => $this->user->id,
        ]);

        $item = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 10,
            'quantity_received' => 0,
            'unit_cost' => 15.00,
        ]);

        Inventory::create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 0,
            'reserved_quantity' => 0,
            'incoming_quantity' => 10,
        ]);

        $response = $this->post("/purchase-orders/{$po->id}/receive", [
            'items' => [
                [
                    'purchase_order_item_id' => $item->id,
                    'quantity_received' => 10,
                    'unit_cost' => 15.00,
                ],
            ],
        ]);

        $response->assertRedirect(route('web.purchase-orders.show', $po));
        $this->assertEquals(PurchaseOrder::STATUS_RECEIVED, $po->fresh()->status);
    }

    public function test_web_cannot_show_receive_page_for_draft_po(): void
    {
        $this->actingAs($this->user);

        $po = PurchaseOrder::factory()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
        ]);

        $response = $this->get("/purchase-orders/{$po->id}/receive");

        $response->assertRedirect(route('web.purchase-orders.show', $po));
    }
}
