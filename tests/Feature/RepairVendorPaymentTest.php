<?php

namespace Tests\Feature;

use App\Models\Repair;
use App\Models\RepairVendorPayment;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RepairVendorPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected Vendor $vendor;

    protected Repair $repair;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id, 'step' => 2]);

        Role::createDefaultRoles($this->store->id);

        $ownerRole = Role::where('store_id', $this->store->id)
            ->where('slug', 'owner')
            ->first();

        StoreUser::create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $ownerRole->id,
            'is_owner' => true,
            'status' => 'active',
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $this->user->email,
        ]);

        $this->user->update(['current_store_id' => $this->store->id]);

        $this->vendor = Vendor::factory()->create(['store_id' => $this->store->id]);

        $this->repair = Repair::factory()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $this->vendor->id,
        ]);
    }

    protected function withStore()
    {
        return $this->withSession(['current_store_id' => $this->store->id]);
    }

    public function test_can_view_vendor_payments_index(): void
    {
        $this->actingAs($this->user);

        RepairVendorPayment::factory()->count(3)->create([
            'store_id' => $this->store->id,
            'repair_id' => $this->repair->id,
            'vendor_id' => $this->vendor->id,
        ]);

        $response = $this->withStore()->get('/repair-vendor-payments');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('repairs/VendorPayments')
                ->has('payments.data', 3)
            );
    }

    public function test_can_create_vendor_payment(): void
    {
        $this->actingAs($this->user);

        $response = $this->withStore()->post("/repairs/{$this->repair->id}/vendor-payments", [
            'check_number' => 'CHK-12345',
            'amount' => 150.50,
            'vendor_invoice_amount' => 175.00,
            'reason' => 'Repair service payment',
            'payment_date' => '2026-02-15',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('repair_vendor_payments', [
            'store_id' => $this->store->id,
            'repair_id' => $this->repair->id,
            'vendor_id' => $this->vendor->id,
            'check_number' => 'CHK-12345',
            'amount' => 150.50,
            'vendor_invoice_amount' => 175.00,
            'reason' => 'Repair service payment',
            'user_id' => $this->user->id,
        ]);

        $payment = RepairVendorPayment::where('repair_id', $this->repair->id)->first();
        $this->assertEquals('2026-02-15', $payment->payment_date->toDateString());
    }

    public function test_vendor_payment_requires_amount(): void
    {
        $this->actingAs($this->user);

        $response = $this->withStore()->post("/repairs/{$this->repair->id}/vendor-payments", [
            'check_number' => 'CHK-12345',
            'reason' => 'Test payment',
        ]);

        $response->assertSessionHasErrors('amount');
    }

    public function test_vendor_payment_amount_must_be_positive(): void
    {
        $this->actingAs($this->user);

        $response = $this->withStore()->post("/repairs/{$this->repair->id}/vendor-payments", [
            'amount' => 0,
        ]);

        $response->assertSessionHasErrors('amount');
    }

    public function test_can_update_vendor_payment(): void
    {
        $this->actingAs($this->user);

        $payment = RepairVendorPayment::factory()->create([
            'store_id' => $this->store->id,
            'repair_id' => $this->repair->id,
            'vendor_id' => $this->vendor->id,
            'check_number' => 'OLD-CHK',
            'amount' => 100.00,
        ]);

        $response = $this->withStore()->put("/repair-vendor-payments/{$payment->id}", [
            'check_number' => 'NEW-CHK',
            'amount' => 200.00,
            'reason' => 'Updated reason',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('repair_vendor_payments', [
            'id' => $payment->id,
            'check_number' => 'NEW-CHK',
            'amount' => 200.00,
            'reason' => 'Updated reason',
        ]);
    }

    public function test_can_delete_vendor_payment(): void
    {
        $this->actingAs($this->user);

        $payment = RepairVendorPayment::factory()->create([
            'store_id' => $this->store->id,
            'repair_id' => $this->repair->id,
        ]);

        $response = $this->withStore()->delete("/repair-vendor-payments/{$payment->id}");

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertSoftDeleted('repair_vendor_payments', ['id' => $payment->id]);
    }

    public function test_can_upload_attachment_with_payment(): void
    {
        Storage::fake('local');
        $this->actingAs($this->user);

        $file = UploadedFile::fake()->create('invoice.pdf', 1024);

        $response = $this->withStore()->post("/repairs/{$this->repair->id}/vendor-payments", [
            'amount' => 100.00,
            'attachment' => $file,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $payment = RepairVendorPayment::where('repair_id', $this->repair->id)->first();
        $this->assertNotNull($payment->attachment_path);
        $this->assertEquals('invoice.pdf', $payment->attachment_name);
    }

    public function test_can_download_attachment(): void
    {
        Storage::fake('local');
        $this->actingAs($this->user);

        Storage::disk('local')->put('vendor-payments/test.pdf', 'Test content');

        $payment = RepairVendorPayment::factory()->create([
            'store_id' => $this->store->id,
            'repair_id' => $this->repair->id,
            'attachment_path' => 'vendor-payments/test.pdf',
            'attachment_name' => 'invoice.pdf',
        ]);

        $response = $this->withStore()->get("/repair-vendor-payments/{$payment->id}/attachment");

        $response->assertStatus(200);
        $response->assertDownload('invoice.pdf');
    }

    public function test_filter_by_vendor(): void
    {
        $this->actingAs($this->user);

        $otherVendor = Vendor::factory()->create(['store_id' => $this->store->id]);

        RepairVendorPayment::factory()->create([
            'store_id' => $this->store->id,
            'repair_id' => $this->repair->id,
            'vendor_id' => $this->vendor->id,
        ]);

        RepairVendorPayment::factory()->create([
            'store_id' => $this->store->id,
            'repair_id' => $this->repair->id,
            'vendor_id' => $otherVendor->id,
        ]);

        $response = $this->withStore()->get("/repair-vendor-payments?vendor_id={$this->vendor->id}");

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('repairs/VendorPayments')
                ->has('payments.data', 1)
            );
    }

    public function test_filter_by_date_range(): void
    {
        $this->actingAs($this->user);

        RepairVendorPayment::factory()->create([
            'store_id' => $this->store->id,
            'repair_id' => $this->repair->id,
            'payment_date' => '2026-02-10',
        ]);

        RepairVendorPayment::factory()->create([
            'store_id' => $this->store->id,
            'repair_id' => $this->repair->id,
            'payment_date' => '2026-02-20',
        ]);

        $response = $this->withStore()->get('/repair-vendor-payments?date_from=2026-02-15&date_to=2026-02-25');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('repairs/VendorPayments')
                ->has('payments.data', 1)
            );
    }

    public function test_filter_by_repair_number(): void
    {
        $this->actingAs($this->user);

        $otherRepair = Repair::factory()->create([
            'store_id' => $this->store->id,
            'repair_number' => 'REP-999',
        ]);

        RepairVendorPayment::factory()->create([
            'store_id' => $this->store->id,
            'repair_id' => $this->repair->id,
        ]);

        RepairVendorPayment::factory()->create([
            'store_id' => $this->store->id,
            'repair_id' => $otherRepair->id,
        ]);

        $response = $this->withStore()->get('/repair-vendor-payments?repair_number=REP-999');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('repairs/VendorPayments')
                ->has('payments.data', 1)
            );
    }

    public function test_only_store_payments_are_visible(): void
    {
        $this->actingAs($this->user);

        $otherStore = Store::factory()->create();
        $otherRepair = Repair::factory()->create(['store_id' => $otherStore->id]);

        RepairVendorPayment::factory()->count(2)->create([
            'store_id' => $this->store->id,
            'repair_id' => $this->repair->id,
        ]);

        RepairVendorPayment::factory()->count(3)->create([
            'store_id' => $otherStore->id,
            'repair_id' => $otherRepair->id,
        ]);

        $response = $this->withStore()->get('/repair-vendor-payments');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('repairs/VendorPayments')
                ->has('payments.data', 2)
            );
    }

    public function test_cannot_access_other_store_payment(): void
    {
        $this->actingAs($this->user);

        $otherStore = Store::factory()->create();
        $otherRepair = Repair::factory()->create(['store_id' => $otherStore->id]);

        $payment = RepairVendorPayment::factory()->create([
            'store_id' => $otherStore->id,
            'repair_id' => $otherRepair->id,
        ]);

        $response = $this->withStore()->put("/repair-vendor-payments/{$payment->id}", [
            'amount' => 100.00,
        ]);

        $response->assertStatus(404);
    }

    public function test_repair_has_vendor_payments_relationship(): void
    {
        $payments = RepairVendorPayment::factory()->count(3)->create([
            'store_id' => $this->store->id,
            'repair_id' => $this->repair->id,
        ]);

        $this->repair->refresh();

        $this->assertCount(3, $this->repair->vendorPayments);
    }

    public function test_vendor_payment_defaults_to_today_if_no_date_provided(): void
    {
        $this->actingAs($this->user);

        $response = $this->withStore()->post("/repairs/{$this->repair->id}/vendor-payments", [
            'amount' => 100.00,
        ]);

        $response->assertRedirect();

        $payment = RepairVendorPayment::where('repair_id', $this->repair->id)->first();
        $this->assertEquals(now()->toDateString(), $payment->payment_date->toDateString());
    }

    public function test_multiple_payments_can_be_added_to_same_repair(): void
    {
        $this->actingAs($this->user);

        $this->withStore()->post("/repairs/{$this->repair->id}/vendor-payments", [
            'amount' => 100.00,
            'check_number' => 'CHK-001',
        ]);

        $this->withStore()->post("/repairs/{$this->repair->id}/vendor-payments", [
            'amount' => 200.00,
            'check_number' => 'CHK-002',
        ]);

        $this->withStore()->post("/repairs/{$this->repair->id}/vendor-payments", [
            'amount' => 50.00,
            'check_number' => 'CHK-003',
        ]);

        $this->assertCount(3, RepairVendorPayment::where('repair_id', $this->repair->id)->get());
    }
}
