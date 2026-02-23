<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\ProductTemplate;
use App\Models\ProductTemplateField;
use App\Models\ProductTemplateFieldOption;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MigrateLegacyCategories extends Command
{
    protected $signature = 'migrate:legacy-categories
                            {store_id : The legacy store ID to migrate from}
                            {--new-store-id= : The new store ID to migrate to (defaults to same as legacy)}
                            {--dry-run : Run without making any changes}
                            {--skip-templates : Skip migrating templates (html_forms)}';

    protected $description = 'Migrate legacy categories and templates from shopmata-new database for a specific store';

    /**
     * Map legacy html_form_id to new template_id.
     *
     * @var array<int, int>
     */
    protected array $templateMap = [];

    /**
     * Map legacy category_id to new category_id.
     *
     * @var array<int, int>
     */
    protected array $categoryMap = [];

    protected bool $dryRun = false;

    protected int $legacyStoreId;

    protected int $newStoreId;

    public function handle(): int
    {
        // DISABLED: Category migration is disabled until comprehensive cutover plan is ready
        $this->error('Category migration is disabled - using legacy sync instead.');
        $this->info('This command will be re-enabled when the comprehensive cutover plan is ready.');

        return 1;

        // @codeCoverageIgnoreStart
        $this->legacyStoreId = (int) $this->argument('store_id');
        $this->newStoreId = (int) ($this->option('new-store-id') ?? $this->legacyStoreId);
        $this->dryRun = (bool) $this->option('dry-run');

        if ($this->dryRun) {
            $this->warn('Running in DRY RUN mode - no changes will be made');
        }

        $this->info("Starting migration for store ID: {$this->legacyStoreId} -> {$this->newStoreId}");

        // Test legacy database connection
        try {
            DB::connection('legacy')->getPdo();
            $this->info('Connected to legacy database successfully.');
        } catch (\Exception $e) {
            $this->error('Could not connect to legacy database: '.$e->getMessage());

            return self::FAILURE;
        }

        // Step 1: Migrate templates (html_forms)
        if (! $this->option('skip-templates')) {
            $this->migrateTemplates();
        }

        // Step 2: Migrate categories
        $this->migrateCategories();

        // Step 3: Save the mapping to cache/file for use by transaction migration
        $this->saveMappings();

        $this->newLine();
        $this->info('Migration complete!');
        $this->table(
            ['Type', 'Count'],
            [
                ['Templates migrated', count($this->templateMap)],
                ['Categories migrated', count($this->categoryMap)],
            ]
        );

        return self::SUCCESS;
    }

    protected function migrateTemplates(): void
    {
        $this->info('Migrating templates (html_forms)...');

        $legacyForms = DB::connection('legacy')
            ->table('html_forms')
            ->where('store_id', $this->legacyStoreId)
            ->whereNull('deleted_at')
            ->get();

        $this->info("Found {$legacyForms->count()} templates to migrate");

        foreach ($legacyForms as $legacyForm) {
            try {
                $this->migrateTemplate($legacyForm);
            } catch (\Exception $e) {
                Log::error('Failed to migrate template', [
                    'legacy_id' => $legacyForm->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("Failed to migrate template #{$legacyForm->id}: {$e->getMessage()}");
            }
        }
    }

    protected function migrateTemplate(object $legacyForm): void
    {
        // Check if already migrated
        $existing = ProductTemplate::where('store_id', $this->newStoreId)
            ->where('name', $legacyForm->title)
            ->first();

        if ($existing) {
            $this->templateMap[$legacyForm->id] = $existing->id;
            $this->line("  Template '{$legacyForm->title}' already exists, mapping to ID {$existing->id}");

            return;
        }

        if ($this->dryRun) {
            $this->line("  [DRY RUN] Would create template: {$legacyForm->title}");

            return;
        }

        // Create the template
        $template = ProductTemplate::create([
            'store_id' => $this->newStoreId,
            'name' => $legacyForm->title,
            'description' => null,
            'is_active' => true,
            'ai_generated' => false,
        ]);

        $this->templateMap[$legacyForm->id] = $template->id;
        $this->line("  Created template: {$legacyForm->title} (ID: {$template->id})");

        // Migrate fields
        $this->migrateTemplateFields($legacyForm->id, $template);
    }

    protected function migrateTemplateFields(int $legacyFormId, ProductTemplate $template): void
    {
        $legacyFields = DB::connection('legacy')
            ->table('html_form_fields')
            ->where('html_form_id', $legacyFormId)
            ->orderBy('sort_order')
            ->get();

        $usedFieldNames = []; // Track used field names within this template

        foreach ($legacyFields as $legacyField) {
            $fieldType = $this->mapFieldType($legacyField->component);

            // Make field name unique within the template
            $baseName = Str::snake($legacyField->name);
            $fieldName = $baseName;
            $counter = 1;
            while (in_array($fieldName, $usedFieldNames)) {
                $counter++;
                $fieldName = "{$baseName}_{$counter}";
            }
            $usedFieldNames[] = $fieldName;

            $field = ProductTemplateField::create([
                'product_template_id' => $template->id,
                'name' => $fieldName,
                'canonical_name' => $baseName,
                'label' => $legacyField->label.($counter > 1 ? " ({$counter})" : ''),
                'type' => $fieldType,
                'placeholder' => null,
                'help_text' => null,
                'default_value' => null,
                'is_required' => false,
                'is_searchable' => (bool) $legacyField->is_searchable,
                'is_filterable' => false,
                'show_in_listing' => true,
                'validation_rules' => null,
                'sort_order' => $legacyField->sort_order,
                'group_name' => null,
                'group_position' => 0,
                'width_class' => 'w-full',
                'ai_generated' => false,
            ]);

            // Migrate field options
            $this->migrateFieldOptions($legacyField->id, $field);
        }
    }

    protected function migrateFieldOptions(int $legacyFieldId, ProductTemplateField $field): void
    {
        $legacyOptions = DB::connection('legacy')
            ->table('html_form_field_values')
            ->where('html_form_field_id', $legacyFieldId)
            ->orderBy('sort_order')
            ->get();

        foreach ($legacyOptions as $index => $legacyOption) {
            ProductTemplateFieldOption::create([
                'product_template_field_id' => $field->id,
                'label' => $legacyOption->value,
                'value' => Str::slug($legacyOption->value, '_'),
                'sort_order' => $legacyOption->sort_order ?? $index,
            ]);
        }
    }

    protected function mapFieldType(string $component): string
    {
        return match ($component) {
            'input' => ProductTemplateField::TYPE_TEXT,
            'textarea' => ProductTemplateField::TYPE_TEXTAREA,
            'select' => ProductTemplateField::TYPE_SELECT,
            'checkbox' => ProductTemplateField::TYPE_CHECKBOX,
            'radio' => ProductTemplateField::TYPE_RADIO,
            'number' => ProductTemplateField::TYPE_NUMBER,
            'date' => ProductTemplateField::TYPE_DATE,
            default => ProductTemplateField::TYPE_TEXT,
        };
    }

    protected function migrateCategories(): void
    {
        $this->info('Migrating categories...');

        // Get legacy categories from store_categories table
        $legacyCategories = DB::connection('legacy')
            ->table('store_categories')
            ->where('store_id', $this->legacyStoreId)
            ->whereNull('deleted_at')
            ->orderBy('parent_id')
            ->orderBy('sort_order')
            ->get();

        $this->info("Found {$legacyCategories->count()} categories to migrate");

        // Build a lookup map for validation
        $categoryLookup = $legacyCategories->keyBy('id');

        // Filter out categories with invalid parent chains
        // A valid category must have a parent chain that leads to parent_id = 0 or null
        $validCategories = $legacyCategories->filter(function ($category) use ($categoryLookup) {
            return $this->hasValidParentChain($category, $categoryLookup);
        });

        $skippedCount = $legacyCategories->count() - $validCategories->count();
        if ($skippedCount > 0) {
            $this->warn("Skipping {$skippedCount} categories with invalid parent chains (orphaned categories)");
        }

        // First pass: migrate all valid categories without parent relationships
        foreach ($validCategories as $legacyCategory) {
            try {
                $this->migrateCategory($legacyCategory);
            } catch (\Exception $e) {
                Log::error('Failed to migrate category', [
                    'legacy_id' => $legacyCategory->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("Failed to migrate category #{$legacyCategory->id}: {$e->getMessage()}");
            }
        }

        // Second pass: update parent relationships
        if (! $this->dryRun) {
            $this->updateParentRelationships($validCategories);
        }
    }

    /**
     * Check if a category has a valid parent chain leading to parent_id = 0 or null.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $categoryLookup
     */
    protected function hasValidParentChain(object $category, $categoryLookup): bool
    {
        $visited = [];
        $current = $category;

        while (true) {
            // Top-level category (parent_id = 0 or null) - valid
            if (empty($current->parent_id) || $current->parent_id == 0) {
                return true;
            }

            // Detect circular references
            if (in_array($current->id, $visited)) {
                $this->warn("  Category '{$current->name}' (ID {$current->id}) has circular parent reference - skipping");

                return false;
            }
            $visited[] = $current->id;

            // Check if parent exists
            $parent = $categoryLookup->get($current->parent_id);
            if (! $parent) {
                $this->warn("  Category '{$category->name}' (ID {$category->id}) has orphaned parent_id {$current->parent_id} - skipping");

                return false;
            }

            $current = $parent;
        }
    }

    protected function migrateCategory(object $legacyCategory): void
    {
        // Check if already migrated by ID (preserving original IDs)
        $existing = Category::withoutGlobalScopes()->find($legacyCategory->id);

        if ($existing) {
            // Verify it belongs to the correct store
            if ($existing->store_id == $this->newStoreId) {
                $this->categoryMap[$legacyCategory->id] = $existing->id;
                $this->line("  Category '{$legacyCategory->name}' (ID {$legacyCategory->id}) already exists");

                return;
            }
            // ID conflict with another store - we'll create with a new ID
            $this->warn("  Category ID {$legacyCategory->id} taken by store {$existing->store_id}, will generate new ID");
        }

        if ($this->dryRun) {
            $this->line("  [DRY RUN] Would create category: {$legacyCategory->name} (ID {$legacyCategory->id}, level {$legacyCategory->level})");
            $this->categoryMap[$legacyCategory->id] = $legacyCategory->id;

            return;
        }

        // Find template for this category - store_categories uses html_form_id
        $templateId = null;
        if ($legacyCategory->html_form_id && isset($this->templateMap[$legacyCategory->html_form_id])) {
            $templateId = $this->templateMap[$legacyCategory->html_form_id];
        }

        // Try to preserve original ID, otherwise let DB generate new one
        $canPreserveId = ! $existing;

        if ($canPreserveId) {
            // Preserve original ID
            DB::table('categories')->insert([
                'id' => $legacyCategory->id,
                'store_id' => $this->newStoreId,
                'name' => $legacyCategory->name,
                'slug' => Str::slug($legacyCategory->name).'-'.Str::random(4),
                'description' => null,
                'type' => 'transaction_item_category',
                'template_id' => $templateId,
                'sort_order' => $legacyCategory->sort_order ?? 0,
                'level' => $legacyCategory->level ?? 0,
                'parent_id' => null,
                'created_at' => $legacyCategory->created_at ?? now(),
                'updated_at' => $legacyCategory->updated_at ?? now(),
            ]);

            $this->categoryMap[$legacyCategory->id] = $legacyCategory->id;
            $this->line("  Created category: {$legacyCategory->name} (ID {$legacyCategory->id}, level {$legacyCategory->level})");
        } else {
            // ID collision - generate new ID
            $newId = DB::table('categories')->insertGetId([
                'store_id' => $this->newStoreId,
                'name' => $legacyCategory->name,
                'slug' => Str::slug($legacyCategory->name).'-'.Str::random(4),
                'description' => null,
                'type' => 'transaction_item_category',
                'template_id' => $templateId,
                'sort_order' => $legacyCategory->sort_order ?? 0,
                'level' => $legacyCategory->level ?? 0,
                'parent_id' => null,
                'created_at' => $legacyCategory->created_at ?? now(),
                'updated_at' => $legacyCategory->updated_at ?? now(),
            ]);

            $this->categoryMap[$legacyCategory->id] = $newId;
            $this->line("  Created category: {$legacyCategory->name} (legacy ID {$legacyCategory->id} -> new ID {$newId}, level {$legacyCategory->level})");
        }
    }

    protected function updateParentRelationships($legacyCategories): void
    {
        foreach ($legacyCategories as $legacyCategory) {
            if (! $legacyCategory->parent_id || $legacyCategory->parent_id == 0) {
                continue;
            }

            $newCategoryId = $this->categoryMap[$legacyCategory->id] ?? null;
            $newParentId = $this->categoryMap[$legacyCategory->parent_id] ?? null;

            if ($newCategoryId && $newParentId) {
                Category::withoutGlobalScopes()
                    ->where('id', $newCategoryId)
                    ->update(['parent_id' => $newParentId]);
            }
        }
    }

    protected function saveMappings(): void
    {
        $mappings = [
            'templates' => $this->templateMap,
            'categories' => $this->categoryMap,
            'legacy_store_id' => $this->legacyStoreId,
            'new_store_id' => $this->newStoreId,
            'created_at' => now()->toIso8601String(),
        ];

        $filename = storage_path("app/legacy-mappings-{$this->legacyStoreId}-{$this->newStoreId}.json");
        file_put_contents($filename, json_encode($mappings, JSON_PRETTY_PRINT));

        $this->info("Mappings saved to: {$filename}");
    }

    /**
     * Get the category mapping for use by other commands.
     *
     * @return array<int, int>
     */
    public function getCategoryMap(): array
    {
        return $this->categoryMap;
    }

    /**
     * Get the template mapping for use by other commands.
     *
     * @return array<int, int>
     */
    public function getTemplateMap(): array
    {
        return $this->templateMap;
    }

    /**
     * Load mappings from a previously saved file.
     *
     * @return array{templates: array<int, int>, categories: array<int, int>}|null
     */
    public static function loadMappings(int $legacyStoreId, int $newStoreId): ?array
    {
        $filename = storage_path("app/legacy-mappings-{$legacyStoreId}-{$newStoreId}.json");

        if (! file_exists($filename)) {
            return null;
        }

        $data = json_decode(file_get_contents($filename), true);

        return [
            'templates' => $data['templates'] ?? [],
            'categories' => $data['categories'] ?? [],
        ];
    }
}
