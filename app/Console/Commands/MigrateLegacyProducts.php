<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Image;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductTemplate;
use App\Models\ProductTemplateField;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\Vendor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MigrateLegacyProducts extends Command
{
    protected $signature = 'migrate:legacy-products
                            {--store-id=63 : Legacy store ID to migrate}
                            {--new-store-id= : New store ID (if different from legacy)}
                            {--limit=0 : Number of products to migrate (0 for all)}
                            {--dry-run : Show what would be migrated without making changes}
                            {--fresh : Delete existing products and start fresh}
                            {--skip-deleted : Skip soft-deleted products}';

    protected $description = 'Migrate products and variants from the legacy database';

    protected array $productMap = [];

    protected array $variantMap = [];

    protected array $categoryMap = [];

    protected array $templateMap = [];

    protected array $vendorMap = [];

    /**
     * Maps legacy html_form_field.id => new product_template_field.id
     */
    protected array $templateFieldMap = [];

    /**
     * Maps legacy html_form_field.name => new product_template_field.id (by template)
     * Structure: [template_id => [field_name => field_id]]
     */
    protected array $templateFieldNameMap = [];

    protected int $attributeValueCount = 0;

    protected int $imageCount = 0;

    public function handle(): int
    {
        $legacyStoreId = (int) $this->option('store-id');
        $newStoreId = $this->option('new-store-id') ? (int) $this->option('new-store-id') : null;
        $limit = (int) $this->option('limit');
        $isDryRun = $this->option('dry-run');
        $skipDeleted = $this->option('skip-deleted');

        $this->info("Starting product migration from legacy store ID: {$legacyStoreId}");

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        // Get legacy store info
        $legacyStore = DB::connection('legacy')
            ->table('stores')
            ->where('id', $legacyStoreId)
            ->first();

        if (! $legacyStore) {
            $this->error("Legacy store with ID {$legacyStoreId} not found");

            return 1;
        }

        // Find the new store
        $newStore = null;
        if ($newStoreId) {
            $newStore = Store::find($newStoreId);
        } else {
            $newStore = Store::where('name', $legacyStore->name)->first();
        }

        if (! $newStore) {
            $this->error('New store not found. Run migrate:legacy first to create the store.');

            return 1;
        }

        $this->info("Migrating products to store: {$newStore->name} (ID: {$newStore->id})");

        // Load mapping files from previous migrations
        $this->loadMappingFiles($legacyStoreId);

        if ($this->option('fresh') && ! $isDryRun) {
            $shouldCleanup = ! $this->input->isInteractive() || $this->confirm('This will delete all existing products for this store. Continue?');
            if ($shouldCleanup) {
                $this->cleanupExistingProducts($newStore);
            }
        }

        try {
            DB::beginTransaction();

            // Build category mapping from existing categories
            $this->buildCategoryMapping($legacyStoreId, $newStore);

            // Build template mapping
            $this->buildTemplateMapping($legacyStoreId, $newStore);

            // Build vendor mapping
            $this->buildVendorMapping($legacyStoreId, $newStore);

            // Migrate products
            $this->migrateProducts($legacyStoreId, $newStore, $isDryRun, $limit, $skipDeleted);

            if ($isDryRun) {
                DB::rollBack();
                $this->info('Dry run complete - no changes made');
            } else {
                DB::commit();
                $this->info('Product migration complete!');
            }

            $this->displaySummary($newStore);

            // Save mapping files
            if (! $isDryRun && count($this->productMap) > 0) {
                $this->saveMappingFiles($legacyStoreId);
            }

            return 0;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Migration failed: {$e->getMessage()}");
            $this->error($e->getTraceAsString());

            return 1;
        }
    }

    protected function loadMappingFiles(int $legacyStoreId): void
    {
        $basePath = storage_path('app/migration_maps');

        // Load category map
        $categoryMapFile = "{$basePath}/category_map_{$legacyStoreId}.json";
        if (file_exists($categoryMapFile)) {
            $this->categoryMap = json_decode(file_get_contents($categoryMapFile), true) ?? [];
            $this->line('  Loaded '.count($this->categoryMap).' category mappings');
        }

        // Load template map
        $templateMapFile = "{$basePath}/template_map_{$legacyStoreId}.json";
        if (file_exists($templateMapFile)) {
            $this->templateMap = json_decode(file_get_contents($templateMapFile), true) ?? [];
            $this->line('  Loaded '.count($this->templateMap).' template mappings');
        }

        // Load vendor map
        $vendorMapFile = "{$basePath}/vendor_map_{$legacyStoreId}.json";
        if (file_exists($vendorMapFile)) {
            $this->vendorMap = json_decode(file_get_contents($vendorMapFile), true) ?? [];
            $this->line('  Loaded '.count($this->vendorMap).' vendor mappings');
        }
    }

    protected function saveMappingFiles(int $legacyStoreId): void
    {
        $basePath = storage_path('app/migration_maps');
        if (! is_dir($basePath)) {
            mkdir($basePath, 0755, true);
        }

        // Save product map
        $productMapFile = "{$basePath}/product_map_{$legacyStoreId}.json";
        file_put_contents($productMapFile, json_encode($this->productMap, JSON_PRETTY_PRINT));
        $this->line("  Product map saved to: {$productMapFile}");

        // Save variant map
        $variantMapFile = "{$basePath}/variant_map_{$legacyStoreId}.json";
        file_put_contents($variantMapFile, json_encode($this->variantMap, JSON_PRETTY_PRINT));
        $this->line("  Variant map saved to: {$variantMapFile}");
    }

    protected function buildCategoryMapping(int $legacyStoreId, Store $newStore): void
    {
        if (! empty($this->categoryMap)) {
            return;
        }

        $this->info('Building category mapping...');

        // Get legacy categories
        $legacyCategories = DB::connection('legacy')
            ->table('store_categories')
            ->where('store_id', $legacyStoreId)

            ->get();

        // Get new categories
        $newCategories = Category::where('store_id', $newStore->id)->get();

        // Map by name (normalized)
        $newCategoriesByName = $newCategories->keyBy(fn ($c) => Str::slug($c->name));

        foreach ($legacyCategories as $legacy) {
            $slug = Str::slug($legacy->name);
            if ($newCategoriesByName->has($slug)) {
                $this->categoryMap[$legacy->id] = $newCategoriesByName->get($slug)->id;
            }
        }

        $this->line('  Mapped '.count($this->categoryMap).' categories');
    }

    protected function buildTemplateMapping(int $legacyStoreId, Store $newStore): void
    {
        if (! empty($this->templateMap)) {
            return;
        }

        $this->info('Building template mapping...');

        // Get legacy templates (html_forms) only from this store
        $legacyTemplates = DB::connection('legacy')
            ->table('html_forms')
            ->where('store_id', $legacyStoreId)
            ->get();

        // Get new templates
        $newTemplates = ProductTemplate::where('store_id', $newStore->id)->get();

        // Map by name
        $newTemplatesByName = $newTemplates->keyBy(fn ($t) => strtolower($t->name));

        foreach ($legacyTemplates as $legacy) {
            $name = strtolower($legacy->title);
            if ($newTemplatesByName->has($name)) {
                $this->templateMap[$legacy->id] = $newTemplatesByName->get($name)->id;
            }
        }

        $this->line('  Mapped '.count($this->templateMap).' templates');

        // Also build template field mapping
        $this->buildTemplateFieldMapping($legacyStoreId, $newStore);
    }

    /**
     * Build mapping from legacy html_form_fields to new product_template_fields.
     * Maps by field name within each template.
     */
    protected function buildTemplateFieldMapping(int $legacyStoreId, Store $newStore): void
    {
        $this->info('Building template field mapping...');

        $fieldCount = 0;

        foreach ($this->templateMap as $legacyTemplateId => $newTemplateId) {
            // Get legacy fields for this template
            $legacyFields = DB::connection('legacy')
                ->table('html_form_fields')
                ->where('html_form_id', $legacyTemplateId)
                ->get();

            // Get new fields for this template
            $newFields = ProductTemplateField::where('product_template_id', $newTemplateId)->get();

            // Build a map by canonical_name (which stores the original legacy name)
            $newFieldsByCanonicalName = $newFields->keyBy(fn ($f) => strtolower($f->canonical_name ?? $f->name));

            // Also map by snake_case name for fallback
            $newFieldsByName = $newFields->keyBy(fn ($f) => strtolower($f->name));

            $this->templateFieldNameMap[$newTemplateId] = [];

            foreach ($legacyFields as $legacyField) {
                $legacyName = strtolower($legacyField->name);

                // Try canonical name first, then snake_case name
                $newField = $newFieldsByCanonicalName->get($legacyName)
                    ?? $newFieldsByName->get(Str::snake($legacyName));

                if ($newField) {
                    $this->templateFieldMap[$legacyField->id] = $newField->id;
                    $this->templateFieldNameMap[$newTemplateId][$legacyName] = $newField->id;
                    $fieldCount++;
                }
            }
        }

        $this->line("  Mapped {$fieldCount} template fields");
    }

    protected function buildVendorMapping(int $legacyStoreId, Store $newStore): void
    {
        if (! empty($this->vendorMap)) {
            return;
        }

        $this->info('Building vendor mapping...');

        // Get legacy vendors
        $legacyVendors = DB::connection('legacy')
            ->table('vendors')
            ->where('store_id', $legacyStoreId)

            ->get();

        // Get new vendors
        $newVendors = Vendor::where('store_id', $newStore->id)->get();

        foreach ($legacyVendors as $legacy) {
            $legacyName = trim(($legacy->first_name ?? '').' '.($legacy->last_name ?? ''));
            if (empty($legacyName)) {
                $legacyName = $legacy->company;
            }

            foreach ($newVendors as $new) {
                if (strtolower($new->name) === strtolower($legacyName) ||
                    ($legacy->email && strtolower($new->email) === strtolower($legacy->email))) {
                    $this->vendorMap[$legacy->id] = $new->id;
                    break;
                }
            }
        }

        $this->line('  Mapped '.count($this->vendorMap).' vendors');
    }

    protected function migrateProducts(int $legacyStoreId, Store $newStore, bool $isDryRun, int $limit, bool $skipDeleted): void
    {
        $this->info('Migrating products...');

        $query = DB::connection('legacy')
            ->table('products')
            ->where('store_id', $legacyStoreId);

        if ($skipDeleted) {
            $query->whereNull('deleted_at');
        }

        $query->orderBy('id', 'asc');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $legacyProducts = $query->get();
        $productCount = 0;
        $variantCount = 0;
        $skipped = 0;

        foreach ($legacyProducts as $legacyProduct) {
            // Check if product already exists by SKU
            $existingProduct = null;
            if ($legacyProduct->sku) {
                $existingProduct = Product::where('store_id', $newStore->id)
                    ->whereHas('variants', fn ($q) => $q->where('sku', $legacyProduct->sku))
                    ->first();
            }

            if (! $existingProduct && $legacyProduct->handle) {
                $existingProduct = Product::where('store_id', $newStore->id)
                    ->where('handle', $legacyProduct->handle)
                    ->first();
            }

            if ($existingProduct) {
                $this->productMap[$legacyProduct->id] = $existingProduct->id;
                $skipped++;

                continue;
            }

            if ($isDryRun) {
                $title = $legacyProduct->title ?? $legacyProduct->product_name ?? "Product #{$legacyProduct->id}";
                $this->line("  Would create product: {$title} (SKU: {$legacyProduct->sku})");
                $productCount++;

                continue;
            }

            // Map category
            $categoryId = null;
            if ($legacyProduct->store_category_id && isset($this->categoryMap[$legacyProduct->store_category_id])) {
                $categoryId = $this->categoryMap[$legacyProduct->store_category_id];
            }

            // Map template
            $templateId = null;
            if ($legacyProduct->template_id && isset($this->templateMap[$legacyProduct->template_id])) {
                $templateId = $this->templateMap[$legacyProduct->template_id];
            }

            // Map vendor
            $vendorId = null;
            if ($legacyProduct->vendor_id && isset($this->vendorMap[$legacyProduct->vendor_id])) {
                $vendorId = $this->vendorMap[$legacyProduct->vendor_id];
            }

            // Generate unique handle - always include legacy ID to ensure uniqueness
            $titleSlug = Str::slug($legacyProduct->title ?? $legacyProduct->product_name ?? 'product');
            $handle = $titleSlug ? "{$titleSlug}-{$legacyProduct->id}" : "product-{$legacyProduct->id}";

            // Use DB::table to preserve timestamps from legacy data
            $newProductId = DB::table('products')->insertGetId([
                'store_id' => $newStore->id,
                'title' => $legacyProduct->title ?? $legacyProduct->product_name ?? "Product #{$legacyProduct->id}",
                'description' => $legacyProduct->description,
                'category_id' => $categoryId,
                'template_id' => $templateId,
                'vendor_id' => $vendorId,
                'handle' => $handle,
                'weight' => $legacyProduct->weight,
                'compare_at_price' => $legacyProduct->compare_at_price,
                'upc' => $legacyProduct->upc,
                'ean' => $legacyProduct->ean,
                'jan' => $legacyProduct->jan,
                'isbn' => $legacyProduct->isbn,
                'mpn' => $legacyProduct->mpn,
                'length' => $legacyProduct->length ?? $legacyProduct->package_length,
                'width' => $legacyProduct->width ?? $legacyProduct->package_width,
                'height' => $legacyProduct->height ?? $legacyProduct->package_height,
                'country_of_origin' => $legacyProduct->country_of_origin,
                'has_variants' => (bool) $legacyProduct->has_variants,
                'is_published' => (bool) $legacyProduct->is_published,
                'is_draft' => (bool) $legacyProduct->is_draft,
                'seo_description' => $legacyProduct->seo_description,
                'seo_page_title' => $legacyProduct->seo_page_title,
                'track_quantity' => (bool) $legacyProduct->track_quantity,
                'sell_out_of_stock' => (bool) $legacyProduct->sell_out_of_stock,
                'charge_taxes' => (bool) $legacyProduct->charge_taxes,
                'quantity' => $legacyProduct->quantity ?? $legacyProduct->total_quantity ?? 0,
                'created_at' => $legacyProduct->created_at,
                'updated_at' => $legacyProduct->updated_at,
                'deleted_at' => ($legacyProduct->deleted_at && ! $skipDeleted) ? $legacyProduct->deleted_at : null,
            ]);

            $newProduct = Product::withTrashed()->find($newProductId);

            $this->productMap[$legacyProduct->id] = $newProduct->id;
            $productCount++;

            // Migrate variants for this product
            $legacyVariants = DB::connection('legacy')
                ->table('product_variants')
                ->where('product_id', $legacyProduct->id)

                ->get();

            if ($legacyVariants->isEmpty()) {
                // Create default variant from product data - use DB::table to preserve timestamps
                DB::table('product_variants')->insert([
                    'product_id' => $newProduct->id,
                    'sku' => $legacyProduct->sku ?? "SKU-{$newProduct->id}",
                    'price' => $legacyProduct->price ?? 0,
                    'wholesale_price' => $legacyProduct->wholesale_price ?? 0,
                    'cost' => $legacyProduct->cost_per_item,
                    'quantity' => $legacyProduct->quantity ?? $legacyProduct->total_quantity ?? 0,
                    'barcode' => $legacyProduct->upc,
                    'status' => $legacyProduct->status ?? 'active',
                    'is_active' => true,
                    'created_at' => $legacyProduct->created_at,
                    'updated_at' => $legacyProduct->updated_at,
                ]);
                $variantCount++;
            } else {
                foreach ($legacyVariants as $legacyVariant) {
                    // Use DB::table to preserve timestamps
                    $newVariantId = DB::table('product_variants')->insertGetId([
                        'product_id' => $newProduct->id,
                        'sku' => $legacyVariant->sku ?? "SKU-{$newProduct->id}-{$legacyVariant->id}",
                        'price' => $legacyVariant->price ?? 0,
                        'cost' => $legacyVariant->cost_per_item,
                        'quantity' => $legacyVariant->quantity ?? 0,
                        'barcode' => $legacyVariant->barcode,
                        'status' => $legacyVariant->status ?? 'active',
                        'sort_order' => $legacyVariant->sort_order ?? 0,
                        'is_active' => (bool) ($legacyVariant->is_active ?? true),
                        'created_at' => $legacyVariant->created_at,
                        'updated_at' => $legacyVariant->updated_at,
                    ]);

                    $this->variantMap[$legacyVariant->id] = $newVariantId;
                    $variantCount++;
                }
            }

            // Migrate product attribute values (metas)
            $this->migrateProductMetas($legacyProduct, $newProduct, $templateId);

            // Migrate product images
            $this->migrateProductImages($legacyProduct, $newProduct);

            if ($productCount % 100 === 0) {
                $this->line("  Processed {$productCount} products...");
            }
        }

        $this->line("  Created {$productCount} products with {$variantCount} variants, skipped {$skipped} existing");
        $this->line("  Migrated {$this->attributeValueCount} attribute values and {$this->imageCount} images");
    }

    /**
     * Migrate product attribute values from the legacy metas table.
     *
     * Legacy structure:
     * - metas.metable_type = 'App\Models\Product'
     * - metas.metable_id = product ID
     * - metas.field = field name (matches html_form_fields.name)
     * - metas.value = the actual value
     *
     * New structure:
     * - product_attribute_values.product_id
     * - product_attribute_values.product_template_field_id
     * - product_attribute_values.value
     */
    protected function migrateProductMetas(object $legacyProduct, Product $newProduct, ?int $templateId): void
    {
        if (! $templateId) {
            return;
        }

        // Get metas for this product (legacy uses metaable_type/metaable_id with double 'a')
        $legacyMetas = DB::connection('legacy')
            ->table('metas')
            ->where('metaable_type', 'App\\Models\\Product')
            ->where('metaable_id', $legacyProduct->id)
            ->whereNull('deleted_at')
            ->get();

        if ($legacyMetas->isEmpty()) {
            return;
        }

        // Get the field mapping for this template
        $fieldMap = $this->templateFieldNameMap[$templateId] ?? [];

        if (empty($fieldMap)) {
            return;
        }

        foreach ($legacyMetas as $meta) {
            // Skip empty values
            if ($meta->value === null || $meta->value === '') {
                continue;
            }

            $fieldName = strtolower($meta->field);
            $newFieldId = $fieldMap[$fieldName] ?? null;

            if (! $newFieldId) {
                // Try matching with underscores (legacy uses hyphens, new uses underscores)
                $normalizedName = str_replace('-', '_', $fieldName);
                $newFieldId = $fieldMap[$normalizedName] ?? null;
            }

            if (! $newFieldId) {
                // Try matching with snake_case conversion
                $newFieldId = $fieldMap[Str::snake($fieldName)] ?? null;
            }

            if (! $newFieldId) {
                continue;
            }

            // Check if this attribute value already exists
            $existingValue = ProductAttributeValue::where('product_id', $newProduct->id)
                ->where('product_template_field_id', $newFieldId)
                ->first();

            if ($existingValue) {
                continue;
            }

            // Use DB::table to preserve timestamps
            DB::table('product_attribute_values')->insert([
                'product_id' => $newProduct->id,
                'product_template_field_id' => $newFieldId,
                'value' => $meta->value,
                'created_at' => $meta->created_at ?? $legacyProduct->created_at,
                'updated_at' => $meta->updated_at ?? $legacyProduct->updated_at,
            ]);

            $this->attributeValueCount++;
        }
    }

    /**
     * Migrate product images from the legacy polymorphic images table.
     *
     * Legacy columns: imageable_type, imageable_id, url, thumbnail, rank
     * New columns: imageable_type, imageable_id, url, thumbnail_url, sort_order, is_primary
     */
    protected function migrateProductImages(object $legacyProduct, Product $newProduct): void
    {
        // Get images for this product from legacy polymorphic images table
        $legacyImages = DB::connection('legacy')
            ->table('images')
            ->where('imageable_type', 'App\\Models\\Product')
            ->where('imageable_id', $legacyProduct->id)
            ->whereNull('deleted_at')
            ->orderBy('rank')
            ->get();

        if ($legacyImages->isEmpty()) {
            return;
        }

        foreach ($legacyImages as $legacyImage) {
            // Get the image URL
            $url = $legacyImage->url ?? null;

            if (! $url) {
                continue;
            }

            // Check if this image already exists
            $existingImage = DB::table('images')
                ->where('imageable_type', 'App\\Models\\Product')
                ->where('imageable_id', $newProduct->id)
                ->where('url', $url)
                ->first();

            if ($existingImage) {
                continue;
            }

            // rank=1 is primary
            $rank = $legacyImage->rank ?? 0;

            // Use DB::table to preserve timestamps - insert into polymorphic images table
            DB::table('images')->insert([
                'store_id' => $newProduct->store_id,
                'imageable_type' => 'App\\Models\\Product',
                'imageable_id' => $newProduct->id,
                'path' => $url, // Store the URL in path field as well
                'url' => $url,
                'thumbnail_url' => $legacyImage->thumbnail,
                'alt_text' => $newProduct->title,
                'sort_order' => $rank,
                'is_primary' => $rank === 1,
                'created_at' => $legacyImage->created_at ?? $legacyProduct->created_at,
                'updated_at' => $legacyImage->updated_at ?? $legacyProduct->updated_at,
            ]);

            $this->imageCount++;
        }
    }

    protected function cleanupExistingProducts(Store $newStore): void
    {
        $this->warn('Cleaning up existing products...');

        $productIds = Product::where('store_id', $newStore->id)->pluck('id');

        // Delete in order of dependencies
        ProductAttributeValue::whereIn('product_id', $productIds)->delete();
        Image::where('imageable_type', 'App\\Models\\Product')
            ->whereIn('imageable_id', $productIds)
            ->delete();
        ProductVariant::whereIn('product_id', $productIds)->forceDelete();
        Product::where('store_id', $newStore->id)->forceDelete();

        $this->line('  Cleanup complete');
    }

    protected function displaySummary(Store $newStore): void
    {
        $this->newLine();
        $this->info('=== Product Migration Summary ===');
        $this->line('Store: '.$newStore->name.' (ID: '.$newStore->id.')');
        $this->line('Products mapped: '.count($this->productMap));
        $this->line('Variants mapped: '.count($this->variantMap));
        $this->line('Template fields mapped: '.count($this->templateFieldMap));

        $productIds = Product::where('store_id', $newStore->id)->pluck('id');
        $productCount = $productIds->count();
        $variantCount = ProductVariant::whereIn('product_id', $productIds)->count();
        $attributeCount = ProductAttributeValue::whereIn('product_id', $productIds)->count();
        $imageCount = DB::table('images')
            ->where('imageable_type', 'App\\Models\\Product')
            ->whereIn('imageable_id', $productIds)
            ->count();

        $this->line("Total products in store: {$productCount}");
        $this->line("Total variants in store: {$variantCount}");
        $this->line("Total attribute values in store: {$attributeCount}");
        $this->line("Total images in store: {$imageCount}");
    }
}
