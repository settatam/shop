<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Image;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductTemplate;
use App\Models\ProductTemplateField;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\Vendor;
use App\Models\Warehouse;
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
                            {--skip-deleted : Skip soft-deleted products}
                            {--skip-categories : Skip building category mappings}
                            {--skip-templates : Skip building template mappings}
                            {--sync-deletes : Soft-delete new records if legacy record is soft-deleted}';

    protected $description = 'Migrate products and variants from the legacy database';

    protected array $productMap = [];

    protected array $variantMap = [];

    protected array $categoryMap = [];

    protected array $templateMap = [];

    protected array $vendorMap = [];

    protected array $unmappedVendors = [];

    /**
     * Maps legacy html_form_field.id => new product_template_field.id
     */
    protected array $templateFieldMap = [];

    /**
     * Maps legacy html_form_field.name => new product_template_field.id (by template)
     * Structure: [template_id => [field_name => field_id]]
     */
    protected array $templateFieldNameMap = [];

    /**
     * Maps template_id => [field_name => field_type]
     */
    protected array $templateFieldTypeMap = [];

    /**
     * Maps template_id => [field_name => select_options]
     */
    protected array $templateFieldOptionsMap = [];

    protected int $attributeValueCount = 0;

    protected int $imageCount = 0;

    protected int $inventoryCount = 0;

    protected ?Warehouse $defaultWarehouse = null;

    protected ?int $legacyStoreLocationId = null;

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

        $syncDeletes = $this->option('sync-deletes');

        try {
            DB::beginTransaction();

            // Build category mapping from existing categories
            if (! $this->option('skip-categories')) {
                $this->buildCategoryMapping($legacyStoreId, $newStore);
            } else {
                $this->info('Skipping category mapping (--skip-categories)');
            }

            // Build template mapping
            if (! $this->option('skip-templates')) {
                $this->buildTemplateMapping($legacyStoreId, $newStore);
            } else {
                $this->info('Skipping template mapping (--skip-templates)');
            }

            // Build vendor mapping
            $this->buildVendorMapping($legacyStoreId, $newStore);

            // Migrate products
            $this->migrateProducts($legacyStoreId, $newStore, $isDryRun, $limit, $skipDeleted, $syncDeletes);

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
            ->get()
            ->keyBy('id');

        // Build legacy category full paths
        $legacyPaths = [];
        foreach ($legacyCategories as $legacy) {
            $legacyPaths[$legacy->id] = $this->buildLegacyCategoryPath($legacy, $legacyCategories);
        }

        // Get new categories with parent relationship loaded
        $newCategories = Category::where('store_id', $newStore->id)->get();

        // Build new category full paths and index by path
        $newCategoriesByPath = [];
        foreach ($newCategories as $category) {
            $path = Str::slug($category->full_path, '-');
            $newCategoriesByPath[$path] = $category;
        }

        // Also index by name only as fallback for categories without path conflicts
        $newCategoriesByName = [];
        $nameConflicts = [];
        foreach ($newCategories as $category) {
            $slug = Str::slug($category->name);
            if (isset($newCategoriesByName[$slug])) {
                $nameConflicts[$slug] = true;
            }
            $newCategoriesByName[$slug] = $category;
        }

        // Map categories - prefer full path match, fall back to name if no conflict
        foreach ($legacyCategories as $legacy) {
            $legacyPath = $legacyPaths[$legacy->id];
            $pathSlug = Str::slug($legacyPath, '-');
            $nameSlug = Str::slug($legacy->name);

            // Try full path match first
            if (isset($newCategoriesByPath[$pathSlug])) {
                $this->categoryMap[$legacy->id] = $newCategoriesByPath[$pathSlug]->id;
            }
            // Fall back to name match only if there's no conflict
            elseif (isset($newCategoriesByName[$nameSlug]) && ! isset($nameConflicts[$nameSlug])) {
                $this->categoryMap[$legacy->id] = $newCategoriesByName[$nameSlug]->id;
            }
        }

        $this->line('  Mapped '.count($this->categoryMap).' categories');
    }

    /**
     * Build the full path for a legacy category.
     */
    protected function buildLegacyCategoryPath(object $category, $allCategories): string
    {
        $path = [$category->name];
        $current = $category;

        while ($current->parent_id && isset($allCategories[$current->parent_id])) {
            $current = $allCategories[$current->parent_id];
            array_unshift($path, $current->name);
        }

        return implode(' > ', $path);
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
            $this->templateFieldTypeMap[$newTemplateId] = [];
            $this->templateFieldOptionsMap[$newTemplateId] = [];

            foreach ($legacyFields as $legacyField) {
                $legacyName = strtolower($legacyField->name);

                // Try canonical name first, then snake_case name
                $newField = $newFieldsByCanonicalName->get($legacyName)
                    ?? $newFieldsByName->get(Str::snake($legacyName))
                    ?? $newFieldsByName->get(str_replace('-', '_', $legacyName));

                if ($newField) {
                    $this->templateFieldMap[$legacyField->id] = $newField->id;
                    $this->templateFieldNameMap[$newTemplateId][$legacyName] = $newField->id;
                    $this->templateFieldTypeMap[$newTemplateId][$legacyName] = $newField->type;

                    // Store select options for value transformation
                    if ($newField->type === 'select' && $newField->options) {
                        $options = $newField->options;
                        if (is_string($options)) {
                            $options = json_decode($options, true);
                        } elseif ($options instanceof \Illuminate\Support\Collection) {
                            $options = $options->toArray();
                        }
                        $this->templateFieldOptionsMap[$newTemplateId][$legacyName] = $options ?? [];
                    }

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

        // Get legacy vendors from customers table (where is_vendor = 1)
        $legacyVendors = DB::connection('legacy')
            ->table('customers')
            ->where('store_id', $legacyStoreId)
            ->where('is_vendor', true)
            ->whereNull('deleted_at')
            ->get();

        // Get new vendors
        $newVendors = Vendor::where('store_id', $newStore->id)->get();

        foreach ($legacyVendors as $legacy) {
            $legacyName = trim(($legacy->first_name ?? '').' '.($legacy->last_name ?? ''));
            if (empty($legacyName)) {
                $legacyName = $legacy->company_name ?? '';
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

        // Save vendor map for future runs
        $basePath = storage_path('app/migrations');
        if (! is_dir($basePath)) {
            mkdir($basePath, 0755, true);
        }
        $vendorMapFile = "{$basePath}/vendor_map_{$legacyStoreId}.json";
        file_put_contents($vendorMapFile, json_encode($this->vendorMap, JSON_PRETTY_PRINT));
        $this->line("  Saved vendor map to {$vendorMapFile}");
    }

    protected function migrateProducts(int $legacyStoreId, Store $newStore, bool $isDryRun, int $limit, bool $skipDeleted, bool $syncDeletes = false): void
    {
        $this->info('Migrating products...');

        if ($syncDeletes) {
            $this->info('  Sync deletes enabled - will soft-delete products if legacy is soft-deleted');
        }

        // Get or create default warehouse for inventory
        $this->defaultWarehouse = Warehouse::where('store_id', $newStore->id)
            ->where('is_default', true)
            ->first();

        if (! $this->defaultWarehouse) {
            $this->defaultWarehouse = Warehouse::where('store_id', $newStore->id)->first();
        }

        if (! $this->defaultWarehouse && ! $isDryRun) {
            $this->defaultWarehouse = Warehouse::create([
                'store_id' => $newStore->id,
                'name' => 'Main Warehouse',
                'code' => 'MAIN',
                'is_default' => true,
                'is_active' => true,
            ]);
            $this->line("  Created default warehouse: {$this->defaultWarehouse->name}");
        }

        // Get legacy store location for inventory quantities
        $this->getLegacyStoreLocationId($legacyStoreId);
        if ($this->legacyStoreLocationId) {
            $this->line("  Using legacy store location ID: {$this->legacyStoreLocationId}");
        } else {
            $this->warn('  No legacy store location found - inventory quantities will be 0');
        }

        $this->line('  Counting legacy products...');

        $query = DB::connection('legacy')
            ->table('products')
            ->where('store_id', $legacyStoreId);

        if ($skipDeleted) {
            $query->whereNull('deleted_at');
        }

        $totalCount = $query->count();
        $this->line("  Found {$totalCount} legacy products");

        $query->orderBy('id', 'asc');

        if ($limit > 0) {
            $query->limit($limit);
            $this->line("  Limiting to {$limit} products");
        }

        $this->line('  Fetching products from legacy database...');
        $legacyProducts = $query->get();
        $this->line('  Products fetched, starting migration...');

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

                // Sync soft-delete status if enabled
                if ($syncDeletes && $legacyProduct->deleted_at && ! $existingProduct->deleted_at) {
                    if (! $isDryRun) {
                        $existingProduct->delete();
                        $this->line("  Soft-deleted product #{$existingProduct->id} (legacy was deleted)");
                    } else {
                        $this->line("  Would soft-delete product #{$existingProduct->id} (legacy was deleted)");
                    }
                }

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
            if ($legacyProduct->vendor_id) {
                if (isset($this->vendorMap[$legacyProduct->vendor_id])) {
                    $vendorId = $this->vendorMap[$legacyProduct->vendor_id];
                } else {
                    $this->unmappedVendors[$legacyProduct->vendor_id] = ($this->unmappedVendors[$legacyProduct->vendor_id] ?? 0) + 1;
                }
            }

            // Generate unique handle - always include legacy ID to ensure uniqueness
            $titleSlug = Str::slug($legacyProduct->title ?? $legacyProduct->product_name ?? 'product');
            $handle = $titleSlug ? "{$titleSlug}-{$legacyProduct->id}" : "product-{$legacyProduct->id}";

            // Use DB::table to preserve timestamps and original ID from legacy data
            DB::table('products')->insert([
                'id' => $legacyProduct->id,
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
                'status' => $legacyProduct->status ?? 'draft',
                'seo_description' => $legacyProduct->seo_description,
                'seo_page_title' => $legacyProduct->seo_page_title,
                'track_quantity' => (bool) $legacyProduct->track_quantity,
                'sell_out_of_stock' => (bool) $legacyProduct->sell_out_of_stock,
                'charge_taxes' => (bool) $legacyProduct->charge_taxes,
                'price_code' => $legacyProduct->price_code,
                'quantity' => $legacyProduct->quantity ?? $legacyProduct->total_quantity ?? 0,
                'created_at' => $legacyProduct->created_at,
                'updated_at' => $legacyProduct->updated_at,
                'deleted_at' => ($legacyProduct->deleted_at && ! $skipDeleted) ? $legacyProduct->deleted_at : null,
            ]);

            $newProduct = Product::withTrashed()->find($legacyProduct->id);

            $this->productMap[$legacyProduct->id] = $legacyProduct->id;
            $productCount++;

            // Migrate variants for this product
            $legacyVariants = DB::connection('legacy')
                ->table('product_variants')
                ->where('product_id', $legacyProduct->id)

                ->get();

            if ($legacyVariants->isEmpty()) {
                // Create default variant from product data - use DB::table to preserve timestamps
                $quantity = $legacyProduct->quantity ?? $legacyProduct->total_quantity ?? 0;
                $cost = $legacyProduct->cost_per_item;

                $newVariantId = DB::table('product_variants')->insertGetId([
                    'product_id' => $newProduct->id,
                    'sku' => $legacyProduct->sku ?? "SKU-{$newProduct->id}",
                    'price' => $legacyProduct->price ?? 0,
                    'wholesale_price' => $legacyProduct->wholesale_price ?? 0,
                    'cost' => $cost,
                    'quantity' => $quantity,
                    'barcode' => $legacyProduct->upc,
                    'status' => $legacyProduct->status ?? 'active',
                    'is_active' => true,
                    'created_at' => $legacyProduct->created_at,
                    'updated_at' => $legacyProduct->updated_at,
                ]);
                $variantCount++;

                // Create inventory record if quantity > 0
                if ($quantity > 0 && $this->defaultWarehouse) {
                    $this->createInventoryRecord($newStore->id, $newVariantId, $quantity, $cost, $legacyProduct->created_at);
                }
            } else {
                foreach ($legacyVariants as $legacyVariant) {
                    // Get quantity from location quantities table, not from variant directly
                    $quantity = $this->getLegacyVariantQuantity($legacyVariant->id);
                    // Use variant cost, fall back to product cost
                    $cost = $legacyVariant->cost_per_item ?? $legacyProduct->cost_per_item;

                    // Use DB::table to preserve timestamps and original ID
                    // wholesale_price is on the product level in legacy, copy to variant
                    DB::table('product_variants')->insert([
                        'id' => $legacyVariant->id,
                        'product_id' => $newProduct->id,
                        'sku' => $legacyVariant->sku ?? "SKU-{$newProduct->id}-{$legacyVariant->id}",
                        'price' => $legacyVariant->price ?? 0,
                        'wholesale_price' => $legacyProduct->wholesale_price,
                        'cost' => $cost,
                        'quantity' => $quantity,
                        'barcode' => $legacyVariant->barcode,
                        'status' => $legacyVariant->status ?? 'active',
                        'sort_order' => $legacyVariant->sort_order ?? 0,
                        'is_active' => (bool) ($legacyVariant->is_active ?? true),
                        'created_at' => $legacyVariant->created_at,
                        'updated_at' => $legacyVariant->updated_at,
                    ]);

                    $this->variantMap[$legacyVariant->id] = $legacyVariant->id;
                    $variantCount++;

                    // Create inventory record if quantity > 0
                    if ($quantity > 0 && $this->defaultWarehouse) {
                        $this->createInventoryRecord($newStore->id, $legacyVariant->id, $quantity, $cost, $legacyVariant->created_at);
                    }
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
        $this->line("  Created {$this->inventoryCount} inventory records");
    }

    /**
     * Create an inventory record for a variant.
     */
    protected function createInventoryRecord(int $storeId, int $variantId, int $quantity, ?float $cost, ?string $createdAt): void
    {
        DB::table('inventory')->insert([
            'store_id' => $storeId,
            'product_variant_id' => $variantId,
            'warehouse_id' => $this->defaultWarehouse->id,
            'quantity' => $quantity,
            'reserved_quantity' => 0,
            'incoming_quantity' => 0,
            'safety_stock' => 0,
            'unit_cost' => $cost ?? 0,
            'created_at' => $createdAt ?? now(),
            'updated_at' => $createdAt ?? now(),
        ]);
        $this->inventoryCount++;
    }

    /**
     * Get the legacy store location ID for a given store.
     */
    protected function getLegacyStoreLocationId(int $legacyStoreId): ?int
    {
        if ($this->legacyStoreLocationId !== null) {
            return $this->legacyStoreLocationId;
        }

        $location = DB::connection('legacy')
            ->table('store_locations')
            ->where('store_id', $legacyStoreId)
            ->first();

        $this->legacyStoreLocationId = $location?->id;

        return $this->legacyStoreLocationId;
    }

    /**
     * Get quantity for a variant from the legacy product_variant_location_quantities table.
     */
    protected function getLegacyVariantQuantity(int $legacyVariantId): int
    {
        if (! $this->legacyStoreLocationId) {
            return 0;
        }

        $locationQty = DB::connection('legacy')
            ->table('product_variant_location_quantities')
            ->where('product_variant_id', $legacyVariantId)
            ->where('store_location_id', $this->legacyStoreLocationId)
            ->whereNull('deleted_at')
            ->first();

        return $locationQty?->quantity ?? 0;
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
        $fieldTypeMap = $this->templateFieldTypeMap[$templateId] ?? [];
        $fieldOptionsMap = $this->templateFieldOptionsMap[$templateId] ?? [];

        if (empty($fieldMap)) {
            return;
        }

        foreach ($legacyMetas as $meta) {
            // Skip empty values
            if ($meta->value === null || $meta->value === '') {
                continue;
            }

            $fieldName = strtolower($meta->field);
            $matchedFieldName = null;
            $newFieldId = null;

            // Try different name formats
            if (isset($fieldMap[$fieldName])) {
                $matchedFieldName = $fieldName;
                $newFieldId = $fieldMap[$fieldName];
            } elseif (isset($fieldMap[str_replace('-', '_', $fieldName)])) {
                $matchedFieldName = str_replace('-', '_', $fieldName);
                $newFieldId = $fieldMap[$matchedFieldName];
            } elseif (isset($fieldMap[Str::snake($fieldName)])) {
                $matchedFieldName = Str::snake($fieldName);
                $newFieldId = $fieldMap[$matchedFieldName];
            }

            if (! $newFieldId || ! $matchedFieldName) {
                continue;
            }

            // Get field type and options for value transformation
            $fieldType = $fieldTypeMap[$matchedFieldName] ?? 'text';
            $fieldOptions = $fieldOptionsMap[$matchedFieldName] ?? [];

            // Transform value for select fields
            $value = $this->transformMetaValue($meta->value, $fieldType, $fieldOptions, $matchedFieldName);

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
                'value' => $value,
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

    /**
     * Transform a legacy meta value to match the new field format.
     * Handles select fields by matching against available options.
     */
    protected function transformMetaValue(string $value, string $fieldType, array $fieldOptions, string $fieldName): string
    {
        // Only transform select field values
        if ($fieldType !== 'select' || empty($fieldOptions)) {
            return $value;
        }

        // Build a map of option values (existing values in the select)
        $optionValues = collect($fieldOptions)->pluck('value')->filter()->toArray();

        // If value already matches an option exactly, use it
        if (in_array($value, $optionValues, true)) {
            return $value;
        }

        // Try lowercase match
        $lowerValue = strtolower($value);
        foreach ($optionValues as $optionValue) {
            if (strtolower($optionValue) === $lowerValue) {
                return $optionValue;
            }
        }

        // Try slugified match (handles "Natural Diamond" -> "natural-diamond")
        $slugValue = Str::slug($value);
        foreach ($optionValues as $optionValue) {
            if ($optionValue === $slugValue) {
                return $optionValue;
            }
        }

        // Try matching by label
        foreach ($fieldOptions as $option) {
            $label = $option['label'] ?? '';
            if (strtolower($label) === $lowerValue || Str::slug($label) === $slugValue) {
                return $option['value'] ?? $value;
            }
        }

        // Special handling for known field patterns
        $transformed = $this->applyKnownTransformations($value, $fieldName, $optionValues);
        if ($transformed !== null) {
            return $transformed;
        }

        // Return original value if no match found
        return $value;
    }

    /**
     * Apply known transformations for specific field types.
     */
    protected function applyKnownTransformations(string $value, string $fieldName, array $optionValues): ?string
    {
        // Cert type fields (GIA, IGI, etc.)
        if (Str::contains($fieldName, 'cert_type')) {
            $lowerValue = strtolower($value);
            if (in_array($lowerValue, $optionValues, true)) {
                return $lowerValue;
            }
        }

        // Stone type fields (Natural Diamond, Lab Grown, etc.)
        if (Str::contains($fieldName, 'stone_type')) {
            $slugValue = Str::slug($value);
            if (in_array($slugValue, $optionValues, true)) {
                return $slugValue;
            }
        }

        // Diamond color fields (D, E, F, etc.)
        if (Str::contains($fieldName, ['diamond_color', 'color']) && ! Str::contains($fieldName, 'range')) {
            $lowerValue = strtolower($value);
            if (in_array($lowerValue, $optionValues, true)) {
                return $lowerValue;
            }
        }

        // Diamond clarity fields (VVS1, VS2, SI1, etc.)
        if (Str::contains($fieldName, ['diamond_clarity', 'clarity']) && ! Str::contains($fieldName, 'range')) {
            $lowerValue = strtolower($value);
            if (in_array($lowerValue, $optionValues, true)) {
                return $lowerValue;
            }
        }

        // Color range fields (D-E-F, G-H-I-J, etc.)
        if (Str::contains($fieldName, 'color_range')) {
            $transformed = $this->transformColorRange($value, $optionValues);
            if ($transformed !== null) {
                return $transformed;
            }
        }

        // Clarity range fields (FL-IF, VVS1-VVS2, etc.)
        if (Str::contains($fieldName, 'clarity_range')) {
            $transformed = $this->transformClarityRange($value, $optionValues);
            if ($transformed !== null) {
                return $transformed;
            }
        }

        // Weight range fields (51-75, 76-99, etc.)
        if (Str::contains($fieldName, ['weight', 'stone_weight']) && Str::contains($fieldName, 'range') || $fieldName === 'main_stone_weight') {
            $transformed = $this->transformWeightRange($value, $optionValues);
            if ($transformed !== null) {
                return $transformed;
            }
        }

        // Cut, polish, symmetry fields (Excellent, Very Good, etc.)
        if (Str::contains($fieldName, ['cut', 'polish', 'symmetry'])) {
            $slugValue = Str::slug($value);
            if (in_array($slugValue, $optionValues, true)) {
                return $slugValue;
            }
        }

        // Includes field (Certificate, Box, etc.)
        if ($fieldName === 'includes') {
            $slugValue = Str::slug($value);
            if (in_array($slugValue, $optionValues, true)) {
                return $slugValue;
            }
            $lowerValue = strtolower($value);
            if (in_array($lowerValue, $optionValues, true)) {
                return $lowerValue;
            }
        }

        return null;
    }

    /**
     * Transform color value to color range option.
     */
    protected function transformColorRange(string $value, array $optionValues): ?string
    {
        // Map individual colors to their range
        $colorRanges = [
            'd-e-f' => ['D', 'E', 'F', 'd', 'e', 'f', 'D-E', 'E-F', 'D-E-F'],
            'g-h-i-j' => ['G', 'H', 'I', 'J', 'g', 'h', 'i', 'j', 'G-H', 'H-I', 'I-J', 'G-H-I-J'],
            'k-l-m' => ['K', 'L', 'M', 'k', 'l', 'm', 'K-L', 'L-M', 'K-L-M'],
            'n-to-z' => ['N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'ST',
                'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z', 'N-Z', 'N to Z'],
            'fancy' => ['Fancy', 'fancy', 'FANCY'],
        ];

        foreach ($colorRanges as $range => $colors) {
            if (in_array($value, $colors, true) && in_array($range, $optionValues, true)) {
                return $range;
            }
        }

        // Try lowercase/slug matching
        $slugValue = Str::slug($value);
        if (in_array($slugValue, $optionValues, true)) {
            return $slugValue;
        }

        return null;
    }

    /**
     * Transform clarity value to clarity range option.
     */
    protected function transformClarityRange(string $value, array $optionValues): ?string
    {
        // Map individual clarities to their range
        $clarityRanges = [
            'fl-if' => ['FL', 'IF', 'fl', 'if', 'FL-IF', 'Flawless', 'Internally Flawless'],
            'vvs1-vvs2' => ['VVS1', 'VVS2', 'vvs1', 'vvs2', 'VVS1-VVS2', 'VVS'],
            'vs1-vs2' => ['VS1', 'VS2', 'vs1', 'vs2', 'VS1-VS2', 'VS'],
            'si1-si2' => ['SI1', 'SI2', 'si1', 'si2', 'SI1-SI2', 'SI'],
            'i1-i3' => ['I1', 'I2', 'I3', 'i1', 'i2', 'i3', 'I1-I3', 'I'],
        ];

        foreach ($clarityRanges as $range => $clarities) {
            if (in_array($value, $clarities, true) && in_array($range, $optionValues, true)) {
                return $range;
            }
        }

        // Try lowercase/slug matching
        $slugValue = Str::slug($value);
        if (in_array($slugValue, $optionValues, true)) {
            return $slugValue;
        }

        return null;
    }

    /**
     * Transform weight value to weight range option.
     */
    protected function transformWeightRange(string $value, array $optionValues): ?string
    {
        // Extract numeric weight from value like "0.63 carat" or "0.5 - 0.69"
        if (preg_match('/^([\d.]+)/', $value, $matches)) {
            $weight = (float) $matches[1];

            // Weight range mappings matching the select options
            $weightRanges = [
                '01-17' => [0.01, 0.17],
                '18-22' => [0.18, 0.22],
                '23-29' => [0.23, 0.29],
                '30-39' => [0.30, 0.39],
                '40-49' => [0.40, 0.49],
                '50-69' => [0.50, 0.69],
                '51-75' => [0.51, 0.75],
                '70-89' => [0.70, 0.89],
                '76-99' => [0.76, 0.99],
                '90-99' => [0.90, 0.99],
                '100-149' => [1.00, 1.49],
                '150-199' => [1.50, 1.99],
                '200-299' => [2.00, 2.99],
                '300-399' => [3.00, 3.99],
                '400-499' => [4.00, 4.99],
                '500-599' => [5.00, 5.99],
                '600-999' => [6.00, 9.99],
                '1000+' => [10.00, 999.99],
            ];

            foreach ($weightRanges as $range => [$min, $max]) {
                if ($weight >= $min && $weight <= $max && in_array($range, $optionValues, true)) {
                    return $range;
                }
            }
        }

        // Direct match check
        if (in_array($value, $optionValues, true)) {
            return $value;
        }

        // Try slug matching for values like "0.5 - 0.69" -> "05-069"
        $slugValue = Str::slug($value);
        if (in_array($slugValue, $optionValues, true)) {
            return $slugValue;
        }

        return null;
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

        $inventoryCount = Inventory::where('store_id', $newStore->id)->count();
        $totalQuantity = Inventory::where('store_id', $newStore->id)->sum('quantity');
        $this->line("Total inventory records: {$inventoryCount} ({$totalQuantity} items)");

        // Report unmapped vendors
        if (! empty($this->unmappedVendors)) {
            $this->newLine();
            $this->warn('=== Unmapped Vendors ===');
            $this->warn('The following legacy vendor IDs were not mapped (products will have no vendor):');
            $totalUnmappedProducts = 0;
            foreach ($this->unmappedVendors as $vendorId => $count) {
                $this->line("  Legacy vendor ID {$vendorId}: {$count} products");
                $totalUnmappedProducts += $count;
            }
            $this->warn("Total products without vendor: {$totalUnmappedProducts}");
            $this->line('To fix: Create vendors in the new system and update the vendor_map JSON file');
        }
    }
}
