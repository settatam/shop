<?php

namespace Tests\Feature;

use App\Models\Memo;
use App\Models\MemoItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Memos\MemoService;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class MemoTest extends TestCase
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

    public function test_can_list_memos(): void
    {
        Passport::actingAs($this->user);

        Memo::factory()->count(3)->create(['store_id' => $this->store->id]);

        $response = $this->getJson('/api/v1/memos');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_can_filter_memos_by_status(): void
    {
        Passport::actingAs($this->user);

        Memo::factory()->pending()->count(2)->create(['store_id' => $this->store->id]);
        Memo::factory()->sentToVendor()->count(3)->create(['store_id' => $this->store->id]);

        $response = $this->getJson('/api/v1/memos?status=pending');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_can_filter_memos_by_vendor(): void
    {
        Passport::actingAs($this->user);

        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $otherVendor = Vendor::factory()->create(['store_id' => $this->store->id]);

        Memo::factory()->count(2)->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
        ]);
        Memo::factory()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $otherVendor->id,
        ]);

        $response = $this->getJson("/api/v1/memos?vendor_id={$vendor->id}");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_can_filter_memos_by_tenure(): void
    {
        Passport::actingAs($this->user);

        Memo::factory()->count(2)->create([
            'store_id' => $this->store->id,
            'tenure' => 30,
        ]);
        Memo::factory()->create([
            'store_id' => $this->store->id,
            'tenure' => 60,
        ]);

        $response = $this->getJson('/api/v1/memos?tenure=30');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_can_create_memo_via_api(): void
    {
        Passport::actingAs($this->user);

        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create(['store_id' => $this->store->id]);

        $response = $this->postJson('/api/v1/memos', [
            'vendor_id' => $vendor->id,
            'tenure' => 30,
            'tax_rate' => 0.08,
            'description' => 'Consignment for summer collection',
            'items' => [
                [
                    'product_id' => $product->id,
                    'title' => 'Diamond Earrings',
                    'price' => 500.00,
                    'cost' => 300.00,
                ],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', Memo::STATUS_PENDING)
            ->assertJsonPath('data.vendor_id', $vendor->id)
            ->assertJsonPath('data.tenure', 30);

        $this->assertDatabaseHas('memos', [
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
            'status' => Memo::STATUS_PENDING,
            'tenure' => 30,
        ]);

        $this->assertDatabaseHas('memo_items', [
            'title' => 'Diamond Earrings',
            'price' => '500.00',
            'cost' => '300.00',
        ]);
    }

    public function test_can_show_memo_details(): void
    {
        Passport::actingAs($this->user);

        $memo = Memo::factory()->create(['store_id' => $this->store->id]);
        MemoItem::factory()->count(2)->create(['memo_id' => $memo->id]);

        $response = $this->getJson("/api/v1/memos/{$memo->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $memo->id)
            ->assertJsonCount(2, 'data.items');
    }

    public function test_can_update_memo(): void
    {
        Passport::actingAs($this->user);

        $memo = Memo::factory()->pending()->create(['store_id' => $this->store->id]);

        $response = $this->putJson("/api/v1/memos/{$memo->id}", [
            'description' => 'Updated description',
            'tenure' => 60,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.description', 'Updated description')
            ->assertJsonPath('data.tenure', 60);
    }

    public function test_can_add_item_to_memo(): void
    {
        Passport::actingAs($this->user);

        $memo = Memo::factory()->pending()->create(['store_id' => $this->store->id]);

        $response = $this->postJson("/api/v1/memos/{$memo->id}/items", [
            'title' => 'Gold Bracelet',
            'price' => 400.00,
            'cost' => 250.00,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Item added successfully.');

        $this->assertDatabaseHas('memo_items', [
            'memo_id' => $memo->id,
            'title' => 'Gold Bracelet',
        ]);
    }

    public function test_can_remove_memo_item(): void
    {
        Passport::actingAs($this->user);

        $memo = Memo::factory()->pending()->create(['store_id' => $this->store->id]);
        $item = MemoItem::factory()->create(['memo_id' => $memo->id]);

        $response = $this->deleteJson("/api/v1/memos/{$memo->id}/items/{$item->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Item removed successfully.');

        $this->assertSoftDeleted('memo_items', ['id' => $item->id]);
    }

    public function test_can_send_memo_to_vendor(): void
    {
        Passport::actingAs($this->user);

        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $memo = Memo::factory()->pending()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
        ]);
        MemoItem::factory()->create(['memo_id' => $memo->id]);

        $response = $this->postJson("/api/v1/memos/{$memo->id}/send");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', Memo::STATUS_SENT_TO_VENDOR);

        $this->assertDatabaseHas('memos', [
            'id' => $memo->id,
            'status' => Memo::STATUS_SENT_TO_VENDOR,
        ]);
    }

    public function test_can_mark_memo_received_by_vendor(): void
    {
        Passport::actingAs($this->user);

        $memo = Memo::factory()->sentToVendor()->create(['store_id' => $this->store->id]);

        $response = $this->postJson("/api/v1/memos/{$memo->id}/receive");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', Memo::STATUS_VENDOR_RECEIVED);
    }

    public function test_can_return_memo_item(): void
    {
        Passport::actingAs($this->user);

        $memo = Memo::factory()->vendorReceived()->create(['store_id' => $this->store->id]);
        $item = MemoItem::factory()->create([
            'memo_id' => $memo->id,
            'is_returned' => false,
        ]);

        $response = $this->postJson("/api/v1/memos/{$memo->id}/items/{$item->id}/return");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Item returned successfully.');

        $this->assertDatabaseHas('memo_items', [
            'id' => $item->id,
            'is_returned' => true,
        ]);
    }

    public function test_memo_service_calculates_totals(): void
    {
        $memo = Memo::factory()->create([
            'store_id' => $this->store->id,
            'tax_rate' => 0.08,
            'charge_taxes' => true,
            'shipping_cost' => 15.00,
        ]);

        MemoItem::factory()->create([
            'memo_id' => $memo->id,
            'price' => 200.00,
            'charge_taxes' => true,
        ]);
        MemoItem::factory()->create([
            'memo_id' => $memo->id,
            'price' => 300.00,
            'charge_taxes' => true,
        ]);

        $service = app(MemoService::class);
        $totals = $service->calculateTotals($memo);

        // Subtotal: 200 + 300 = 500
        // Tax: 500 * 0.08 = 40
        // Total: 500 + 40 + 15 = 555
        $this->assertEquals(500.00, $totals['subtotal']);
        $this->assertEquals(40.00, $totals['tax']);
        $this->assertEquals(555.00, $totals['total']);
    }

    public function test_memo_item_due_date_calculation(): void
    {
        $memo = Memo::factory()->create([
            'store_id' => $this->store->id,
            'tenure' => 30,
        ]);

        $item = MemoItem::factory()->create([
            'memo_id' => $memo->id,
            'tenor' => null, // Will use memo's tenure
        ]);

        // Due date should be 30 days from creation
        $expectedDueDate = $item->created_at->addDays(30)->toDateString();

        $service = app(MemoService::class);
        $service->calculateItemDueDate($item, $memo);

        $item->refresh();
        $this->assertEquals($expectedDueDate, $item->due_date->toDateString());
    }

    public function test_memo_generates_unique_number(): void
    {
        $memo1 = Memo::factory()->create(['store_id' => $this->store->id]);
        $memo2 = Memo::factory()->create(['store_id' => $this->store->id]);

        $this->assertNotEquals($memo1->memo_number, $memo2->memo_number);
        $this->assertStringStartsWith('MEM-', $memo1->memo_number);
    }

    public function test_can_delete_pending_memo(): void
    {
        Passport::actingAs($this->user);

        $memo = Memo::factory()->pending()->create(['store_id' => $this->store->id]);

        $response = $this->deleteJson("/api/v1/memos/{$memo->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('memos', ['id' => $memo->id]);
    }

    public function test_only_store_memos_are_visible(): void
    {
        Passport::actingAs($this->user);

        $otherStore = Store::factory()->create();
        Memo::factory()->count(2)->create(['store_id' => $this->store->id]);
        Memo::factory()->count(3)->create(['store_id' => $otherStore->id]);

        $response = $this->getJson('/api/v1/memos');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_memo_computes_duration(): void
    {
        $memo = Memo::factory()->sentToVendor()->create([
            'store_id' => $this->store->id,
            'created_at' => now()->subDays(15),
        ]);

        $memo->computeDuration();

        $this->assertEquals(15, $memo->duration);
    }

    public function test_payment_terms_validation(): void
    {
        Passport::actingAs($this->user);

        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);

        $response = $this->postJson('/api/v1/memos', [
            'vendor_id' => $vendor->id,
            'tenure' => 45, // Invalid - not in [7, 14, 30, 60]
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['tenure']);
    }

    public function test_memo_items_not_taxed_when_charge_taxes_false(): void
    {
        $memo = Memo::factory()->create([
            'store_id' => $this->store->id,
            'tax_rate' => 0.08,
            'charge_taxes' => true,
        ]);

        MemoItem::factory()->create([
            'memo_id' => $memo->id,
            'price' => 200.00,
            'charge_taxes' => false,
        ]);
        MemoItem::factory()->create([
            'memo_id' => $memo->id,
            'price' => 300.00,
            'charge_taxes' => true,
        ]);

        $service = app(MemoService::class);
        $totals = $service->calculateTotals($memo);

        // Only the $300 item is taxed: 300 * 0.08 = 24
        $this->assertEquals(24.00, $totals['tax']);
    }
}
