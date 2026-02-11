<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductTemplate;
use App\Models\ProductTemplateField;
use App\Models\Store;
use App\Models\Tag;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncLegacyProductMetas extends Command
{
    protected $signature = 'sync:legacy-product-metas
                            {--store-id=63 : Legacy store ID}
                            {--new-store-id= : New store ID (if different from legacy)}
                            {--product-id= : Sync a specific product ID only}
                            {--limit=0 : Number of products to process (0 for all)}
                            {--dry-run : Show what would be synced without making changes}
                            {--force : Overwrite existing attribute values}
                            {--include-tags : Also sync product tags from legacy}';

    protected $description = 'Sync product attribute values and tags from legacy database';

    /**
     * Maps legacy html_forms.id => new product_templates.id
     */
    protected array $templateMap = [];

    /**
     * Maps legacy template_id => [field_name => new_field_id]
     */
    protected array $templateFieldNameMap = [];

    protected int $createdCount = 0;

    protected int $updatedCount = 0;

    protected int $skippedCount = 0;

    protected int $processedProducts = 0;

    protected int $tagsCreated = 0;

    protected int $tagsAttached = 0;

    protected int $tagsSkipped = 0;

    /**
     * Maps tag name => new tag ID
     */
    protected array $tagMap = [];

    public function handle(): int
    {
        $legacyStoreId = (int) $this->option('store-id');
        $newStoreId = $this->option('new-store-id') ? (int) $this->option('new-store-id') : null;
        $specificProductId = $this->option('product-id') ? (int) $this->option('product-id') : null;
        $limit = (int) $this->option('limit');
        $isDryRun = $this->option('dry-run');
        $force = $this->option('force');
        $includeTags = $this->option('include-tags');

        $this->info("Syncing product metas from legacy store ID: {$legacyStoreId}");

        if ($includeTags) {
            $this->info('Including product tags in sync');
        }

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        if ($force) {
            $this->warn('FORCE MODE - Existing values will be overwritten');
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
            $this->error('New store not found.');

            return 1;
        }

        $this->info("Syncing to store: {$newStore->name} (ID: {$newStore->id})");

        // Build template and field mappings
        $this->buildTemplateMapping($legacyStoreId, $newStore);

        if (empty($this->templateMap)) {
            $this->error('No template mappings found. Ensure templates are migrated first.');

            return 1;
        }

        // Load product mapping from file if it exists
        $productMap = $this->loadProductMapping($legacyStoreId);

        try {
            DB::beginTransaction();

            // Get products to sync
            $query = Product::where('store_id', $newStore->id)
                ->whereNotNull('template_id');

            if ($specificProductId) {
                $query->where('id', $specificProductId);
            }

            if ($limit > 0) {
                $query->limit($limit);
            }

            $products = $query->get();

            $this->info("Found {$products->count()} products to process");

            $bar = $this->output->createProgressBar($products->count());
            $bar->start();

            foreach ($products as $product) {
                $this->syncProductMetas($product, $productMap, $legacyStoreId, $newStore, $isDryRun, $force, $includeTags);
                $bar->advance();
            }

            $bar->finish();
            $this->newLine();

            if ($isDryRun) {
                DB::rollBack();
                $this->info('Dry run complete - no changes made');
            } else {
                DB::commit();
                $this->info('Sync complete!');
            }

            $this->displaySummary();

            return 0;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Sync failed: {$e->getMessage()}");
            $this->error($e->getTraceAsString());

            return 1;
        }
    }

    protected function buildTemplateMapping(int $legacyStoreId, Store $newStore): void
    {
        $this->info('Building template mapping...');

        // Try to load from saved mapping file first
        $mapFile = storage_path("app/migration_maps/template_map_{$legacyStoreId}.json");
        if (file_exists($mapFile)) {
            $this->templateMap = json_decode(file_get_contents($mapFile), true) ?? [];
            $this->line('  Loaded '.count($this->templateMap).' template mappings from file');
        }

        // If no saved mapping, build from name matching
        if (empty($this->templateMap)) {
            $legacyTemplates = DB::connection('legacy')
                ->table('html_forms')
                ->where('store_id', $legacyStoreId)
                ->get();

            $newTemplates = ProductTemplate::where('store_id', $newStore->id)->get();
            $newTemplatesByName = $newTemplates->keyBy(fn ($t) => strtolower($t->name));

            foreach ($legacyTemplates as $legacy) {
                $name = strtolower($legacy->title);
                if ($newTemplatesByName->has($name)) {
                    $this->templateMap[$legacy->id] = $newTemplatesByName->get($name)->id;
                }
            }

            $this->line('  Built '.count($this->templateMap).' template mappings from names');
        }

        // Build field mappings for each template
        $this->buildTemplateFieldMapping($legacyStoreId, $newStore);
    }

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

            // Build maps by canonical_name and by name
            $newFieldsByCanonicalName = $newFields->keyBy(fn ($f) => strtolower($f->canonical_name ?? $f->name));
            $newFieldsByName = $newFields->keyBy(fn ($f) => strtolower($f->name));

            $this->templateFieldNameMap[$newTemplateId] = [];

            foreach ($legacyFields as $legacyField) {
                $legacyName = strtolower($legacyField->name);

                // Try canonical name first, then snake_case name
                $newField = $newFieldsByCanonicalName->get($legacyName)
                    ?? $newFieldsByName->get(Str::snake($legacyName))
                    ?? $newFieldsByName->get(str_replace('-', '_', $legacyName));

                if ($newField) {
                    $this->templateFieldNameMap[$newTemplateId][$legacyName] = $newField->id;
                    $fieldCount++;
                }
            }
        }

        $this->line("  Mapped {$fieldCount} template fields");
    }

    protected function loadProductMapping(int $legacyStoreId): array
    {
        $mapFile = storage_path("app/migration_maps/product_map_{$legacyStoreId}.json");
        if (file_exists($mapFile)) {
            $map = json_decode(file_get_contents($mapFile), true) ?? [];
            $this->line('  Loaded '.count($map).' product mappings from file');

            // Invert the map: legacy_id => new_id becomes new_id => legacy_id
            return array_flip($map);
        }

        return [];
    }

    protected function syncProductMetas(Product $product, array $productMap, int $legacyStoreId, Store $newStore, bool $isDryRun, bool $force, bool $includeTags): void
    {
        $this->processedProducts++;

        // Find legacy product ID
        $legacyProductId = $productMap[$product->id] ?? null;

        // If no mapping, try to find by matching criteria
        if (! $legacyProductId) {
            $legacyProductId = $this->findLegacyProductId($product, $legacyStoreId);
        }

        if (! $legacyProductId) {
            return;
        }

        // Sync tags if requested
        if ($includeTags) {
            $this->syncProductTags($product, $legacyProductId, $legacyStoreId, $newStore, $isDryRun);
        }

        // Skip meta sync if no template
        if (! $product->template_id) {
            return;
        }

        // Get the field mapping for this template
        $fieldMap = $this->templateFieldNameMap[$product->template_id] ?? [];

        if (empty($fieldMap)) {
            return;
        }

        // Get metas for this product from legacy
        $legacyMetas = DB::connection('legacy')
            ->table('metas')
            ->where('metaable_type', 'App\\Models\\Product')
            ->where('metaable_id', $legacyProductId)
            ->whereNull('deleted_at')
            ->get();

        if ($legacyMetas->isEmpty()) {
            return;
        }

        foreach ($legacyMetas as $meta) {
            // Skip empty values
            if ($meta->value === null || $meta->value === '') {
                continue;
            }

            $fieldName = strtolower($meta->field);
            $newFieldId = $fieldMap[$fieldName]
                ?? $fieldMap[str_replace('-', '_', $fieldName)]
                ?? $fieldMap[Str::snake($fieldName)]
                ?? null;

            if (! $newFieldId) {
                continue;
            }

            // Check if this attribute value already exists
            $existingValue = ProductAttributeValue::where('product_id', $product->id)
                ->where('product_template_field_id', $newFieldId)
                ->first();

            if ($existingValue) {
                if ($force && $existingValue->value !== $meta->value) {
                    if (! $isDryRun) {
                        $existingValue->update(['value' => $meta->value]);
                    }
                    $this->updatedCount++;
                } else {
                    $this->skippedCount++;
                }

                continue;
            }

            if (! $isDryRun) {
                ProductAttributeValue::create([
                    'product_id' => $product->id,
                    'product_template_field_id' => $newFieldId,
                    'value' => $meta->value,
                ]);
            }

            $this->createdCount++;
        }
    }

    /**
     * Sync product tags from legacy store_tags table.
     */
    protected function syncProductTags(Product $product, int $legacyProductId, int $legacyStoreId, Store $newStore, bool $isDryRun): void
    {
        // Get tags for this product from legacy store_tags table
        $legacyTags = DB::connection('legacy')
            ->table('store_tags')
            ->where('store_id', $legacyStoreId)
            ->where('tagable_type', 'App\\Models\\Product')
            ->where('tagable_id', $legacyProductId)
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->get();

        if ($legacyTags->isEmpty()) {
            return;
        }

        foreach ($legacyTags as $legacyTag) {
            $tagName = trim($legacyTag->value);
            if (empty($tagName)) {
                continue;
            }

            // Get or create the tag
            $tagId = $this->getOrCreateTag($tagName, $newStore, $isDryRun);

            if (! $tagId) {
                continue;
            }

            // Check if tag is already attached to product
            $exists = DB::table('taggables')
                ->where('tag_id', $tagId)
                ->where('taggable_type', Product::class)
                ->where('taggable_id', $product->id)
                ->exists();

            if ($exists) {
                $this->tagsSkipped++;

                continue;
            }

            if (! $isDryRun) {
                DB::table('taggables')->insert([
                    'tag_id' => $tagId,
                    'taggable_type' => Product::class,
                    'taggable_id' => $product->id,
                    'created_at' => $legacyTag->created_at ?? now(),
                    'updated_at' => $legacyTag->updated_at ?? now(),
                ]);
            }

            $this->tagsAttached++;
        }
    }

    /**
     * Get or create a tag by name.
     */
    protected function getOrCreateTag(string $name, Store $newStore, bool $isDryRun): ?int
    {
        // Check cache first
        if (isset($this->tagMap[$name])) {
            return $this->tagMap[$name];
        }

        // Check if tag exists in database
        $existingTag = Tag::where('store_id', $newStore->id)
            ->where('name', $name)
            ->first();

        if ($existingTag) {
            $this->tagMap[$name] = $existingTag->id;

            return $existingTag->id;
        }

        if ($isDryRun) {
            // In dry run, use a placeholder ID
            $this->tagsCreated++;

            return -1;
        }

        // Create the tag
        $tag = Tag::create([
            'store_id' => $newStore->id,
            'name' => $name,
            'slug' => Str::slug($name),
            'color' => $this->generateTagColor($name),
        ]);

        $this->tagMap[$name] = $tag->id;
        $this->tagsCreated++;

        return $tag->id;
    }

    /**
     * Generate a consistent color based on tag name.
     */
    protected function generateTagColor(string $tagName): string
    {
        $colors = [
            '#ef4444', '#f97316', '#f59e0b', '#eab308', '#84cc16',
            '#22c55e', '#14b8a6', '#06b6d4', '#3b82f6', '#6366f1',
            '#8b5cf6', '#a855f7', '#d946ef', '#ec4899',
        ];

        $hash = crc32($tagName);

        return $colors[abs($hash) % count($colors)];
    }

    /**
     * Try to find the legacy product ID by matching handle or SKU.
     */
    protected function findLegacyProductId(Product $product, int $legacyStoreId): ?int
    {
        // Try by handle (extract legacy ID from handle like "product-name-12345")
        if ($product->handle && preg_match('/-(\d+)$/', $product->handle, $matches)) {
            $potentialLegacyId = (int) $matches[1];

            $exists = DB::connection('legacy')
                ->table('products')
                ->where('id', $potentialLegacyId)
                ->where('store_id', $legacyStoreId)
                ->exists();

            if ($exists) {
                return $potentialLegacyId;
            }
        }

        // Try by SKU
        $sku = $product->variants()->first()?->sku;
        if ($sku) {
            $legacyProduct = DB::connection('legacy')
                ->table('products')
                ->where('store_id', $legacyStoreId)
                ->where('sku', $sku)
                ->first();

            if ($legacyProduct) {
                return $legacyProduct->id;
            }
        }

        return null;
    }

    protected function displaySummary(): void
    {
        $this->newLine();
        $this->info('=== Sync Summary ===');
        $this->line("Products processed: {$this->processedProducts}");
        $this->newLine();
        $this->line('Attribute Values:');
        $this->line("  Created: {$this->createdCount}");
        $this->line("  Updated: {$this->updatedCount}");
        $this->line("  Skipped (already exist): {$this->skippedCount}");

        if ($this->tagsCreated > 0 || $this->tagsAttached > 0 || $this->tagsSkipped > 0) {
            $this->newLine();
            $this->line('Tags:');
            $this->line("  New tags created: {$this->tagsCreated}");
            $this->line("  Tags attached to products: {$this->tagsAttached}");
            $this->line("  Tags skipped (already attached): {$this->tagsSkipped}");
        }
    }
}
