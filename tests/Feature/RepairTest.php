<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Repair;
use App\Models\RepairItem;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\Repairs\RepairService;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class RepairTest extends TestCase
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

    public function test_can_list_repairs(): void
    {
        Passport::actingAs($this->user);

        Repair::factory()->count(3)->create(['store_id' => $this->store->id]);

        $response = $this->getJson('/api/v1/repairs');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_can_filter_repairs_by_status(): void
    {
        Passport::actingAs($this->user);

        Repair::factory()->pending()->count(2)->create(['store_id' => $this->store->id]);
        Repair::factory()->sentToVendor()->count(3)->create(['store_id' => $this->store->id]);

        $response = $this->getJson('/api/v1/repairs?status=pending');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_can_filter_repairs_by_vendor(): void
    {
        Passport::actingAs($this->user);

        $vendor = Customer::factory()->create(['store_id' => $this->store->id]);
        $otherVendor = Customer::factory()->create(['store_id' => $this->store->id]);

        Repair::factory()->count(2)->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
        ]);
        Repair::factory()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $otherVendor->id,
        ]);

        $response = $this->getJson("/api/v1/repairs?vendor_id={$vendor->id}");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_can_create_repair_via_api(): void
    {
        Passport::actingAs($this->user);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);
        $vendor = Customer::factory()->create(['store_id' => $this->store->id]);

        $response = $this->postJson('/api/v1/repairs', [
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'service_fee' => 25.00,
            'tax_rate' => 0.08,
            'description' => 'Ring resizing and polishing',
            'items' => [
                [
                    'title' => 'Gold Ring Resize',
                    'vendor_cost' => 50.00,
                    'customer_cost' => 75.00,
                ],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', Repair::STATUS_PENDING)
            ->assertJsonPath('data.customer_id', $customer->id)
            ->assertJsonPath('data.vendor_id', $vendor->id);

        $this->assertDatabaseHas('repairs', [
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'status' => Repair::STATUS_PENDING,
        ]);

        $this->assertDatabaseHas('repair_items', [
            'title' => 'Gold Ring Resize',
            'vendor_cost' => '50.00',
            'customer_cost' => '75.00',
        ]);
    }

    public function test_can_create_appraisal(): void
    {
        Passport::actingAs($this->user);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        $response = $this->postJson('/api/v1/repairs', [
            'customer_id' => $customer->id,
            'is_appraisal' => true,
            'service_fee' => 50.00,
            'description' => 'Diamond ring appraisal for insurance',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.is_appraisal', true);

        $this->assertDatabaseHas('repairs', [
            'is_appraisal' => true,
        ]);
    }

    public function test_can_show_repair_details(): void
    {
        Passport::actingAs($this->user);

        $repair = Repair::factory()->create(['store_id' => $this->store->id]);
        RepairItem::factory()->count(2)->create(['repair_id' => $repair->id]);

        $response = $this->getJson("/api/v1/repairs/{$repair->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $repair->id)
            ->assertJsonCount(2, 'data.items');
    }

    public function test_can_update_repair(): void
    {
        Passport::actingAs($this->user);

        $repair = Repair::factory()->pending()->create(['store_id' => $this->store->id]);

        $response = $this->putJson("/api/v1/repairs/{$repair->id}", [
            'description' => 'Updated description',
            'service_fee' => 30.00,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.description', 'Updated description')
            ->assertJsonPath('data.service_fee', '30.00');
    }

    public function test_can_add_item_to_repair(): void
    {
        Passport::actingAs($this->user);

        $repair = Repair::factory()->pending()->create(['store_id' => $this->store->id]);

        $response = $this->postJson("/api/v1/repairs/{$repair->id}/items", [
            'title' => 'Chain Repair',
            'vendor_cost' => 25.00,
            'customer_cost' => 40.00,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Item added successfully.');

        $this->assertDatabaseHas('repair_items', [
            'repair_id' => $repair->id,
            'title' => 'Chain Repair',
        ]);
    }

    public function test_can_update_repair_item(): void
    {
        Passport::actingAs($this->user);

        $repair = Repair::factory()->pending()->create(['store_id' => $this->store->id]);
        $item = RepairItem::factory()->create([
            'repair_id' => $repair->id,
            'customer_cost' => 50.00,
        ]);

        $response = $this->putJson("/api/v1/repairs/{$repair->id}/items/{$item->id}", [
            'customer_cost' => 60.00,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Item updated successfully.');

        $this->assertDatabaseHas('repair_items', [
            'id' => $item->id,
            'customer_cost' => '60.00',
        ]);
    }

    public function test_can_remove_repair_item(): void
    {
        Passport::actingAs($this->user);

        $repair = Repair::factory()->pending()->create(['store_id' => $this->store->id]);
        $item = RepairItem::factory()->create(['repair_id' => $repair->id]);

        $response = $this->deleteJson("/api/v1/repairs/{$repair->id}/items/{$item->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Item removed successfully.');

        $this->assertDatabaseMissing('repair_items', ['id' => $item->id]);
    }

    public function test_can_send_repair_to_vendor(): void
    {
        Passport::actingAs($this->user);

        $vendor = Customer::factory()->create(['store_id' => $this->store->id]);
        $repair = Repair::factory()->pending()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
        ]);

        $response = $this->postJson("/api/v1/repairs/{$repair->id}/send");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', Repair::STATUS_SENT_TO_VENDOR);

        $this->assertDatabaseHas('repairs', [
            'id' => $repair->id,
            'status' => Repair::STATUS_SENT_TO_VENDOR,
        ]);

        $repair->refresh();
        $this->assertNotNull($repair->date_sent_to_vendor);
    }

    public function test_can_mark_repair_received_by_vendor(): void
    {
        Passport::actingAs($this->user);

        $repair = Repair::factory()->sentToVendor()->create(['store_id' => $this->store->id]);

        $response = $this->postJson("/api/v1/repairs/{$repair->id}/receive");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', Repair::STATUS_RECEIVED_BY_VENDOR);

        $repair->refresh();
        $this->assertNotNull($repair->date_received_by_vendor);
    }

    public function test_can_mark_repair_completed(): void
    {
        Passport::actingAs($this->user);

        $repair = Repair::factory()->receivedByVendor()->create(['store_id' => $this->store->id]);

        $response = $this->postJson("/api/v1/repairs/{$repair->id}/complete");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', Repair::STATUS_COMPLETED);

        $repair->refresh();
        $this->assertNotNull($repair->date_completed);
    }

    public function test_repair_service_calculates_totals(): void
    {
        $repair = Repair::factory()->create([
            'store_id' => $this->store->id,
            'service_fee' => 20.00,
            'tax_rate' => 0.08,
            'shipping_cost' => 10.00,
            'discount' => 5.00,
        ]);

        RepairItem::factory()->create([
            'repair_id' => $repair->id,
            'customer_cost' => 50.00,
        ]);
        RepairItem::factory()->create([
            'repair_id' => $repair->id,
            'customer_cost' => 30.00,
        ]);

        $service = app(RepairService::class);
        $totals = $service->calculateTotals($repair);

        // Subtotal: 50 + 30 = 80
        // Tax: 80 * 0.08 = 6.4 (tax on items only, not service fee)
        // Total: 80 + 20 (service fee) + 6.4 + 10 (shipping) - 5 (discount) = 111.4
        $this->assertEquals(80.00, $totals['subtotal']);
        $this->assertEquals(6.40, $totals['tax']);
        $this->assertEquals(111.40, $totals['total']);
    }

    public function test_repair_generates_unique_number(): void
    {
        $repair1 = Repair::factory()->create(['store_id' => $this->store->id]);
        $repair2 = Repair::factory()->create(['store_id' => $this->store->id]);

        $this->assertNotEquals($repair1->repair_number, $repair2->repair_number);
        $this->assertStringStartsWith('REP-', $repair1->repair_number);
    }

    public function test_can_delete_pending_repair(): void
    {
        Passport::actingAs($this->user);

        $repair = Repair::factory()->pending()->create(['store_id' => $this->store->id]);

        $response = $this->deleteJson("/api/v1/repairs/{$repair->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('repairs', ['id' => $repair->id]);
    }

    public function test_only_store_repairs_are_visible(): void
    {
        Passport::actingAs($this->user);

        $otherStore = Store::factory()->create();
        Repair::factory()->count(2)->create(['store_id' => $this->store->id]);
        Repair::factory()->count(3)->create(['store_id' => $otherStore->id]);

        $response = $this->getJson('/api/v1/repairs');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_repair_computes_repair_days(): void
    {
        $repair = Repair::factory()->completed()->create([
            'store_id' => $this->store->id,
            'date_sent_to_vendor' => now()->subDays(7),
            'date_received_by_vendor' => now()->subDays(5),
            'date_completed' => now(),
        ]);

        $repair->computeRepairDays();

        // Repair days are calculated from received by vendor to completed (5 days)
        $this->assertEquals(5, $repair->repair_days);
    }

    public function test_create_sale_order_creates_invoice(): void
    {
        Passport::actingAs($this->user);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);
        $repair = Repair::factory()->completed()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'subtotal' => 100,
            'tax' => 8,
            'total' => 108,
        ]);

        RepairItem::factory()->create([
            'repair_id' => $repair->id,
            'customer_cost' => 100,
        ]);

        $response = $this->postJson("/api/v1/repairs/{$repair->id}/sale");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Sale order created successfully.');

        $repair->refresh();
        $this->assertEquals(Repair::STATUS_PAYMENT_RECEIVED, $repair->status);

        // Verify invoice was created
        $this->assertDatabaseHas('invoices', [
            'store_id' => $this->store->id,
            'invoiceable_type' => Repair::class,
            'invoiceable_id' => $repair->id,
            'status' => 'paid',
        ]);

        // Verify repair has invoice relationship
        $this->assertNotNull($repair->invoice);
        $this->assertEquals('paid', $repair->invoice->status);

        // Verify order has REP- prefix in invoice number
        $order = Order::find($repair->order_id);
        $this->assertNotNull($order);
        $this->assertStringStartsWith('REP-', $order->invoice_number);
        $this->assertEquals("REP-{$order->id}", $order->invoice_number);
    }
}
