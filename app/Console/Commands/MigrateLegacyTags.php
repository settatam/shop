<?php

namespace App\Console\Commands;

use App\Models\Memo;
use App\Models\Product;
use App\Models\Repair;
use App\Models\Store;
use App\Models\Tag;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Migrate tags from the legacy Laravel 8 application.
 *
 * In the legacy system, tags are stored in the store_tags table with a 'value' column
 * containing the tag name, and polymorphic tagable_type/tagable_id for relationships.
 */
class MigrateLegacyTags extends Command
{
    protected $signature = 'migrate:legacy-tags
                            {--store-id=63 : Legacy store ID to migrate}
                            {--new-store-id= : New store ID (if different from legacy)}
                            {--dry-run : Show what would be migrated without making changes}
                            {--fresh : Delete existing tags and start fresh}';

    protected $description = 'Migrate tags from the legacy database (store_tags table)';

    protected array $tagMap = [];

    protected array $productMap = [];

    protected array $transactionMap = [];

    protected array $memoMap = [];

    protected array $repairMap = [];

    public function handle(): int
    {
        $legacyStoreId = (int) $this->option('store-id');
        $newStoreId = $this->option('new-store-id') ? (int) $this->option('new-store-id') : null;
        $isDryRun = $this->option('dry-run');

        $this->info("Starting tag migration from legacy store ID: {$legacyStoreId}");
        $this->info('(Importing from store_tags table)');

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

        $this->info("Migrating tags to store: {$newStore->name} (ID: {$newStore->id})");

        // Load mapping files from previous migrations
        $this->loadMappingFiles($legacyStoreId);

        if ($this->option('fresh') && ! $isDryRun) {
            $shouldCleanup = ! $this->input->isInteractive() || $this->confirm('This will delete all existing tags for this store. Continue?');
            if ($shouldCleanup) {
                $this->cleanupExistingTags($newStore);
            }
        }

        try {
            DB::beginTransaction();

            $this->migrateTags($legacyStoreId, $newStore, $isDryRun);

            if ($isDryRun) {
                DB::rollBack();
                $this->info('Dry run complete - no changes made');
            } else {
                DB::commit();
                $this->info('Tag migration complete!');
            }

            $this->displaySummary($newStore);

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

        // Load product map
        $productMapFile = "{$basePath}/product_map_{$legacyStoreId}.json";
        if (file_exists($productMapFile)) {
            $this->productMap = json_decode(file_get_contents($productMapFile), true) ?? [];
            $this->line('  Loaded '.count($this->productMap).' product mappings');
        }

        // Load transaction map
        $transactionMapFile = "{$basePath}/transaction_map_{$legacyStoreId}.json";
        if (file_exists($transactionMapFile)) {
            $this->transactionMap = json_decode(file_get_contents($transactionMapFile), true) ?? [];
            $this->line('  Loaded '.count($this->transactionMap).' transaction mappings');
        }

        // Load memo map
        $memoMapFile = "{$basePath}/memo_map_{$legacyStoreId}.json";
        if (file_exists($memoMapFile)) {
            $this->memoMap = json_decode(file_get_contents($memoMapFile), true) ?? [];
            $this->line('  Loaded '.count($this->memoMap).' memo mappings');
        }

        // Load repair map
        $repairMapFile = "{$basePath}/repair_map_{$legacyStoreId}.json";
        if (file_exists($repairMapFile)) {
            $this->repairMap = json_decode(file_get_contents($repairMapFile), true) ?? [];
            $this->line('  Loaded '.count($this->repairMap).' repair mappings');
        }
    }

    protected function migrateTags(int $legacyStoreId, Store $newStore, bool $isDryRun): void
    {
        $this->info('Migrating tags from store_tags table...');

        // Get all unique tag values from legacy store_tags
        $uniqueTags = DB::connection('legacy')
            ->table('store_tags')
            ->where('store_id', $legacyStoreId)
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->distinct()
            ->pluck('value');

        if ($uniqueTags->isEmpty()) {
            $this->line('  No tags to migrate');

            return;
        }

        $this->line("  Found {$uniqueTags->count()} unique tag values");

        // Step 1: Create Tag records for unique values
        $tagCount = 0;
        foreach ($uniqueTags as $tagValue) {
            $tagValue = trim($tagValue);
            if (empty($tagValue)) {
                continue;
            }

            // Check if tag already exists
            $existingTag = Tag::where('store_id', $newStore->id)
                ->where('name', $tagValue)
                ->first();

            if ($existingTag) {
                $this->tagMap[$tagValue] = $existingTag->id;

                continue;
            }

            if ($isDryRun) {
                $this->line("  Would create tag: {$tagValue}");
                $tagCount++;

                continue;
            }

            $tag = Tag::create([
                'store_id' => $newStore->id,
                'name' => $tagValue,
                'slug' => Str::slug($tagValue),
                'color' => $this->generateColor($tagValue),
            ]);

            $this->tagMap[$tagValue] = $tag->id;
            $tagCount++;
        }

        $this->line("  Created {$tagCount} tags");

        // Step 2: Create taggable relationships
        $this->migrateTagRelationships($legacyStoreId, $newStore, $isDryRun);
    }

    protected function migrateTagRelationships(int $legacyStoreId, Store $newStore, bool $isDryRun): void
    {
        $this->info('Migrating tag relationships...');

        $legacyTags = DB::connection('legacy')
            ->table('store_tags')
            ->where('store_id', $legacyStoreId)
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->get();

        $productCount = 0;
        $transactionCount = 0;
        $memoCount = 0;
        $repairCount = 0;
        $skipped = 0;

        foreach ($legacyTags as $legacyTag) {
            $tagValue = trim($legacyTag->value);
            if (empty($tagValue) || ! isset($this->tagMap[$tagValue])) {
                $skipped++;

                continue;
            }

            $newTagId = $this->tagMap[$tagValue];
            $newModelId = null;
            $newModelType = null;

            // Map the model type and ID
            switch ($legacyTag->tagable_type) {
                case 'App\\Models\\Product':
                    if (isset($this->productMap[$legacyTag->tagable_id])) {
                        $newModelId = $this->productMap[$legacyTag->tagable_id];
                        $newModelType = Product::class;
                        $productCount++;
                    }
                    break;

                case 'App\\Models\\Transaction':
                    if (isset($this->transactionMap[$legacyTag->tagable_id])) {
                        $newModelId = $this->transactionMap[$legacyTag->tagable_id];
                        $newModelType = Transaction::class;
                        $transactionCount++;
                    }
                    break;

                case 'App\\Models\\Memo':
                    if (isset($this->memoMap[$legacyTag->tagable_id])) {
                        $newModelId = $this->memoMap[$legacyTag->tagable_id];
                        $newModelType = Memo::class;
                        $memoCount++;
                    }
                    break;

                case 'App\\Models\\Repair':
                    if (isset($this->repairMap[$legacyTag->tagable_id])) {
                        $newModelId = $this->repairMap[$legacyTag->tagable_id];
                        $newModelType = Repair::class;
                        $repairCount++;
                    }
                    break;

                default:
                    $skipped++;

                    continue 2;
            }

            if (! $newModelId || ! $newModelType) {
                $skipped++;

                continue;
            }

            if ($isDryRun) {
                continue;
            }

            // Check if relationship already exists
            $exists = DB::table('taggables')
                ->where('tag_id', $newTagId)
                ->where('taggable_type', $newModelType)
                ->where('taggable_id', $newModelId)
                ->exists();

            if ($exists) {
                continue;
            }

            // Create the taggable relationship
            DB::table('taggables')->insert([
                'tag_id' => $newTagId,
                'taggable_type' => $newModelType,
                'taggable_id' => $newModelId,
                'created_at' => $legacyTag->created_at,
                'updated_at' => $legacyTag->updated_at,
            ]);
        }

        $this->line("  Tagged {$productCount} products, {$transactionCount} transactions, {$memoCount} memos, {$repairCount} repairs");
        $this->line("  Skipped {$skipped} (unmapped or invalid)");
    }

    protected function generateColor(string $tagName): string
    {
        // Generate a consistent color based on the tag name
        $colors = [
            '#ef4444', '#f97316', '#f59e0b', '#eab308', '#84cc16',
            '#22c55e', '#14b8a6', '#06b6d4', '#3b82f6', '#6366f1',
            '#8b5cf6', '#a855f7', '#d946ef', '#ec4899',
        ];

        $hash = crc32($tagName);

        return $colors[abs($hash) % count($colors)];
    }

    protected function cleanupExistingTags(Store $newStore): void
    {
        $this->warn('Cleaning up existing tags...');

        $tagIds = Tag::where('store_id', $newStore->id)->pluck('id');

        DB::table('taggables')->whereIn('tag_id', $tagIds)->delete();
        Tag::where('store_id', $newStore->id)->delete();

        $this->line('  Cleanup complete');
    }

    protected function displaySummary(Store $newStore): void
    {
        $this->newLine();
        $this->info('=== Tag Migration Summary ===');
        $this->line('Store: '.$newStore->name.' (ID: '.$newStore->id.')');
        $this->line('Tags created/mapped: '.count($this->tagMap));

        $tagCount = Tag::where('store_id', $newStore->id)->count();
        $taggableCount = DB::table('taggables')
            ->whereIn('tag_id', Tag::where('store_id', $newStore->id)->pluck('id'))
            ->count();

        $this->line("Total tags in store: {$tagCount}");
        $this->line("Total tag relationships: {$taggableCount}");
    }
}
