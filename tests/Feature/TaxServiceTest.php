<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Memos\MemoService;
use App\Services\Repairs\RepairService;
use App\Services\StoreContext;
use App\Services\TaxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxServiceTest extends TestCase
{
    use RefreshDatabase;

    protected TaxService $taxService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->taxService = app(TaxService::class);
    }

    public function test_returns_store_default_tax_rate_when_no_warehouse(): void
    {
        $store = Store::factory()->withTaxRate(0.08)->create();

        $taxRate = $this->taxService->getTaxRate(null, $store);

        $this->assertEquals(0.08, $taxRate);
    }

    public function test_returns_warehouse_tax_rate_when_set(): void
    {
        $store = Store::factory()->withTaxRate(0.08)->create();
        $warehouse = Warehouse::factory()
            ->withTaxRate(0.10)
            ->create(['store_id' => $store->id]);

        $taxRate = $this->taxService->getTaxRate($warehouse, $store);

        $this->assertEquals(0.10, $taxRate);
    }

    public function test_falls_back_to_store_default_when_warehouse_tax_rate_is_null(): void
    {
        $store = Store::factory()->withTaxRate(0.08)->create();
        $warehouse = Warehouse::factory()
            ->create(['store_id' => $store->id, 'tax_rate' => null]);

        $taxRate = $this->taxService->getTaxRate($warehouse, $store);

        $this->assertEquals(0.08, $taxRate);
    }

    public function test_returns_zero_when_no_tax_rates_configured(): void
    {
        $store = Store::factory()->create(['default_tax_rate' => 0]);
        $warehouse = Warehouse::factory()
            ->create(['store_id' => $store->id, 'tax_rate' => null]);

        $taxRate = $this->taxService->getTaxRate($warehouse, $store);

        $this->assertEquals(0, $taxRate);
    }

    public function test_resolve_tax_rate_returns_explicit_value_when_provided(): void
    {
        $store = Store::factory()->withTaxRate(0.08)->create();
        $warehouse = Warehouse::factory()
            ->withTaxRate(0.10)
            ->create(['store_id' => $store->id]);

        $taxRate = $this->taxService->resolveTaxRate(0.15, $warehouse, $store);

        $this->assertEquals(0.15, $taxRate);
    }

    public function test_resolve_tax_rate_uses_warehouse_when_no_explicit_value(): void
    {
        $store = Store::factory()->withTaxRate(0.08)->create();
        $warehouse = Warehouse::factory()
            ->withTaxRate(0.10)
            ->create(['store_id' => $store->id]);

        $taxRate = $this->taxService->resolveTaxRate(null, $warehouse, $store);

        $this->assertEquals(0.10, $taxRate);
    }

    public function test_resolve_tax_rate_uses_store_default_when_no_explicit_and_no_warehouse_rate(): void
    {
        $store = Store::factory()->withTaxRate(0.08)->create();
        $warehouse = Warehouse::factory()
            ->create(['store_id' => $store->id, 'tax_rate' => null]);

        $taxRate = $this->taxService->resolveTaxRate(null, $warehouse, $store);

        $this->assertEquals(0.08, $taxRate);
    }

    public function test_warehouse_tax_rate_zero_is_treated_as_valid_override(): void
    {
        $store = Store::factory()->withTaxRate(0.08)->create();
        // Some locations may be tax exempt (0%)
        $warehouse = Warehouse::factory()
            ->withTaxRate(0)
            ->create(['store_id' => $store->id]);

        $taxRate = $this->taxService->getTaxRate($warehouse, $store);

        // 0 is a valid tax rate (tax-exempt), should NOT fall back to store default
        $this->assertEquals(0, $taxRate);
    }

    public function test_memo_created_with_warehouse_uses_warehouse_tax_rate(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->withTaxRate(0.08)->create(['user_id' => $user->id]);
        $warehouse = Warehouse::factory()
            ->withTaxRate(0.10)
            ->create(['store_id' => $store->id]);
        $vendor = Vendor::factory()->create(['store_id' => $store->id]);

        $role = Role::factory()->owner()->create(['store_id' => $store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
        ]);

        app(StoreContext::class)->setCurrentStore($store);

        $memoService = app(MemoService::class);
        $memo = $memoService->create([
            'vendor_id' => $vendor->id,
            'warehouse_id' => $warehouse->id,
        ]);

        // Should use warehouse tax rate (0.10), not store default (0.08)
        $this->assertEquals(0.10, (float) $memo->tax_rate);
        $this->assertEquals($warehouse->id, $memo->warehouse_id);
    }

    public function test_memo_created_without_warehouse_uses_store_default_tax_rate(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->withTaxRate(0.08)->create(['user_id' => $user->id]);
        $vendor = Vendor::factory()->create(['store_id' => $store->id]);

        $role = Role::factory()->owner()->create(['store_id' => $store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
        ]);

        app(StoreContext::class)->setCurrentStore($store);

        $memoService = app(MemoService::class);
        $memo = $memoService->create([
            'vendor_id' => $vendor->id,
        ]);

        // Should use store default tax rate (0.08)
        $this->assertEquals(0.08, (float) $memo->tax_rate);
        $this->assertNull($memo->warehouse_id);
    }

    public function test_repair_created_with_warehouse_uses_warehouse_tax_rate(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->withTaxRate(0.08)->create(['user_id' => $user->id]);
        $warehouse = Warehouse::factory()
            ->withTaxRate(0.06)
            ->create(['store_id' => $store->id]);

        $role = Role::factory()->owner()->create(['store_id' => $store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
        ]);

        app(StoreContext::class)->setCurrentStore($store);

        $repairService = app(RepairService::class);
        $repair = $repairService->create([
            'warehouse_id' => $warehouse->id,
        ]);

        // Should use warehouse tax rate (0.06), not store default (0.08)
        $this->assertEquals(0.06, (float) $repair->tax_rate);
        $this->assertEquals($warehouse->id, $repair->warehouse_id);
    }

    public function test_memo_uses_store_user_default_warehouse_when_not_specified(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->withTaxRate(0.08)->create(['user_id' => $user->id]);
        $defaultWarehouse = Warehouse::factory()
            ->withTaxRate(0.095)
            ->create(['store_id' => $store->id]);
        $vendor = Vendor::factory()->create(['store_id' => $store->id]);

        $role = Role::factory()->owner()->create(['store_id' => $store->id]);
        StoreUser::factory()->owner()->withDefaultWarehouse($defaultWarehouse)->create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
        ]);

        $this->actingAs($user);
        app(StoreContext::class)->setCurrentStore($store);

        $memoService = app(MemoService::class);
        $memo = $memoService->create([
            'vendor_id' => $vendor->id,
            // No warehouse_id provided - should use user's default
        ]);

        // Should use user's default warehouse and its tax rate
        $this->assertEquals($defaultWarehouse->id, $memo->warehouse_id);
        $this->assertEquals(0.095, (float) $memo->tax_rate);
    }

    public function test_memo_can_override_user_default_warehouse(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->withTaxRate(0.08)->create(['user_id' => $user->id]);
        $defaultWarehouse = Warehouse::factory()
            ->withTaxRate(0.05)
            ->create(['store_id' => $store->id]);
        $otherWarehouse = Warehouse::factory()
            ->withTaxRate(0.12)
            ->create(['store_id' => $store->id]);
        $vendor = Vendor::factory()->create(['store_id' => $store->id]);

        $role = Role::factory()->owner()->create(['store_id' => $store->id]);
        StoreUser::factory()->owner()->withDefaultWarehouse($defaultWarehouse)->create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
        ]);

        $this->actingAs($user);
        app(StoreContext::class)->setCurrentStore($store);

        $memoService = app(MemoService::class);
        $memo = $memoService->create([
            'vendor_id' => $vendor->id,
            'warehouse_id' => $otherWarehouse->id, // Override with different warehouse
        ]);

        // Should use the explicitly provided warehouse, not the default
        $this->assertEquals($otherWarehouse->id, $memo->warehouse_id);
        $this->assertEquals(0.12, (float) $memo->tax_rate);
    }
}
