<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Repair;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Models\Vendor;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppraisalTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->onboarded()->create(['user_id' => $this->user->id]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_index_page_loads(): void
    {
        $this->actingAs($this->user);

        $response = $this->get('/appraisals');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('repairs/Index')
            ->has('isAppraisal')
            ->where('isAppraisal', true)
        );
    }

    public function test_index_does_not_show_regular_repairs(): void
    {
        $this->actingAs($this->user);

        // Create a regular repair and an appraisal
        Repair::factory()->create(['store_id' => $this->store->id, 'is_appraisal' => false]);
        Repair::factory()->appraisal()->create(['store_id' => $this->store->id]);

        $response = $this->get('/appraisals');

        $response->assertStatus(200);
    }

    public function test_create_wizard_loads(): void
    {
        $this->actingAs($this->user);

        $response = $this->get('/appraisals/create');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('repairs/CreateWizard')
            ->has('isAppraisal')
            ->where('isAppraisal', true)
        );
    }

    public function test_store_creates_appraisal_with_apr_prefix(): void
    {
        $this->actingAs($this->user);

        $storeUser = StoreUser::where('user_id', $this->user->id)
            ->where('store_id', $this->store->id)
            ->first();

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        $response = $this->post('/appraisals', [
            'store_user_id' => $storeUser->id,
            'customer_id' => $customer->id,
            'items' => [
                [
                    'title' => 'Diamond Ring Appraisal',
                    'customer_cost' => 100.00,
                    'vendor_cost' => 0,
                ],
            ],
            'service_fee' => 50.00,
            'description' => 'Insurance appraisal',
        ]);

        $response->assertRedirect();

        $appraisal = Repair::where('store_id', $this->store->id)
            ->where('is_appraisal', true)
            ->first();

        $this->assertNotNull($appraisal);
        $this->assertTrue($appraisal->is_appraisal);
        $this->assertStringStartsWith('APR-', $appraisal->repair_number);
        $this->assertEquals('Insurance appraisal', $appraisal->description);
    }

    public function test_store_creates_appraisal_without_vendor(): void
    {
        $this->actingAs($this->user);

        $storeUser = StoreUser::where('user_id', $this->user->id)
            ->where('store_id', $this->store->id)
            ->first();

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        $response = $this->post('/appraisals', [
            'store_user_id' => $storeUser->id,
            'customer_id' => $customer->id,
            'items' => [
                [
                    'title' => 'Watch Appraisal',
                    'customer_cost' => 75.00,
                    'vendor_cost' => 0,
                ],
            ],
        ]);

        $response->assertRedirect();

        $appraisal = Repair::where('store_id', $this->store->id)
            ->where('is_appraisal', true)
            ->first();

        $this->assertNotNull($appraisal);
        $this->assertNull($appraisal->vendor_id);
    }

    public function test_show_page_loads_correct_appraisal(): void
    {
        $this->actingAs($this->user);

        $appraisal = Repair::factory()->appraisal()->create(['store_id' => $this->store->id]);

        $response = $this->get("/appraisals/{$appraisal->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('repairs/Show')
            ->has('isAppraisal')
            ->where('isAppraisal', true)
            ->has('repair')
        );
    }

    public function test_update_appraisal(): void
    {
        $this->actingAs($this->user);

        $appraisal = Repair::factory()->appraisal()->pending()->create([
            'store_id' => $this->store->id,
        ]);

        $response = $this->patch("/appraisals/{$appraisal->id}", [
            'description' => 'Updated appraisal description',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('repairs', [
            'id' => $appraisal->id,
            'description' => 'Updated appraisal description',
        ]);
    }

    public function test_cancel_appraisal(): void
    {
        $this->actingAs($this->user);

        $appraisal = Repair::factory()->appraisal()->pending()->create([
            'store_id' => $this->store->id,
        ]);

        $response = $this->post("/appraisals/{$appraisal->id}/cancel");

        $response->assertRedirect();
        $this->assertDatabaseHas('repairs', [
            'id' => $appraisal->id,
            'status' => Repair::STATUS_CANCELLED,
        ]);
    }

    public function test_delete_pending_appraisal(): void
    {
        $this->actingAs($this->user);

        $appraisal = Repair::factory()->appraisal()->pending()->create([
            'store_id' => $this->store->id,
        ]);

        $response = $this->delete("/appraisals/{$appraisal->id}");

        $response->assertRedirect('/appraisals');
        $this->assertSoftDeleted('repairs', ['id' => $appraisal->id]);
    }

    public function test_change_status(): void
    {
        $this->actingAs($this->user);

        $appraisal = Repair::factory()->appraisal()->pending()->create([
            'store_id' => $this->store->id,
        ]);

        $response = $this->post("/appraisals/{$appraisal->id}/change-status", [
            'status' => Repair::STATUS_COMPLETED,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('repairs', [
            'id' => $appraisal->id,
            'status' => Repair::STATUS_COMPLETED,
        ]);
    }

    public function test_bulk_action_only_affects_appraisals(): void
    {
        $this->actingAs($this->user);

        $appraisal = Repair::factory()->appraisal()->pending()->create([
            'store_id' => $this->store->id,
        ]);

        $repair = Repair::factory()->pending()->create([
            'store_id' => $this->store->id,
            'is_appraisal' => false,
        ]);

        $response = $this->post('/appraisals/bulk-action', [
            'action' => 'cancel',
            'ids' => [$appraisal->id, $repair->id],
        ]);

        $response->assertRedirect('/appraisals');

        // The appraisal should be cancelled
        $this->assertDatabaseHas('repairs', [
            'id' => $appraisal->id,
            'status' => Repair::STATUS_CANCELLED,
        ]);

        // The regular repair should NOT be affected (bulk action filters is_appraisal=true)
        $this->assertDatabaseHas('repairs', [
            'id' => $repair->id,
            'status' => Repair::STATUS_PENDING,
        ]);
    }

    public function test_requires_authentication(): void
    {
        $response = $this->get('/appraisals');

        $response->assertRedirect('/login');
    }

    public function test_store_scoping_prevents_cross_store_access(): void
    {
        $this->actingAs($this->user);

        $otherStore = Store::factory()->create();
        $appraisal = Repair::factory()->appraisal()->create([
            'store_id' => $otherStore->id,
        ]);

        $response = $this->get("/appraisals/{$appraisal->id}");

        $response->assertStatus(404);
    }

    public function test_mark_completed(): void
    {
        $this->actingAs($this->user);

        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $appraisal = Repair::factory()->appraisal()->receivedByVendor()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
        ]);

        $response = $this->post("/appraisals/{$appraisal->id}/mark-completed");

        $response->assertRedirect();
        $this->assertDatabaseHas('repairs', [
            'id' => $appraisal->id,
            'status' => Repair::STATUS_COMPLETED,
        ]);
    }

    public function test_send_to_vendor(): void
    {
        $this->actingAs($this->user);

        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $appraisal = Repair::factory()->appraisal()->pending()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
        ]);

        $response = $this->post("/appraisals/{$appraisal->id}/send-to-vendor");

        $response->assertRedirect();
        $this->assertDatabaseHas('repairs', [
            'id' => $appraisal->id,
            'status' => Repair::STATUS_SENT_TO_VENDOR,
        ]);
    }

    public function test_send_to_vendor_requires_vendor(): void
    {
        $this->actingAs($this->user);

        $appraisal = Repair::factory()->appraisal()->pending()->create([
            'store_id' => $this->store->id,
            'vendor_id' => null,
        ]);

        $response = $this->post("/appraisals/{$appraisal->id}/send-to-vendor");

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Please assign a vendor before sending.');
    }
}
