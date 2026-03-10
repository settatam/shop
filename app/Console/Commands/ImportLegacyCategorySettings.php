<?php

namespace App\Console\Commands;

use App\Models\Category;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportLegacyCategorySettings extends Command
{
    protected $signature = 'import:legacy-category-settings
                            {--legacy-store=63 : Legacy store ID to import from}
                            {--target-store=2 : Target store ID in the new system}
                            {--target-connection=prod : Database connection for the target store}
                            {--dry-run : Show what would be updated without making changes}';

    protected $description = 'Import SKU prefix, SKU format, and barcode settings from legacy store_categories into the new categories table';

    /**
     * Map legacy barcode_sequence display names to new system attribute names.
     */
    protected array $barcodeNameMap = [
        'price code' => 'price_code',
        'price' => 'price',
        'selling price' => 'price',
        'retail price' => 'price',
        'approx. retail price' => 'price',
        'main stone wt' => 'main_stone_wt',
        'diamond color' => 'diamond_color',
        'diamond clarity' => 'diamond_clarity',
        'precious metals' => 'precious_metals',
        'dwt' => 'dwt',
        'total dwt' => 'total_dwt',
        'total stone weight' => 'total_stone_wt',
        'total stone wt' => 'total_stone_wt',
        'total carat weight' => 'total_carat_weight',
        'ring size' => 'ring_size',
        'main stone type' => 'main_stone_type',
        'brand' => 'brand',
        'product name' => 'product_name',
        'condition' => 'condition',
        'model #' => 'model_number',
        'serial number' => 'serial_number',
        'year manufactored' => 'year_manufactored',
        'diamond dial' => 'diamond_dial',
        'semi-mount stone shape' => 'semi_mount_stone_shape',
        'semi-mount size' => 'semi_mount_size',
        'description of stones' => 'description_of_stones',
        'second stone color' => 'second_stone_color',
        'category' => 'category',
    ];

    public function handle(): int
    {
        $legacyStoreId = (int) $this->option('legacy-store');
        $targetStoreId = (int) $this->option('target-store');
        $targetConnection = $this->option('target-connection');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        $this->info("Importing from legacy store {$legacyStoreId} → target store {$targetStoreId} (connection: {$targetConnection})");

        // Load legacy categories with their full parent path
        $legacyCategories = $this->loadLegacyCategories($legacyStoreId);

        if ($legacyCategories->isEmpty()) {
            $this->error("No categories with SKU prefix found for legacy store {$legacyStoreId}.");

            return self::FAILURE;
        }

        $this->info("Found {$legacyCategories->count()} legacy categories with SKU prefixes.");

        // Load target categories with their full parent path
        $targetCategories = $this->loadTargetCategories($targetStoreId, $targetConnection);

        $matched = 0;
        $unmatched = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($legacyCategories as $legacy) {
            $legacyPath = $this->buildPath($legacy, $legacyCategories);

            // Find matching target category by full path
            $target = $this->findMatchingTarget($legacy, $legacyPath, $targetCategories);

            if (! $target) {
                $this->warn("  No match: {$legacyPath} (legacy ID {$legacy->id})");
                $unmatched++;

                continue;
            }

            $matched++;

            $skuPrefix = $legacy->sku_prefix;
            $skuFormat = $skuPrefix ? '{category_code}-{product_id}' : null;
            $barcodeAttributes = $this->convertBarcodeSequence($legacy->barcode_sequence);

            // Check if already set
            $targetCategory = DB::connection($targetConnection)
                ->table('categories')
                ->where('id', $target->id)
                ->first();

            $changes = [];

            if (! $targetCategory->sku_prefix && $skuPrefix) {
                $changes['sku_prefix'] = $skuPrefix;
            } elseif ($targetCategory->sku_prefix && $skuPrefix && $targetCategory->sku_prefix !== $skuPrefix) {
                $changes['sku_prefix'] = $skuPrefix;
            }

            if (! $targetCategory->sku_format && $skuFormat) {
                $changes['sku_format'] = $skuFormat;
            }

            if (! $targetCategory->barcode_attributes && $barcodeAttributes) {
                $changes['barcode_attributes'] = json_encode($barcodeAttributes);
            }

            if (empty($changes)) {
                $this->line("  Skip (already set): {$legacyPath}");
                $skipped++;

                continue;
            }

            $changeDesc = collect($changes)->map(fn ($v, $k) => "{$k}={$v}")->join(', ');
            $this->info("  Update: {$legacyPath} → {$changeDesc}");

            if (! $dryRun) {
                DB::connection($targetConnection)
                    ->table('categories')
                    ->where('id', $target->id)
                    ->update($changes);
            }

            $updated++;
        }

        $this->newLine();
        $this->info('Summary:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Legacy categories with prefix', $legacyCategories->count()],
                ['Matched to target', $matched],
                ['Updated', $updated],
                ['Skipped (already set)', $skipped],
                ['Unmatched', $unmatched],
            ]
        );

        if ($dryRun) {
            $this->warn('DRY RUN complete. Re-run without --dry-run to apply changes.');
        }

        return self::SUCCESS;
    }

    protected function loadLegacyCategories(int $storeId): \Illuminate\Support\Collection
    {
        return DB::connection('legacy')
            ->table('store_categories')
            ->where('store_id', $storeId)
            ->whereNull('deleted_at')
            ->whereNotNull('sku_prefix')
            ->where('sku_prefix', '!=', '')
            ->select('id', 'name', 'parent_id', 'level', 'sku_prefix', 'barcode_sequence')
            ->get();
    }

    protected function loadTargetCategories(int $storeId, string $connection): \Illuminate\Support\Collection
    {
        return DB::connection($connection)
            ->table('categories')
            ->where('store_id', $storeId)
            ->select('id', 'name', 'parent_id', 'sku_prefix', 'sku_format', 'barcode_attributes')
            ->get();
    }

    protected function buildPath(object $category, \Illuminate\Support\Collection $allCategories): string
    {
        $path = [$category->name];
        $current = $category;

        while ($current->parent_id && $current->parent_id !== 0) {
            $parent = $allCategories->firstWhere('id', $current->parent_id);

            if (! $parent) {
                // Parent might be a level-1 category without sku_prefix, load it directly
                $parent = DB::connection('legacy')
                    ->table('store_categories')
                    ->where('id', $current->parent_id)
                    ->whereNull('deleted_at')
                    ->select('id', 'name', 'parent_id', 'level')
                    ->first();
            }

            if (! $parent) {
                break;
            }

            $path[] = $parent->name;
            $current = $parent;
        }

        return implode(' > ', array_reverse($path));
    }

    protected function findMatchingTarget(
        object $legacy,
        string $legacyPath,
        \Illuminate\Support\Collection $targetCategories
    ): ?object {
        // First try matching by name + parent chain
        $candidates = $targetCategories->where('name', $legacy->name);

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        // Multiple candidates — match by parent name
        foreach ($candidates as $candidate) {
            $targetPath = $this->buildTargetPath($candidate, $targetCategories);

            if ($targetPath === $legacyPath) {
                return $candidate;
            }
        }

        // Fallback: try matching by ID (IDs are same between legacy and prod)
        $byId = $targetCategories->firstWhere('id', $legacy->id);

        if ($byId && $byId->name === $legacy->name) {
            return $byId;
        }

        return null;
    }

    protected function buildTargetPath(object $category, \Illuminate\Support\Collection $allCategories): string
    {
        $path = [$category->name];
        $current = $category;

        while ($current->parent_id) {
            $parent = $allCategories->firstWhere('id', $current->parent_id);

            if (! $parent) {
                break;
            }

            $path[] = $parent->name;
            $current = $parent;
        }

        return implode(' > ', array_reverse($path));
    }

    protected function convertBarcodeSequence(?string $sequence): ?array
    {
        if (! $sequence || trim($sequence) === '') {
            return null;
        }

        $items = array_map('trim', explode(',', $sequence));
        $attributes = [];

        foreach ($items as $item) {
            if ($item === '') {
                continue;
            }

            $key = strtolower(trim($item));
            $mapped = $this->barcodeNameMap[$key] ?? null;

            if ($mapped) {
                if (! in_array($mapped, $attributes)) {
                    $attributes[] = $mapped;
                }
            } else {
                // Convert to snake_case as fallback
                $snake = str_replace([' ', '-', '#'], ['_', '_', ''], strtolower(trim($item)));
                if (! in_array($snake, $attributes)) {
                    $attributes[] = $snake;
                    $this->warn("    Unmapped barcode attribute: '{$item}' → '{$snake}'");
                }
            }
        }

        return $attributes ?: null;
    }
}
