<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Memo;
use App\Models\MemoItem;
use App\Models\Repair;
use App\Models\RepairItem;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackingSlipTest extends TestCase
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
            'step' => 2,
        ]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        $this->user->update(['current_store_id' => $this->store->id]);
        app(StoreContext::class)->setCurrentStore($this->store);

        Warehouse::factory()->create([
            'store_id' => $this->store->id,
            'is_default' => true,
        ]);
    }

    public function test_can_stream_memo_packing_slip(): void
    {
        $this->actingAs($this->user, 'api');

        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $memo = Memo::factory()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
        ]);
        MemoItem::factory()->create(['memo_id' => $memo->id]);

        $response = $this->get("/api/v1/memos/{$memo->id}/packing-slip/stream");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_can_download_memo_packing_slip(): void
    {
        $this->actingAs($this->user, 'api');

        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $memo = Memo::factory()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
        ]);
        MemoItem::factory()->create(['memo_id' => $memo->id]);

        $response = $this->get("/api/v1/memos/{$memo->id}/packing-slip");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $response->assertHeaderContains('content-disposition', 'attachment');
    }

    public function test_can_stream_repair_packing_slip(): void
    {
        $this->actingAs($this->user, 'api');

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);
        $repair = Repair::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
        ]);
        RepairItem::factory()->create(['repair_id' => $repair->id]);

        $response = $this->get("/api/v1/repairs/{$repair->id}/packing-slip/stream");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_can_download_repair_packing_slip(): void
    {
        $this->actingAs($this->user, 'api');

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);
        $repair = Repair::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
        ]);
        RepairItem::factory()->create(['repair_id' => $repair->id]);

        $response = $this->get("/api/v1/repairs/{$repair->id}/packing-slip");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_can_stream_transaction_packing_slip(): void
    {
        $this->actingAs($this->user, 'api');

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);
        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
        ]);
        TransactionItem::factory()->create(['transaction_id' => $transaction->id]);

        $response = $this->get("/api/v1/transactions/{$transaction->id}/packing-slip/stream");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_can_download_transaction_packing_slip(): void
    {
        $this->actingAs($this->user, 'api');

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);
        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
        ]);
        TransactionItem::factory()->create(['transaction_id' => $transaction->id]);

        $response = $this->get("/api/v1/transactions/{$transaction->id}/packing-slip");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_cannot_access_packing_slip_from_other_store(): void
    {
        $this->actingAs($this->user, 'api');

        $otherStore = Store::factory()->create();
        $vendor = Vendor::factory()->create(['store_id' => $otherStore->id]);
        $memo = Memo::factory()->create([
            'store_id' => $otherStore->id,
            'vendor_id' => $vendor->id,
        ]);

        $response = $this->get("/api/v1/memos/{$memo->id}/packing-slip/stream");

        $response->assertNotFound();
    }
}
