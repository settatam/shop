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
                            {--skip-listings : Skip migrating platform listings}
                            {--sync-deletes : Soft-delete new records if legacy record is soft-deleted}';

    protected $description = 'Migrate products and variants from the legacy database';

    protected array $productMap = [];

    protected array $variantMap = [];

    protected array $categoryMap = [];

    protected array $templateMap = [];

    protected array $vendorMap = [];

    protected array $unmappedVendors = [];

    /**
     * Cache of legacy vendor ID => legacy vendor data
     */
    protected array $legacyVendorCache = [];

    /**
     * Cache of legacy category ID => legacy category data
     */
    protected array $legacyCategoryCache = [];

    /**
     * Cache of legacy template ID => legacy template data
     */
    protected array $legacyTemplateCache = [];

    /**
     * Cache of legacy template ID => legacy template fields
     */
    protected array $legacyTemplateFieldsCache = [];

    /**
     * Counter for created categories
     */
    protected int $createdCategoryCount = 0;

    /**
     * Counter for created templates
     */
    protected int $createdTemplateCount = 0;

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

    protected int $listingCount = 0;

    /**
     * Maps legacy store_market_place_id => new store_marketplace_id
     */
    protected array $marketplaceMap = [];

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

            // Categories and templates are now created on-demand using findOrCreate
            // No pre-mapping needed - they will be created as products are migrated

            // Build marketplace mapping for platform listings
            if (! $this->option('skip-listings')) {
                $this->buildMarketplaceMapping($legacyStoreId, $newStore);
            } else {
                $this->info('Skipping platform listings (--skip-listings)');
            }

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

    /**
     * Find or create a vendor from legacy vendor ID.
     * Returns the new vendor ID, or null if legacy vendor not found.
     */
    protected function findOrCreateVendor(int $legacyVendorId, Store $newStore): ?int
    {
        // Check cache first
        if (isset($this->vendorMap[$legacyVendorId])) {
            return $this->vendorMap[$legacyVendorId];
        }

        // Fetch legacy vendor if not cached
        if (! isset($this->legacyVendorCache[$legacyVendorId])) {
            $legacyVendor = DB::connection('legacy')
                ->table('customers')
                ->where('id', $legacyVendorId)
                ->first();

            $this->legacyVendorCache[$legacyVendorId] = $legacyVendor;
        }

        $legacyVendor = $this->legacyVendorCache[$legacyVendorId];

        if (! $legacyVendor) {
            return null;
        }

        // Build name from legacy vendor
        $firstName = trim($legacyVendor->first_name ?? '');
        $lastName = trim($legacyVendor->last_name ?? '');
        $name = trim("{$firstName} {$lastName}");
        if (empty($name)) {
            $name = $legacyVendor->company_name ?? "Vendor {$legacyVendorId}";
        }

        $email = $legacyVendor->email ?? null;

        // Try to find existing vendor by email first (more reliable)
        $existingVendor = null;
        if ($email) {
            $existingVendor = Vendor::where('store_id', $newStore->id)
                ->where('email', $email)
                ->first();
        }

        // Fall back to name match
        if (! $existingVendor) {
            $existingVendor = Vendor::where('store_id', $newStore->id)
                ->where('name', $name)
                ->first();
        }

        if ($existingVendor) {
            $this->vendorMap[$legacyVendorId] = $existingVendor->id;

            return $existingVendor->id;
        }

        // Fetch legacy addresses from addresses table (polymorphic)
        $legacyAddresses = DB::connection('legacy')
            ->table('addresses')
            ->where('addressable_type', 'App\\Models\\Customer')
            ->where('addressable_id', $legacyVendorId)
            ->whereNull('deleted_at')
            ->get();

        // Create new vendor with basic data
        $newVendor = Vendor::create([
            'store_id' => $newStore->id,
            'name' => $name,
            'email' => $email,
            'phone' => $legacyVendor->phone ?? null,
            'company_name' => $legacyVendor->company_name ?? null,
            'contact_name' => trim("{$firstName} {$lastName}") ?: null,
            'contact_email' => $email,
            'contact_phone' => $legacyVendor->phone ?? null,
            'notes' => $legacyVendor->notes ?? null,
            'is_active' => true,
        ]);

        // Create addresses in the addresses table (polymorphic)
        foreach ($legacyAddresses as $index => $legacyAddress) {
            // Find state_id if state name/abbreviation provided
            $stateId = null;
            if ($legacyAddress->state ?? null) {
                $state = \App\Models\State::where('abbreviation', $legacyAddress->state)
                    ->orWhere('name', $legacyAddress->state)
                    ->first();
                $stateId = $state?->id;
            }

            DB::table('addresses')->insert([
                'store_id' => $newStore->id,
                'addressable_type' => 'App\\Models\\Vendor',
                'addressable_id' => $newVendor->id,
                'first_name' => $legacyAddress->first_name ?? $firstName,
                'last_name' => $legacyAddress->last_name ?? $lastName,
                'company' => $legacyAddress->company ?? $legacyVendor->company_name ?? null,
                'address' => $legacyAddress->address ?? $legacyAddress->address1 ?? null,
                'address2' => $legacyAddress->address2 ?? null,
                'city' => $legacyAddress->city ?? null,
                'state_id' => $stateId,
                'zip' => $legacyAddress->zip ?? $legacyAddress->postal_code ?? null,
                'phone' => $legacyAddress->phone ?? $legacyVendor->phone ?? null,
                'is_default' => $index === 0 || ($legacyAddress->is_default ?? false),
                'is_shipping' => $legacyAddress->is_shipping ?? true,
                'is_billing' => $legacyAddress->is_billing ?? true,
                'type' => $legacyAddress->type ?? 'other',
                'created_at' => $legacyAddress->created_at ?? now(),
                'updated_at' => $legacyAddress->updated_at ?? now(),
            ]);
        }

        $this->vendorMap[$legacyVendorId] = $newVendor->id;
        $addressCount = $legacyAddresses->count();
        $this->line("  Created vendor: {$name} (legacy ID {$legacyVendorId} => new ID {$newVendor->id}) with {$addressCount} address(es)");

        return $newVendor->id;
    }

    /**
     * Find or create a category and its template from legacy category ID.
     * Returns array with 'category_id' and 'template_id'.
     *
     * Important: Categories are matched by name AND parent to handle cases like
     * "Rings > Antique" vs "Bracelets > Antique" - both have "Antique" as name
     * but are different categories.
     */
    protected function findOrCreateCategory(int $legacyCategoryId, Store $newStore): array
    {
        // Check cache first
        if (isset($this->categoryMap[$legacyCategoryId])) {
            $categoryId = $this->categoryMap[$legacyCategoryId];
            $category = Category::find($categoryId);

            return [
                'category_id' => $categoryId,
                'template_id' => $category?->template_id,
            ];
        }

        // Fetch legacy category if not cached
        if (! isset($this->legacyCategoryCache[$legacyCategoryId])) {
            $legacyCategory = DB::connection('legacy')
                ->table('store_categories')
                ->where('id', $legacyCategoryId)
                ->first();

            $this->legacyCategoryCache[$legacyCategoryId] = $legacyCategory;
        }

        $legacyCategory = $this->legacyCategoryCache[$legacyCategoryId];

        if (! $legacyCategory) {
            return ['category_id' => null, 'template_id' => null];
        }

        $categoryName = $legacyCategory->name ?? "Category {$legacyCategoryId}";

        // FIRST: Handle parent category if exists (we need parent_id for matching)
        $parentId = null;
        if ($legacyCategory->parent_id) {
            $parentResult = $this->findOrCreateCategory($legacyCategory->parent_id, $newStore);
            $parentId = $parentResult['category_id'];
        }

        // Try to find existing category by name AND parent_id
        // This ensures "Rings > Antique" and "Bracelets > Antique" are treated as different categories
        $existingCategoryQuery = Category::where('store_id', $newStore->id)
            ->where('name', $categoryName);

        if ($parentId) {
            $existingCategoryQuery->where('parent_id', $parentId);
        } else {
            $existingCategoryQuery->whereNull('parent_id');
        }

        $existingCategory = $existingCategoryQuery->first();

        if ($existingCategory) {
            $this->categoryMap[$legacyCategoryId] = $existingCategory->id;

            // Validate that the category's template actually exists
            // It might have a stale template_id pointing to a non-existent template
            $existingTemplate = null;
            if ($existingCategory->template_id) {
                $existingTemplate = ProductTemplate::find($existingCategory->template_id);
            }

            // If template doesn't exist or category has no template, create from legacy
            if (! $existingTemplate && $legacyCategory->html_form_id) {
                $templateId = $this->findOrCreateTemplate($legacyCategory->html_form_id, $newStore);
                if ($templateId) {
                    $existingCategory->update(['template_id' => $templateId]);
                    $existingTemplate = ProductTemplate::find($templateId);
                }
            }

            // Ensure field mappings are populated for this template
            if ($existingTemplate && $legacyCategory->html_form_id) {
                $this->ensureTemplateFieldsMapped($legacyCategory->html_form_id, $existingTemplate);
            }

            return [
                'category_id' => $existingCategory->id,
                'template_id' => $existingCategory->template_id,
            ];
        }

        // Create the template if it exists on the legacy category
        $templateId = null;
        if ($legacyCategory->html_form_id) {
            $templateId = $this->findOrCreateTemplate($legacyCategory->html_form_id, $newStore);
        }

        // Create new category with the correct parent
        $newCategory = Category::create([
            'store_id' => $newStore->id,
            'name' => $categoryName,
            'slug' => Str::slug($categoryName).'-'.$legacyCategoryId,
            'description' => $legacyCategory->description ?? null,
            'parent_id' => $parentId,
            'template_id' => $templateId,
            'sort_order' => $legacyCategory->sort_order ?? 0,
            'ebay_category_id' => $legacyCategory->ebay_category_id ?? null,
        ]);

        $this->categoryMap[$legacyCategoryId] = $newCategory->id;
        $this->createdCategoryCount++;

        // Build full path for logging
        $fullPath = $this->buildCategoryPath($newCategory);
        $this->line("  Created category: {$fullPath} (legacy ID {$legacyCategoryId} => new ID {$newCategory->id})");

        return [
            'category_id' => $newCategory->id,
            'template_id' => $templateId,
        ];
    }

    /**
     * Build the full path string for a category (e.g., "Jewelry > Rings > Antique").
     */
    protected function buildCategoryPath(Category $category): string
    {
        $path = [$category->name];
        $parent = $category->parent;

        while ($parent) {
            array_unshift($path, $parent->name);
            $parent = $parent->parent;
        }

        return implode(' > ', $path);
    }

    /**
     * Find or create a template and its fields from legacy template ID.
     * Returns the new template ID.
     */
    protected function findOrCreateTemplate(int $legacyTemplateId, Store $newStore): ?int
    {
        // Check cache first
        if (isset($this->templateMap[$legacyTemplateId])) {
            return $this->templateMap[$legacyTemplateId];
        }

        // Fetch legacy template if not cached
        if (! isset($this->legacyTemplateCache[$legacyTemplateId])) {
            $legacyTemplate = DB::connection('legacy')
                ->table('html_forms')
                ->where('id', $legacyTemplateId)
                ->first();

            $this->legacyTemplateCache[$legacyTemplateId] = $legacyTemplate;
        }

        $legacyTemplate = $this->legacyTemplateCache[$legacyTemplateId];

        if (! $legacyTemplate) {
            return null;
        }

        $templateName = $legacyTemplate->title ?? "Template {$legacyTemplateId}";

        // Try to find existing template by name
        $existingTemplate = ProductTemplate::where('store_id', $newStore->id)
            ->where('name', $templateName)
            ->first();

        if ($existingTemplate) {
            $this->templateMap[$legacyTemplateId] = $existingTemplate->id;

            // Ensure fields are mapped
            $this->ensureTemplateFieldsMapped($legacyTemplateId, $existingTemplate);

            return $existingTemplate->id;
        }

        // Create new template
        $newTemplate = ProductTemplate::create([
            'store_id' => $newStore->id,
            'name' => $templateName,
            'description' => $legacyTemplate->description ?? null,
            'is_active' => true,
        ]);

        $this->templateMap[$legacyTemplateId] = $newTemplate->id;
        $this->createdTemplateCount++;

        // Create template fields
        $this->createTemplateFields($legacyTemplateId, $newTemplate);

        $this->line("  Created template: {$templateName} (legacy ID {$legacyTemplateId} => new ID {$newTemplate->id})");

        return $newTemplate->id;
    }

    /**
     * Create template fields from legacy html_form_fields.
     */
    protected function createTemplateFields(int $legacyTemplateId, ProductTemplate $newTemplate): void
    {
        // Fetch legacy fields if not cached
        if (! isset($this->legacyTemplateFieldsCache[$legacyTemplateId])) {
            $legacyFields = DB::connection('legacy')
                ->table('html_form_fields')
                ->where('html_form_id', $legacyTemplateId)
                ->orderBy('sort_order')
                ->get();

            $this->legacyTemplateFieldsCache[$legacyTemplateId] = $legacyFields;
        }

        $legacyFields = $this->legacyTemplateFieldsCache[$legacyTemplateId];

        $this->templateFieldNameMap[$newTemplate->id] = [];
        $this->templateFieldTypeMap[$newTemplate->id] = [];
        $this->templateFieldOptionsMap[$newTemplate->id] = [];

        foreach ($legacyFields as $legacyField) {
            $fieldName = Str::snake($legacyField->name);
            $fieldType = $this->mapFieldType($legacyField->type ?? 'text');

            // Create the field
            $newField = ProductTemplateField::create([
                'product_template_id' => $newTemplate->id,
                'name' => $fieldName,
                'canonical_name' => $legacyField->name, // Store original name for matching
                'label' => $legacyField->label ?? Str::title(str_replace('_', ' ', $fieldName)),
                'type' => $fieldType,
                'placeholder' => $legacyField->placeholder ?? null,
                'help_text' => $legacyField->help_text ?? null,
                'default_value' => $legacyField->default_value ?? null,
                'is_required' => (bool) ($legacyField->is_required ?? false),
                'is_searchable' => (bool) ($legacyField->is_searchable ?? true),
                'is_filterable' => (bool) ($legacyField->is_filterable ?? false),
                'show_in_listing' => (bool) ($legacyField->show_in_listing ?? true),
                'sort_order' => $legacyField->sort_order ?? 0,
                'group_name' => $legacyField->group_name ?? null,
            ]);

            // Store mappings
            $this->templateFieldMap[$legacyField->id] = $newField->id;
            $canonicalName = strtolower($legacyField->name);
            $this->templateFieldNameMap[$newTemplate->id][$canonicalName] = $newField->id;
            $this->templateFieldTypeMap[$newTemplate->id][$canonicalName] = $fieldType;

            // Create field options for select/radio/checkbox fields
            if (in_array($fieldType, ['select', 'radio', 'checkbox'])) {
                $this->createFieldOptions($legacyField, $newField);
            }
        }
    }

    /**
     * Ensure template fields are mapped for an existing template.
     */
    protected function ensureTemplateFieldsMapped(int $legacyTemplateId, ProductTemplate $existingTemplate): void
    {
        // If already mapped, skip
        if (isset($this->templateFieldNameMap[$existingTemplate->id]) && ! empty($this->templateFieldNameMap[$existingTemplate->id])) {
            return;
        }

        // Fetch legacy fields
        if (! isset($this->legacyTemplateFieldsCache[$legacyTemplateId])) {
            $legacyFields = DB::connection('legacy')
                ->table('html_form_fields')
                ->where('html_form_id', $legacyTemplateId)
                ->orderBy('sort_order')
                ->get();

            $this->legacyTemplateFieldsCache[$legacyTemplateId] = $legacyFields;
        }

        $legacyFields = $this->legacyTemplateFieldsCache[$legacyTemplateId];

        // Get existing new fields
        $existingFields = ProductTemplateField::where('product_template_id', $existingTemplate->id)->get();
        $existingFieldsByName = $existingFields->keyBy(fn ($f) => strtolower($f->canonical_name ?? $f->name));
        $existingFieldsBySnakeName = $existingFields->keyBy(fn ($f) => strtolower($f->name));

        $this->templateFieldNameMap[$existingTemplate->id] = [];
        $this->templateFieldTypeMap[$existingTemplate->id] = [];
        $this->templateFieldOptionsMap[$existingTemplate->id] = [];

        foreach ($legacyFields as $legacyField) {
            $canonicalName = strtolower($legacyField->name);
            $snakeName = strtolower(Str::snake($legacyField->name));

            // Try to find matching field
            $matchedField = $existingFieldsByName->get($canonicalName)
                ?? $existingFieldsBySnakeName->get($snakeName);

            if ($matchedField) {
                $this->templateFieldMap[$legacyField->id] = $matchedField->id;
                $this->templateFieldNameMap[$existingTemplate->id][$canonicalName] = $matchedField->id;
                $this->templateFieldTypeMap[$existingTemplate->id][$canonicalName] = $matchedField->type;

                // Store select options
                if ($matchedField->type === 'select') {
                    $options = $matchedField->options()->get()->map(fn ($o) => [
                        'value' => $o->value,
                        'label' => $o->label,
                    ])->toArray();
                    $this->templateFieldOptionsMap[$existingTemplate->id][$canonicalName] = $options;
                }
            } else {
                // Create missing field
                $fieldName = Str::snake($legacyField->name);
                $fieldType = $this->mapFieldType($legacyField->type ?? 'text');

                $newField = ProductTemplateField::create([
                    'product_template_id' => $existingTemplate->id,
                    'name' => $fieldName,
                    'canonical_name' => $legacyField->name,
                    'label' => $legacyField->label ?? Str::title(str_replace('_', ' ', $fieldName)),
                    'type' => $fieldType,
                    'placeholder' => $legacyField->placeholder ?? null,
                    'is_required' => (bool) ($legacyField->is_required ?? false),
                    'is_searchable' => (bool) ($legacyField->is_searchable ?? true),
                    'show_in_listing' => (bool) ($legacyField->show_in_listing ?? true),
                    'sort_order' => $legacyField->sort_order ?? 0,
                ]);

                $this->templateFieldMap[$legacyField->id] = $newField->id;
                $this->templateFieldNameMap[$existingTemplate->id][$canonicalName] = $newField->id;
                $this->templateFieldTypeMap[$existingTemplate->id][$canonicalName] = $fieldType;

                // Create field options
                if (in_array($fieldType, ['select', 'radio', 'checkbox'])) {
                    $this->createFieldOptions($legacyField, $newField);
                }
            }
        }
    }

    /**
     * Create field options for select/radio/checkbox fields.
     */
    protected function createFieldOptions(object $legacyField, ProductTemplateField $newField): void
    {
        // Fetch legacy options
        $legacyOptions = DB::connection('legacy')
            ->table('html_form_field_options')
            ->where('html_form_field_id', $legacyField->id)
            ->orderBy('sort_order')
            ->get();

        $options = [];

        foreach ($legacyOptions as $index => $legacyOption) {
            $value = $legacyOption->value ?? Str::slug($legacyOption->label ?? '');
            $label = $legacyOption->label ?? $legacyOption->value ?? '';

            DB::table('product_template_field_options')->insert([
                'product_template_field_id' => $newField->id,
                'value' => $value,
                'label' => $label,
                'sort_order' => $legacyOption->sort_order ?? $index,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $options[] = ['value' => $value, 'label' => $label];
        }

        // Store in options map
        $canonicalName = strtolower($legacyField->name);
        $this->templateFieldOptionsMap[$newField->product_template_id][$canonicalName] = $options;
    }

    /**
     * Map legacy field type to new field type.
     */
    protected function mapFieldType(?string $legacyType): string
    {
        if (! $legacyType) {
            return 'text';
        }

        return match (strtolower($legacyType)) {
            'text', 'string' => 'text',
            'textarea', 'text_area' => 'textarea',
            'number', 'integer', 'float', 'decimal' => 'number',
            'select', 'dropdown' => 'select',
            'checkbox' => 'checkbox',
            'radio' => 'radio',
            'date', 'datetime' => 'date',
            default => 'text',
        };
    }

    /**
     * Build mapping from legacy store_market_places to new store_marketplaces.
     * Maps by platform name (case-insensitive).
     */
    protected function buildMarketplaceMapping(int $legacyStoreId, Store $newStore): void
    {
        $this->info('Building marketplace mapping for platform listings...');

        // Get legacy marketplaces for this store
        $legacyMarketplaces = DB::connection('legacy')
            ->table('store_market_places')
            ->where('store_id', $legacyStoreId)
            ->whereNull('deleted_at')
            ->get();

        // Get new marketplaces for the target store
        $newMarketplaces = \App\Models\StoreMarketplace::where('store_id', $newStore->id)
            ->whereNull('deleted_at')
            ->get();

        // Build a map by platform name (lowercase)
        $newMarketplacesByPlatform = $newMarketplaces->keyBy(fn ($m) => strtolower($m->platform->value ?? $m->platform));

        foreach ($legacyMarketplaces as $legacy) {
            $platform = strtolower($legacy->marketplace);

            if ($newMarketplacesByPlatform->has($platform)) {
                $this->marketplaceMap[$legacy->id] = $newMarketplacesByPlatform->get($platform)->id;
            }
        }

        $this->line('  Mapped '.count($this->marketplaceMap).' marketplaces');

        if (empty($this->marketplaceMap)) {
            $this->warn('  No marketplace mappings found. Platform listings will not be migrated.');
            $this->warn('  Ensure marketplaces are set up in the new store first.');
        }
    }

    /**
     * Migrate platform listings (store_marketplace_products) for a product.
     * These represent products published to external platforms like Shopify, eBay, etc.
     */
    protected function migrateProductListings(object $legacyProduct, Product $newProduct): void
    {
        // Get legacy store_marketplace_products for this product
        $legacyListings = DB::connection('legacy')
            ->table('store_marketplace_products')
            ->where('product_id', $legacyProduct->id)
            ->whereNull('deleted_at')
            ->get();

        if ($legacyListings->isEmpty()) {
            return;
        }

        foreach ($legacyListings as $legacyListing) {
            // Skip if no marketplace mapping
            if (! isset($this->marketplaceMap[$legacyListing->store_market_place_id])) {
                continue;
            }

            $newMarketplaceId = $this->marketplaceMap[$legacyListing->store_market_place_id];

            // Check if listing already exists
            $existingListing = DB::table('platform_listings')
                ->where('store_marketplace_id', $newMarketplaceId)
                ->where('product_id', $newProduct->id)
                ->first();

            if ($existingListing) {
                continue;
            }

            // Map listing status
            $status = $this->mapListingStatus($legacyListing->listing_status);

            // Get the default variant for this product
            $defaultVariant = \App\Models\ProductVariant::where('product_id', $newProduct->id)->first();

            // Create the platform listing
            DB::table('platform_listings')->insert([
                'store_marketplace_id' => $newMarketplaceId,
                'product_id' => $newProduct->id,
                'product_variant_id' => $defaultVariant?->id,
                'external_listing_id' => $legacyListing->external_marketplace_id,
                'external_variant_id' => null,
                'status' => $status,
                'listing_url' => $legacyListing->external_marketplace_url,
                'platform_price' => null, // Will be set from variant price
                'platform_quantity' => null,
                'platform_data' => $legacyListing->marketplace_response ? json_encode([
                    'legacy_response' => $legacyListing->marketplace_response,
                    'legacy_channelable_type' => $legacyListing->channelable_type,
                    'legacy_channelable_id' => $legacyListing->channelable_id,
                ]) : null,
                'category_mapping' => null,
                'last_error' => null,
                'last_synced_at' => $legacyListing->updated_at,
                'published_at' => $status === 'active' ? $legacyListing->listing_start_date : null,
                'created_at' => $legacyListing->created_at,
                'updated_at' => $legacyListing->updated_at,
            ]);

            $this->listingCount++;
        }
    }

    /**
     * Map legacy listing_status to new status.
     */
    protected function mapListingStatus(?string $legacyStatus): string
    {
        if (! $legacyStatus) {
            return 'draft';
        }

        return match (strtolower($legacyStatus)) {
            'listed' => 'active',
            'not_listed' => 'draft',
            'archived' => 'archived',
            'listing_expired' => 'expired',
            'listing_ended' => 'ended',
            'listing_error' => 'error',
            default => 'draft',
        };
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
            ->where('store_id', $legacyStoreId)
            ->whereNotNull('vendor_id'); // Skip products without vendors

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
            // Check if product already exists by ID first (since we preserve legacy IDs)
            $existingProduct = Product::withTrashed()->find($legacyProduct->id);

            // If not found by ID, check by SKU
            if (! $existingProduct && $legacyProduct->sku) {
                $existingProduct = Product::where('store_id', $newStore->id)
                    ->whereHas('variants', fn ($q) => $q->where('sku', $legacyProduct->sku))
                    ->first();
            }

            // If not found by SKU, check by handle
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

            // Find or create category (template is inherited from category)
            $categoryId = null;
            $templateId = null;
            if ($legacyProduct->store_category_id) {
                $categoryResult = $this->findOrCreateCategory($legacyProduct->store_category_id, $newStore);
                $categoryId = $categoryResult['category_id'];
                $templateId = $categoryResult['template_id'];
            }

            // Find or create vendor
            $vendorId = null;
            if ($legacyProduct->vendor_id) {
                $vendorId = $this->findOrCreateVendor($legacyProduct->vendor_id, $newStore);
                if (! $vendorId) {
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

            // Migrate platform listings (store_marketplace_products)
            if (! $this->option('skip-listings') && ! empty($this->marketplaceMap)) {
                $this->migrateProductListings($legacyProduct, $newProduct);
            }

            if ($productCount % 100 === 0) {
                $this->line("  Processed {$productCount} products...");
            }
        }

        $this->line("  Created {$productCount} products with {$variantCount} variants, skipped {$skipped} existing");
        $this->line("  Migrated {$this->attributeValueCount} attribute values, {$this->imageCount} images, {$this->listingCount} platform listings");
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
        $this->line('Categories created: '.$this->createdCategoryCount);
        $this->line('Templates created: '.$this->createdTemplateCount);
        $this->line('Template fields mapped: '.count($this->templateFieldMap));
        $this->line('Vendors created/mapped: '.count($this->vendorMap));

        $productIds = Product::where('store_id', $newStore->id)->pluck('id');
        $productCount = $productIds->count();
        $variantCount = ProductVariant::whereIn('product_id', $productIds)->count();
        $attributeCount = ProductAttributeValue::whereIn('product_id', $productIds)->count();
        $imageCount = DB::table('images')
            ->where('imageable_type', 'App\\Models\\Product')
            ->whereIn('imageable_id', $productIds)
            ->count();

        $categoryCount = Category::where('store_id', $newStore->id)->count();
        $templateCount = ProductTemplate::where('store_id', $newStore->id)->count();

        $this->newLine();
        $this->line("Total categories in store: {$categoryCount}");
        $this->line("Total templates in store: {$templateCount}");
        $this->line("Total products in store: {$productCount}");
        $this->line("Total variants in store: {$variantCount}");
        $this->line("Total attribute values in store: {$attributeCount}");
        $this->line("Total images in store: {$imageCount}");

        $inventoryCount = Inventory::where('store_id', $newStore->id)->count();
        $totalQuantity = Inventory::where('store_id', $newStore->id)->sum('quantity');
        $this->line("Total inventory records: {$inventoryCount} ({$totalQuantity} items)");

        // Report unmapped vendors (only if legacy vendor record doesn't exist)
        if (! empty($this->unmappedVendors)) {
            $this->newLine();
            $this->warn('=== Missing Vendor Records ===');
            $this->warn('The following legacy vendor IDs could not be found in legacy database:');
            $totalUnmappedProducts = 0;
            foreach ($this->unmappedVendors as $vendorId => $count) {
                $this->line("  Legacy vendor ID {$vendorId}: {$count} products");
                $totalUnmappedProducts += $count;
            }
            $this->warn("Total products without vendor: {$totalUnmappedProducts}");
        }
    }
}
