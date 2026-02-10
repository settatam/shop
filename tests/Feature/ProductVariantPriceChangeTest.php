<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductVariantPriceChangeTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected Product $product;

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

        $this->product = Product::factory()->create(['store_id' => $this->store->id]);
    }

    public function test_variant_price_change_logs_price_change_activity(): void
    {
        $this->actingAs($this->user);

        $variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'TEST-SKU-001',
            'price' => 100.00,
            'wholesale_price' => 75.00,
            'cost' => 50.00,
            'quantity' => 10,
        ]);

        // Update the price
        $variant->update(['price' => 120.00]);

        $this->assertDatabaseHas('activity_logs', [
            'store_id' => $this->store->id,
            'subject_type' => ProductVariant::class,
            'subject_id' => $variant->id,
            'activity_slug' => Activity::PRODUCTS_PRICE_CHANGE,
        ]);
    }

    public function test_variant_wholesale_price_change_logs_price_change_activity(): void
    {
        $this->actingAs($this->user);

        $variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'TEST-SKU-002',
            'price' => 100.00,
            'wholesale_price' => 75.00,
            'cost' => 50.00,
            'quantity' => 10,
        ]);

        // Update the wholesale price
        $variant->update(['wholesale_price' => 80.00]);

        $this->assertDatabaseHas('activity_logs', [
            'store_id' => $this->store->id,
            'subject_type' => ProductVariant::class,
            'subject_id' => $variant->id,
            'activity_slug' => Activity::PRODUCTS_PRICE_CHANGE,
        ]);
    }

    public function test_variant_cost_change_logs_price_change_activity(): void
    {
        $this->actingAs($this->user);

        $variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'TEST-SKU-003',
            'price' => 100.00,
            'wholesale_price' => 75.00,
            'cost' => 50.00,
            'quantity' => 10,
        ]);

        // Update the cost
        $variant->update(['cost' => 55.00]);

        $this->assertDatabaseHas('activity_logs', [
            'store_id' => $this->store->id,
            'subject_type' => ProductVariant::class,
            'subject_id' => $variant->id,
            'activity_slug' => Activity::PRODUCTS_PRICE_CHANGE,
        ]);
    }

    public function test_variant_non_price_update_logs_regular_update_activity(): void
    {
        $this->actingAs($this->user);

        $variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'TEST-SKU-004',
            'price' => 100.00,
            'quantity' => 10,
        ]);

        // Update quantity (not a price field)
        $variant->update(['quantity' => 20]);

        // Should log a regular update, not a price change
        $this->assertDatabaseHas('activity_logs', [
            'store_id' => $this->store->id,
            'subject_type' => ProductVariant::class,
            'subject_id' => $variant->id,
            'activity_slug' => Activity::PRODUCTS_UPDATE,
        ]);

        // Should NOT have a price change activity for this update
        $priceChangeLogs = ActivityLog::where('subject_type', ProductVariant::class)
            ->where('subject_id', $variant->id)
            ->where('activity_slug', Activity::PRODUCTS_PRICE_CHANGE)
            ->count();

        $this->assertEquals(0, $priceChangeLogs);
    }

    public function test_variant_creation_logs_create_activity(): void
    {
        $this->actingAs($this->user);

        $variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'TEST-SKU-005',
            'price' => 100.00,
            'quantity' => 10,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'store_id' => $this->store->id,
            'subject_type' => ProductVariant::class,
            'subject_id' => $variant->id,
            'activity_slug' => Activity::PRODUCTS_CREATE,
        ]);
    }

    public function test_price_change_activity_captures_old_and_new_values(): void
    {
        $this->actingAs($this->user);

        $variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'TEST-SKU-006',
            'price' => 100.00,
            'wholesale_price' => 75.00,
            'cost' => 50.00,
            'quantity' => 10,
        ]);

        // Update multiple price fields
        $variant->update([
            'price' => 120.00,
            'wholesale_price' => 85.00,
        ]);

        $log = ActivityLog::where('subject_type', ProductVariant::class)
            ->where('subject_id', $variant->id)
            ->where('activity_slug', Activity::PRODUCTS_PRICE_CHANGE)
            ->first();

        $this->assertNotNull($log);
        $this->assertNotNull($log->properties);
        $this->assertArrayHasKey('old', $log->properties);
        $this->assertArrayHasKey('new', $log->properties);

        // Check that the old values are captured
        $this->assertEquals('100.00', $log->properties['old']['price']);
        $this->assertEquals('75.00', $log->properties['old']['wholesale_price']);

        // Check that the new values are captured
        $this->assertEquals('120.00', $log->properties['new']['price']);
        $this->assertEquals('85.00', $log->properties['new']['wholesale_price']);
    }
}
