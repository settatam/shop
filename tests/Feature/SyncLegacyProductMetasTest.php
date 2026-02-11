<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductTemplate;
use App\Models\ProductTemplateField;
use App\Models\ProductVariant;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SyncLegacyProductMetasTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_fails_when_legacy_store_not_found(): void
    {
        // Skip if legacy connection is not configured
        if (! config('database.connections.legacy')) {
            $this->markTestSkipped('Legacy database connection not configured');
        }

        $this->artisan('sync:legacy-product-metas', [
            '--store-id' => 99999,
            '--dry-run' => true,
        ])->assertExitCode(1);
    }

    public function test_command_displays_help(): void
    {
        $this->artisan('sync:legacy-product-metas', ['--help' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Sync product attribute values and tags from legacy database');
    }

    public function test_command_requires_template_mappings(): void
    {
        // Skip if legacy connection is not configured
        if (! config('database.connections.legacy')) {
            $this->markTestSkipped('Legacy database connection not configured');
        }

        $store = Store::factory()->create(['name' => 'Test Store']);

        // Create a product with template but no template mappings exist
        $template = ProductTemplate::factory()->create(['store_id' => $store->id]);
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'template_id' => $template->id,
        ]);

        ProductVariant::factory()->create(['product_id' => $product->id]);

        // Mock legacy store to exist
        DB::connection('legacy')->table('stores')->insert([
            'id' => 99998,
            'name' => 'Test Store',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('sync:legacy-product-metas', [
            '--store-id' => 99998,
            '--new-store-id' => $store->id,
            '--dry-run' => true,
        ])->assertExitCode(1);

        // Clean up
        DB::connection('legacy')->table('stores')->where('id', 99998)->delete();
    }

    public function test_sync_creates_attribute_values_for_products_without_values(): void
    {
        // Skip if legacy connection is not configured
        if (! config('database.connections.legacy')) {
            $this->markTestSkipped('Legacy database connection not configured');
        }

        $store = Store::factory()->create(['name' => 'Unique Test Store '.uniqid()]);
        $template = ProductTemplate::factory()->create([
            'store_id' => $store->id,
            'name' => 'Test Template',
        ]);

        $field = ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'name' => 'test_field',
            'canonical_name' => 'test_field',
        ]);

        $product = Product::factory()->create([
            'store_id' => $store->id,
            'template_id' => $template->id,
            'handle' => 'test-product-12345',
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'TEST-SKU-12345',
        ]);

        // Create legacy data
        $legacyStoreId = 99997;

        DB::connection('legacy')->table('stores')->insert([
            'id' => $legacyStoreId,
            'name' => $store->name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('legacy')->table('html_forms')->insert([
            'id' => 1001,
            'store_id' => $legacyStoreId,
            'title' => 'Test Template',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('legacy')->table('html_form_fields')->insert([
            'id' => 2001,
            'html_form_id' => 1001,
            'name' => 'test_field',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('legacy')->table('products')->insert([
            'id' => 12345,
            'store_id' => $legacyStoreId,
            'sku' => 'TEST-SKU-12345',
            'title' => 'Test Product',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('legacy')->table('metas')->insert([
            'id' => 3001,
            'metaable_type' => 'App\\Models\\Product',
            'metaable_id' => 12345,
            'field' => 'test_field',
            'value' => 'Test Value',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Verify no attribute values exist before sync
        $this->assertDatabaseMissing('product_attribute_values', [
            'product_id' => $product->id,
            'product_template_field_id' => $field->id,
        ]);

        // Run the sync command
        $this->artisan('sync:legacy-product-metas', [
            '--store-id' => $legacyStoreId,
            '--new-store-id' => $store->id,
            '--product-id' => $product->id,
        ])->assertSuccessful();

        // Verify attribute value was created
        $this->assertDatabaseHas('product_attribute_values', [
            'product_id' => $product->id,
            'product_template_field_id' => $field->id,
            'value' => 'Test Value',
        ]);

        // Clean up legacy data
        DB::connection('legacy')->table('metas')->where('id', 3001)->delete();
        DB::connection('legacy')->table('products')->where('id', 12345)->delete();
        DB::connection('legacy')->table('html_form_fields')->where('id', 2001)->delete();
        DB::connection('legacy')->table('html_forms')->where('id', 1001)->delete();
        DB::connection('legacy')->table('stores')->where('id', $legacyStoreId)->delete();
    }

    public function test_sync_skips_existing_values_without_force_flag(): void
    {
        // Skip if legacy connection is not configured
        if (! config('database.connections.legacy')) {
            $this->markTestSkipped('Legacy database connection not configured');
        }

        $store = Store::factory()->create(['name' => 'Skip Test Store '.uniqid()]);
        $template = ProductTemplate::factory()->create([
            'store_id' => $store->id,
            'name' => 'Skip Template',
        ]);

        $field = ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'name' => 'skip_field',
            'canonical_name' => 'skip_field',
        ]);

        $product = Product::factory()->create([
            'store_id' => $store->id,
            'template_id' => $template->id,
            'handle' => 'skip-product-12346',
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'SKIP-SKU-12346',
        ]);

        // Create existing attribute value
        ProductAttributeValue::create([
            'product_id' => $product->id,
            'product_template_field_id' => $field->id,
            'value' => 'Original Value',
        ]);

        // Create legacy data
        $legacyStoreId = 99996;

        DB::connection('legacy')->table('stores')->insert([
            'id' => $legacyStoreId,
            'name' => $store->name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('legacy')->table('html_forms')->insert([
            'id' => 1002,
            'store_id' => $legacyStoreId,
            'title' => 'Skip Template',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('legacy')->table('html_form_fields')->insert([
            'id' => 2002,
            'html_form_id' => 1002,
            'name' => 'skip_field',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('legacy')->table('products')->insert([
            'id' => 12346,
            'store_id' => $legacyStoreId,
            'sku' => 'SKIP-SKU-12346',
            'title' => 'Skip Product',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('legacy')->table('metas')->insert([
            'id' => 3002,
            'metaable_type' => 'App\\Models\\Product',
            'metaable_id' => 12346,
            'field' => 'skip_field',
            'value' => 'New Value From Legacy',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Run the sync command without --force
        $this->artisan('sync:legacy-product-metas', [
            '--store-id' => $legacyStoreId,
            '--new-store-id' => $store->id,
            '--product-id' => $product->id,
        ])->assertSuccessful();

        // Verify original value is preserved
        $this->assertDatabaseHas('product_attribute_values', [
            'product_id' => $product->id,
            'product_template_field_id' => $field->id,
            'value' => 'Original Value',
        ]);

        // Clean up legacy data
        DB::connection('legacy')->table('metas')->where('id', 3002)->delete();
        DB::connection('legacy')->table('products')->where('id', 12346)->delete();
        DB::connection('legacy')->table('html_form_fields')->where('id', 2002)->delete();
        DB::connection('legacy')->table('html_forms')->where('id', 1002)->delete();
        DB::connection('legacy')->table('stores')->where('id', $legacyStoreId)->delete();
    }

    public function test_sync_updates_existing_values_with_force_flag(): void
    {
        // Skip if legacy connection is not configured
        if (! config('database.connections.legacy')) {
            $this->markTestSkipped('Legacy database connection not configured');
        }

        $store = Store::factory()->create(['name' => 'Force Test Store '.uniqid()]);
        $template = ProductTemplate::factory()->create([
            'store_id' => $store->id,
            'name' => 'Force Template',
        ]);

        $field = ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'name' => 'force_field',
            'canonical_name' => 'force_field',
        ]);

        $product = Product::factory()->create([
            'store_id' => $store->id,
            'template_id' => $template->id,
            'handle' => 'force-product-12347',
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'FORCE-SKU-12347',
        ]);

        // Create existing attribute value
        ProductAttributeValue::create([
            'product_id' => $product->id,
            'product_template_field_id' => $field->id,
            'value' => 'Old Value',
        ]);

        // Create legacy data
        $legacyStoreId = 99995;

        DB::connection('legacy')->table('stores')->insert([
            'id' => $legacyStoreId,
            'name' => $store->name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('legacy')->table('html_forms')->insert([
            'id' => 1003,
            'store_id' => $legacyStoreId,
            'title' => 'Force Template',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('legacy')->table('html_form_fields')->insert([
            'id' => 2003,
            'html_form_id' => 1003,
            'name' => 'force_field',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('legacy')->table('products')->insert([
            'id' => 12347,
            'store_id' => $legacyStoreId,
            'sku' => 'FORCE-SKU-12347',
            'title' => 'Force Product',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('legacy')->table('metas')->insert([
            'id' => 3003,
            'metaable_type' => 'App\\Models\\Product',
            'metaable_id' => 12347,
            'field' => 'force_field',
            'value' => 'Updated Value From Legacy',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Run the sync command with --force
        $this->artisan('sync:legacy-product-metas', [
            '--store-id' => $legacyStoreId,
            '--new-store-id' => $store->id,
            '--product-id' => $product->id,
            '--force' => true,
        ])->assertSuccessful();

        // Verify value was updated
        $this->assertDatabaseHas('product_attribute_values', [
            'product_id' => $product->id,
            'product_template_field_id' => $field->id,
            'value' => 'Updated Value From Legacy',
        ]);

        // Clean up legacy data
        DB::connection('legacy')->table('metas')->where('id', 3003)->delete();
        DB::connection('legacy')->table('products')->where('id', 12347)->delete();
        DB::connection('legacy')->table('html_form_fields')->where('id', 2003)->delete();
        DB::connection('legacy')->table('html_forms')->where('id', 1003)->delete();
        DB::connection('legacy')->table('stores')->where('id', $legacyStoreId)->delete();
    }
}
