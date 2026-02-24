<?php

namespace Tests\Feature\Widget;

use App\Models\Repair;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Models\Vendor;
use App\Services\StoreContext;
use App\Widget\Appraisals\AppraisalsTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppraisalsTableTest extends TestCase
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

    public function test_returns_only_appraisals(): void
    {
        // Create appraisals
        Repair::factory()->appraisal()->count(3)->create(['store_id' => $this->store->id]);

        // Create regular repairs
        Repair::factory()->count(2)->create(['store_id' => $this->store->id, 'is_appraisal' => false]);

        $table = new AppraisalsTable;
        $data = $table->data(['store_id' => $this->store->id]);

        $this->assertEquals(3, $data['total']);
    }

    public function test_excludes_regular_repairs(): void
    {
        Repair::factory()->count(5)->create(['store_id' => $this->store->id, 'is_appraisal' => false]);

        $table = new AppraisalsTable;
        $data = $table->data(['store_id' => $this->store->id]);

        $this->assertEquals(0, $data['total']);
    }

    public function test_status_filter(): void
    {
        Repair::factory()->appraisal()->pending()->count(2)->create(['store_id' => $this->store->id]);
        Repair::factory()->appraisal()->completed()->create(['store_id' => $this->store->id]);

        $table = new AppraisalsTable;
        $data = $table->data([
            'store_id' => $this->store->id,
            'status' => 'pending',
        ]);

        $this->assertEquals(2, $data['total']);
    }

    public function test_vendor_filter(): void
    {
        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $otherVendor = Vendor::factory()->create(['store_id' => $this->store->id]);

        Repair::factory()->appraisal()->count(2)->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
        ]);
        Repair::factory()->appraisal()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $otherVendor->id,
        ]);

        $table = new AppraisalsTable;
        $data = $table->data([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
        ]);

        $this->assertEquals(2, $data['total']);
    }

    public function test_date_range_filter(): void
    {
        Repair::factory()->appraisal()->create([
            'store_id' => $this->store->id,
            'created_at' => now()->subDays(10),
        ]);
        Repair::factory()->appraisal()->create([
            'store_id' => $this->store->id,
            'created_at' => now(),
        ]);

        $table = new AppraisalsTable;
        $data = $table->data([
            'store_id' => $this->store->id,
            'date_from' => now()->subDays(5)->toDateString(),
            'date_to' => now()->toDateString(),
        ]);

        $this->assertEquals(1, $data['total']);
    }

    public function test_store_scoping(): void
    {
        $otherStore = Store::factory()->create();

        Repair::factory()->appraisal()->count(3)->create(['store_id' => $this->store->id]);
        Repair::factory()->appraisal()->count(2)->create(['store_id' => $otherStore->id]);

        $table = new AppraisalsTable;
        $data = $table->data(['store_id' => $this->store->id]);

        $this->assertEquals(3, $data['total']);
    }

    public function test_links_point_to_appraisals_path(): void
    {
        $appraisal = Repair::factory()->appraisal()->create(['store_id' => $this->store->id]);

        $table = new AppraisalsTable;
        $data = $table->data(['store_id' => $this->store->id]);

        $item = $data['items'][0];
        $this->assertEquals("/appraisals/{$appraisal->id}", $item['repair_number']['href']);
    }
}
