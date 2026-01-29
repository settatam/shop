<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Memo;
use App\Models\MemoItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Models\Vendor;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MemoWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Store $store;

    protected Role $ownerRole;

    protected StoreUser $ownerStoreUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->store = Store::factory()->create([
            'user_id' => $this->owner->id,
            'step' => 2,
        ]);

        $this->ownerRole = Role::factory()->owner()->create(['store_id' => $this->store->id]);

        $this->ownerStoreUser = StoreUser::factory()->owner()->create([
            'user_id' => $this->owner->id,
            'store_id' => $this->store->id,
            'role_id' => $this->ownerRole->id,
        ]);

        $this->owner->update(['current_store_id' => $this->store->id]);
        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_memos_index_page_can_be_rendered(): void
    {
        $this->actingAs($this->owner);

        $response = $this->get('/memos');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('memos/Index')
            ->has('statuses')
            ->has('paymentTerms')
        );
    }

    public function test_memo_show_page_can_be_rendered(): void
    {
        $this->actingAs($this->owner);

        $memo = Memo::factory()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->owner->id,
        ]);

        $response = $this->get("/memos/{$memo->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('memos/Show')
            ->has('memo')
            ->has('statuses')
            ->has('paymentTerms')
            ->has('paymentMethods')
        );
    }

    public function test_memo_create_wizard_page_can_be_rendered(): void
    {
        $this->actingAs($this->owner);

        $response = $this->get('/memos/create');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('memos/CreateWizard')
            ->has('storeUsers')
            ->has('currentStoreUserId')
            ->has('categories')
            ->has('paymentTerms')
        );
    }

    public function test_memo_can_be_created_with_existing_vendor(): void
    {
        $this->actingAs($this->owner);

        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'quantity' => 1,
        ]);

        $response = $this->post('/memos', [
            'store_user_id' => $this->ownerStoreUser->id,
            'vendor_id' => $vendor->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'price' => 500.00,
                    'tenor' => 30,
                ],
            ],
            'tenure' => 30,
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('memos', [
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
            'status' => Memo::STATUS_PENDING,
        ]);

        // Product should be marked as out of stock
        $product->refresh();
        $this->assertEquals(0, $product->quantity);
    }

    public function test_memo_can_be_created_with_new_vendor(): void
    {
        $this->actingAs($this->owner);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'quantity' => 1,
        ]);

        $response = $this->post('/memos', [
            'store_user_id' => $this->ownerStoreUser->id,
            'vendor' => [
                'name' => 'John Doe',
                'company_name' => 'Test Company',
                'email' => 'john@example.com',
            ],
            'items' => [
                [
                    'product_id' => $product->id,
                    'price' => 500.00,
                    'tenor' => 30,
                ],
            ],
            'tenure' => 30,
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('vendors', [
            'store_id' => $this->store->id,
            'name' => 'John Doe',
        ]);

        $this->assertDatabaseHas('memos', [
            'store_id' => $this->store->id,
            'status' => Memo::STATUS_PENDING,
        ]);
    }

    public function test_memo_can_be_sent_to_vendor(): void
    {
        $this->actingAs($this->owner);

        $memo = Memo::factory()->pending()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->owner->id,
        ]);

        MemoItem::factory()->create(['memo_id' => $memo->id]);

        $response = $this->post("/memos/{$memo->id}/send-to-vendor");

        $response->assertRedirect();

        $memo->refresh();
        $this->assertEquals(Memo::STATUS_SENT_TO_VENDOR, $memo->status);
    }

    public function test_memo_can_be_marked_as_received(): void
    {
        $this->actingAs($this->owner);

        $memo = Memo::factory()->sentToVendor()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->owner->id,
        ]);

        $response = $this->post("/memos/{$memo->id}/mark-received");

        $response->assertRedirect();

        $memo->refresh();
        $this->assertEquals(Memo::STATUS_VENDOR_RECEIVED, $memo->status);
    }

    public function test_memo_item_can_be_returned_to_stock(): void
    {
        $this->actingAs($this->owner);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'quantity' => 0, // On memo
        ]);

        $memo = Memo::factory()->vendorReceived()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->owner->id,
        ]);

        $item = MemoItem::factory()->create([
            'memo_id' => $memo->id,
            'product_id' => $product->id,
            'is_returned' => false,
        ]);

        $response = $this->post("/memos/{$memo->id}/return-item/{$item->id}");

        $response->assertRedirect();

        $item->refresh();
        $product->refresh();

        $this->assertTrue($item->is_returned);
        $this->assertEquals(1, $product->quantity);
    }

    public function test_memo_can_be_cancelled(): void
    {
        $this->actingAs($this->owner);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'quantity' => 0, // On memo
        ]);

        $memo = Memo::factory()->pending()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->owner->id,
        ]);

        $item = MemoItem::factory()->create([
            'memo_id' => $memo->id,
            'product_id' => $product->id,
            'is_returned' => false,
        ]);

        $response = $this->post("/memos/{$memo->id}/cancel");

        $response->assertRedirect();

        $memo->refresh();
        $product->refresh();
        $item->refresh();

        $this->assertEquals(Memo::STATUS_CANCELLED, $memo->status);
        $this->assertTrue($item->is_returned);
        $this->assertEquals(1, $product->quantity);
    }

    public function test_pending_memo_can_be_deleted(): void
    {
        $this->actingAs($this->owner);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'quantity' => 0,
        ]);

        $memo = Memo::factory()->pending()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->owner->id,
        ]);

        MemoItem::factory()->create([
            'memo_id' => $memo->id,
            'product_id' => $product->id,
            'is_returned' => false,
        ]);

        $response = $this->delete("/memos/{$memo->id}");

        $response->assertRedirect('/memos');

        $this->assertSoftDeleted('memos', ['id' => $memo->id]);

        // Product should be back in stock
        $product->refresh();
        $this->assertEquals(1, $product->quantity);
    }

    public function test_non_pending_memo_cannot_be_deleted(): void
    {
        $this->actingAs($this->owner);

        $memo = Memo::factory()->sentToVendor()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->owner->id,
        ]);

        $response = $this->delete("/memos/{$memo->id}");

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->assertDatabaseHas('memos', ['id' => $memo->id, 'deleted_at' => null]);
    }

    public function test_cannot_access_memo_from_different_store(): void
    {
        $this->actingAs($this->owner);

        $otherStore = Store::factory()->create();
        $memo = Memo::factory()->create(['store_id' => $otherStore->id]);

        $response = $this->get("/memos/{$memo->id}");

        $response->assertStatus(404);
    }

    public function test_memo_status_helpers_work_correctly(): void
    {
        $memo = Memo::factory()->pending()->create();
        $this->assertTrue($memo->isPending());
        $this->assertFalse($memo->isSentToVendor());

        $memo->status = Memo::STATUS_SENT_TO_VENDOR;
        $this->assertTrue($memo->isSentToVendor());
        $this->assertTrue($memo->canBeMarkedAsReceived());

        $memo->status = Memo::STATUS_CANCELLED;
        $this->assertTrue($memo->isCancelled());
        $this->assertFalse($memo->canBeCancelled());
    }

    public function test_days_with_vendor_is_calculated_correctly(): void
    {
        $memo = Memo::factory()->create([
            'created_at' => now()->subDays(10),
        ]);

        $this->assertEquals(10, $memo->days_with_vendor);
    }

    public function test_search_products_returns_price_from_variant(): void
    {
        $this->actingAs($this->owner);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Test Diamond Ring',
            'quantity' => 1,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'RING-001',
            'price' => 1500.00,
            'cost' => 800.00,
        ]);

        $response = $this->getJson('/memos/search-products?query=Diamond');

        $response->assertStatus(200)
            ->assertJsonPath('products.0.id', $product->id)
            ->assertJsonPath('products.0.title', 'Test Diamond Ring')
            ->assertJsonPath('products.0.sku', 'RING-001')
            ->assertJsonPath('products.0.price', '1500.00')
            ->assertJsonPath('products.0.cost', '800.00');
    }

    public function test_search_products_can_search_by_variant_sku(): void
    {
        $this->actingAs($this->owner);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Gold Necklace',
            'quantity' => 1,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'NECK-UNIQUE-123',
            'price' => 2000.00,
        ]);

        $response = $this->getJson('/memos/search-products?query=NECK-UNIQUE');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.id', $product->id)
            ->assertJsonPath('products.0.sku', 'NECK-UNIQUE-123');
    }

    public function test_quick_product_can_be_created(): void
    {
        $this->actingAs($this->owner);

        $category = Category::factory()->create(['store_id' => $this->store->id]);

        $response = $this->postJson('/memos/create-product', [
            'title' => 'Quick Test Product',
            'sku' => 'QTP-001',
            'price' => 500.00,
            'cost' => 250.00,
            'category_id' => $category->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('product.title', 'Quick Test Product')
            ->assertJsonPath('product.sku', 'QTP-001')
            ->assertJsonPath('product.price', '500.00')
            ->assertJsonPath('product.cost', '250.00');

        $this->assertDatabaseHas('products', [
            'store_id' => $this->store->id,
            'title' => 'Quick Test Product',
            'is_draft' => true,
            'is_published' => false,
        ]);

        $this->assertDatabaseHas('product_variants', [
            'sku' => 'QTP-001',
            'price' => 500.00,
            'cost' => 250.00,
        ]);
    }

    public function test_quick_product_generates_sku_if_not_provided(): void
    {
        $this->actingAs($this->owner);

        $response = $this->postJson('/memos/create-product', [
            'title' => 'No SKU Product',
            'price' => 100.00,
        ]);

        $response->assertStatus(201);

        $sku = $response->json('product.sku');
        $this->assertStringStartsWith('SKU-', $sku);
        $this->assertEquals(12, strlen($sku)); // SKU- + 8 characters
    }

    public function test_quick_product_validates_required_fields(): void
    {
        $this->actingAs($this->owner);

        $response = $this->postJson('/memos/create-product', [
            'price' => 100.00,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['title']);

        $response = $this->postJson('/memos/create-product', [
            'title' => 'Missing Price',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['price']);
    }

    public function test_cannot_create_memo_with_duplicate_vendor_email(): void
    {
        $this->actingAs($this->owner);

        // Create existing vendor with email
        Vendor::factory()->create([
            'store_id' => $this->store->id,
            'email' => 'existing@vendor.com',
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'quantity' => 1,
        ]);

        $response = $this->post('/memos', [
            'store_user_id' => $this->ownerStoreUser->id,
            'vendor' => [
                'name' => 'New Vendor',
                'email' => 'existing@vendor.com',
            ],
            'items' => [
                [
                    'product_id' => $product->id,
                    'price' => 500.00,
                ],
            ],
            'tenure' => 30,
        ]);

        $response->assertSessionHasErrors(['vendor.email']);
    }

    public function test_vendor_phone_number_is_formatted(): void
    {
        $this->actingAs($this->owner);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'quantity' => 1,
        ]);

        $response = $this->post('/memos', [
            'store_user_id' => $this->ownerStoreUser->id,
            'vendor' => [
                'name' => 'Phone Test Vendor',
                'phone' => '1234567890',
            ],
            'items' => [
                [
                    'product_id' => $product->id,
                    'price' => 500.00,
                ],
            ],
            'tenure' => 30,
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('vendors', [
            'store_id' => $this->store->id,
            'name' => 'Phone Test Vendor',
            'phone' => '(123) 456-7890',
        ]);
    }

    public function test_vendor_phone_with_country_code_is_formatted(): void
    {
        $this->actingAs($this->owner);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'quantity' => 1,
        ]);

        $response = $this->post('/memos', [
            'store_user_id' => $this->ownerStoreUser->id,
            'vendor' => [
                'name' => 'Country Code Vendor',
                'phone' => '11234567890',
            ],
            'items' => [
                [
                    'product_id' => $product->id,
                    'price' => 500.00,
                ],
            ],
            'tenure' => 30,
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('vendors', [
            'store_id' => $this->store->id,
            'name' => 'Country Code Vendor',
            'phone' => '(123) 456-7890',
        ]);
    }

    public function test_receive_payment_creates_invoice(): void
    {
        $this->actingAs($this->owner);

        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $memo = Memo::factory()->vendorReceived()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->owner->id,
            'vendor_id' => $vendor->id,
            'subtotal' => 1000,
            'tax' => 100,
            'total' => 1100,
        ]);

        MemoItem::factory()->create([
            'memo_id' => $memo->id,
            'price' => 1000,
        ]);

        $response = $this->post("/memos/{$memo->id}/receive-payment", [
            'payment_method' => 'cash',
        ]);

        $response->assertRedirect();

        $memo->refresh();
        $this->assertEquals(Memo::STATUS_PAYMENT_RECEIVED, $memo->status);

        // Verify invoice was created
        $this->assertDatabaseHas('invoices', [
            'store_id' => $this->store->id,
            'invoiceable_type' => Memo::class,
            'invoiceable_id' => $memo->id,
            'status' => 'paid',
        ]);

        // Verify memo has invoice relationship
        $this->assertNotNull($memo->invoice);
        $this->assertEquals('paid', $memo->invoice->status);
    }
}
