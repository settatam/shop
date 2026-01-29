<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
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

class VendorTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create([
            'user_id' => $this->user->id,
            'step' => 2, // Mark onboarding as complete
        ]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_can_list_vendors(): void
    {
        Passport::actingAs($this->user);

        Vendor::factory()->count(3)->create(['store_id' => $this->store->id]);

        $response = $this->getJson('/api/v1/vendors');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_can_create_vendor(): void
    {
        Passport::actingAs($this->user);

        $response = $this->postJson('/api/v1/vendors', [
            'name' => 'Acme Supplies',
            'code' => 'ACME-001',
            'company_name' => 'Acme Corporation',
            'email' => 'sales@acme.com',
            'phone' => '555-123-4567',
            'address_line1' => '123 Main St',
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10001',
            'country' => 'US',
            'payment_terms' => Vendor::PAYMENT_TERMS_NET_30,
            'lead_time_days' => 7,
            'is_active' => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'name' => 'Acme Supplies',
                'code' => 'ACME-001',
                'company_name' => 'Acme Corporation',
            ]);

        $this->assertDatabaseHas('vendors', [
            'store_id' => $this->store->id,
            'name' => 'Acme Supplies',
            'code' => 'ACME-001',
        ]);
    }

    public function test_can_show_vendor(): void
    {
        Passport::actingAs($this->user);

        $vendor = Vendor::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Test Vendor',
        ]);

        $response = $this->getJson("/api/v1/vendors/{$vendor->id}");

        $response->assertStatus(200)
            ->assertJsonFragment([
                'name' => 'Test Vendor',
            ]);
    }

    public function test_can_update_vendor(): void
    {
        Passport::actingAs($this->user);

        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);

        $response = $this->putJson("/api/v1/vendors/{$vendor->id}", [
            'name' => 'Updated Vendor',
            'city' => 'Los Angeles',
            'payment_terms' => Vendor::PAYMENT_TERMS_NET_45,
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'name' => 'Updated Vendor',
                'city' => 'Los Angeles',
                'payment_terms' => Vendor::PAYMENT_TERMS_NET_45,
            ]);
    }

    public function test_can_delete_vendor_without_purchase_orders(): void
    {
        Passport::actingAs($this->user);

        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);

        $response = $this->deleteJson("/api/v1/vendors/{$vendor->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted('vendors', ['id' => $vendor->id]);
    }

    public function test_cannot_delete_vendor_with_open_purchase_orders(): void
    {
        Passport::actingAs($this->user);

        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $warehouse = Warehouse::factory()->create(['store_id' => $this->store->id]);

        PurchaseOrder::factory()->submitted()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
            'warehouse_id' => $warehouse->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->deleteJson("/api/v1/vendors/{$vendor->id}");

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Cannot delete vendor with open purchase orders',
            ]);

        $this->assertDatabaseHas('vendors', ['id' => $vendor->id, 'deleted_at' => null]);
    }

    public function test_can_delete_vendor_with_closed_purchase_orders(): void
    {
        Passport::actingAs($this->user);

        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $warehouse = Warehouse::factory()->create(['store_id' => $this->store->id]);

        PurchaseOrder::factory()->closed()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
            'warehouse_id' => $warehouse->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->deleteJson("/api/v1/vendors/{$vendor->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted('vendors', ['id' => $vendor->id]);
    }

    public function test_can_attach_product_to_vendor(): void
    {
        Passport::actingAs($this->user);

        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $response = $this->postJson("/api/v1/vendors/{$vendor->id}/products", [
            'product_variant_id' => $variant->id,
            'vendor_sku' => 'VENDOR-SKU-123',
            'cost' => 49.99,
            'lead_time_days' => 5,
            'minimum_order_qty' => 10,
            'is_preferred' => true,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('product_vendor', [
            'vendor_id' => $vendor->id,
            'product_variant_id' => $variant->id,
            'vendor_sku' => 'VENDOR-SKU-123',
            'cost' => 49.99,
            'is_preferred' => true,
        ]);
    }

    public function test_can_detach_product_from_vendor(): void
    {
        Passport::actingAs($this->user);

        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $vendor->productVariants()->attach($variant->id, [
            'vendor_sku' => 'TEST-SKU',
            'cost' => 25.00,
        ]);

        $response = $this->deleteJson("/api/v1/vendors/{$vendor->id}/products/{$variant->id}");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('product_vendor', [
            'vendor_id' => $vendor->id,
            'product_variant_id' => $variant->id,
        ]);
    }

    public function test_can_list_vendor_products(): void
    {
        Passport::actingAs($this->user);

        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant1 = ProductVariant::factory()->create(['product_id' => $product->id]);
        $variant2 = ProductVariant::factory()->create(['product_id' => $product->id]);

        $vendor->productVariants()->attach($variant1->id, ['vendor_sku' => 'SKU-1', 'cost' => 10.00]);
        $vendor->productVariants()->attach($variant2->id, ['vendor_sku' => 'SKU-2', 'cost' => 20.00]);

        $response = $this->getJson("/api/v1/vendors/{$vendor->id}/products");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_vendor_code_must_be_unique_per_store(): void
    {
        Passport::actingAs($this->user);

        Vendor::factory()->create([
            'store_id' => $this->store->id,
            'code' => 'UNIQUE-CODE',
        ]);

        $response = $this->postJson('/api/v1/vendors', [
            'name' => 'Another Vendor',
            'code' => 'UNIQUE-CODE',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_vendor_code_can_be_reused_across_stores(): void
    {
        Passport::actingAs($this->user);

        $otherStore = Store::factory()->create(['user_id' => $this->user->id]);
        Vendor::factory()->create([
            'store_id' => $otherStore->id,
            'code' => 'SAME-CODE',
        ]);

        $response = $this->postJson('/api/v1/vendors', [
            'name' => 'My Vendor',
            'code' => 'SAME-CODE',
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'code' => 'SAME-CODE',
            ]);
    }

    public function test_cannot_access_vendor_from_another_store(): void
    {
        Passport::actingAs($this->user);

        $otherStore = Store::factory()->create();
        $vendor = Vendor::factory()->create(['store_id' => $otherStore->id]);

        $response = $this->getJson("/api/v1/vendors/{$vendor->id}");

        $response->assertStatus(404);
    }

    public function test_can_filter_vendors_by_active_status(): void
    {
        Passport::actingAs($this->user);

        Vendor::factory()->count(2)->create([
            'store_id' => $this->store->id,
            'is_active' => true,
        ]);
        Vendor::factory()->count(3)->inactive()->create([
            'store_id' => $this->store->id,
        ]);

        $response = $this->getJson('/api/v1/vendors?is_active=1');
        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');

        $response = $this->getJson('/api/v1/vendors?is_active=0');
        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_can_search_vendors(): void
    {
        Passport::actingAs($this->user);

        Vendor::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Alpha Supplies',
        ]);
        Vendor::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Beta Products',
        ]);
        Vendor::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Gamma Goods',
        ]);

        $response = $this->getJson('/api/v1/vendors?search=alpha');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['name' => 'Alpha Supplies']);
    }

    public function test_preferred_vendor_is_unset_when_new_preferred_is_assigned(): void
    {
        Passport::actingAs($this->user);

        $vendor1 = Vendor::factory()->create(['store_id' => $this->store->id]);
        $vendor2 = Vendor::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        // Attach first vendor as preferred
        $vendor1->productVariants()->attach($variant->id, [
            'vendor_sku' => 'SKU-V1',
            'cost' => 10.00,
            'is_preferred' => true,
        ]);

        // Attach second vendor as preferred
        $this->postJson("/api/v1/vendors/{$vendor2->id}/products", [
            'product_variant_id' => $variant->id,
            'vendor_sku' => 'SKU-V2',
            'cost' => 12.00,
            'is_preferred' => true,
        ]);

        // First vendor should no longer be preferred
        $this->assertDatabaseHas('product_vendor', [
            'vendor_id' => $vendor1->id,
            'product_variant_id' => $variant->id,
            'is_preferred' => false,
        ]);

        // Second vendor should be preferred
        $this->assertDatabaseHas('product_vendor', [
            'vendor_id' => $vendor2->id,
            'product_variant_id' => $variant->id,
            'is_preferred' => true,
        ]);
    }

    public function test_web_can_list_vendors(): void
    {
        $this->actingAs($this->user);

        Vendor::factory()->count(3)->create(['store_id' => $this->store->id]);

        $response = $this->get('/vendors');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('vendors/Index')
                ->has('vendors.data', 3)
            );
    }

    public function test_web_can_show_vendor(): void
    {
        $this->actingAs($this->user);

        $vendor = Vendor::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Test Vendor',
        ]);

        $response = $this->get("/vendors/{$vendor->id}");

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('vendors/Show')
                ->has('vendor')
                ->where('vendor.name', 'Test Vendor')
            );
    }

    public function test_web_can_create_vendor(): void
    {
        $this->actingAs($this->user);

        $response = $this->post('/vendors', [
            'name' => 'Web Created Vendor',
            'email' => 'web@vendor.com',
            'payment_terms' => Vendor::PAYMENT_TERMS_NET_30,
        ]);

        $response->assertRedirect(route('web.vendors.index'));

        $this->assertDatabaseHas('vendors', [
            'store_id' => $this->store->id,
            'name' => 'Web Created Vendor',
        ]);
    }

    public function test_web_can_update_vendor(): void
    {
        $this->actingAs($this->user);

        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);

        $response = $this->put("/vendors/{$vendor->id}", [
            'name' => 'Updated via Web',
            'email' => 'updated@vendor.com',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('vendors', [
            'id' => $vendor->id,
            'name' => 'Updated via Web',
        ]);
    }

    public function test_web_can_delete_vendor(): void
    {
        $this->actingAs($this->user);

        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);

        $response = $this->delete("/vendors/{$vendor->id}");

        $response->assertRedirect(route('web.vendors.index'));
        $this->assertSoftDeleted('vendors', ['id' => $vendor->id]);
    }
}
