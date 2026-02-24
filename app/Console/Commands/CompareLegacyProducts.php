<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CompareLegacyProducts extends Command
{
    protected $signature = 'compare:legacy-products
                            {--store-id=63 : Legacy store ID to compare}
                            {--new-store-id= : New store ID (if different from legacy)}
                            {--show-all : Show all missing products (default shows first 100)}
                            {--export : Export missing products to CSV}';

    protected $description = 'Compare products between legacy and new database, listing products that exist in legacy but not in new';

    public function handle(): int
    {
        $legacyStoreId = (int) $this->option('store-id');
        $newStoreId = $this->option('new-store-id') ? (int) $this->option('new-store-id') : $legacyStoreId;

        $this->info("Comparing products for legacy store {$legacyStoreId} -> new store {$newStoreId}");

        // Test legacy database connection
        try {
            DB::connection('legacy')->getPdo();
        } catch (\Exception $e) {
            $this->error('Could not connect to legacy database: '.$e->getMessage());

            return 1;
        }

        // Get all legacy product IDs for the store
        $legacyProducts = DB::connection('legacy')
            ->table('products')
            ->where('store_id', $legacyStoreId)
            ->whereNull('deleted_at')
            ->select('id', 'title', 'sku', 'price', 'status', 'created_at')
            ->orderBy('id')
            ->get();

        $this->info("Found {$legacyProducts->count()} products in legacy store {$legacyStoreId}");

        // Load the product mapping file if it exists
        $mapFile = "migration_maps/product_map_{$legacyStoreId}.json";
        $productMap = [];
        if (Storage::disk('local')->exists($mapFile)) {
            $productMap = json_decode(Storage::disk('local')->get($mapFile), true) ?? [];
            $this->info('Loaded '.count($productMap)." product mappings from {$mapFile}");
        }

        // Get all new product IDs for the store
        $newProductIds = Product::where('store_id', $newStoreId)
            ->pluck('id')
            ->flip()
            ->toArray();

        $this->info('Found '.count($newProductIds)." products in new store {$newStoreId}");

        // Find missing products
        $missingProducts = [];
        foreach ($legacyProducts as $legacyProduct) {
            $legacyId = $legacyProduct->id;

            // Check if this legacy product was mapped to a new product
            $newId = $productMap[$legacyId] ?? $legacyId;

            // Check if the new product exists
            if (! isset($newProductIds[$newId])) {
                $missingProducts[] = [
                    'legacy_id' => $legacyId,
                    'expected_new_id' => $newId,
                    'title' => $legacyProduct->title,
                    'sku' => $legacyProduct->sku,
                    'price' => $legacyProduct->price,
                    'status' => $legacyProduct->status,
                    'created_at' => $legacyProduct->created_at,
                ];
            }
        }

        $missingCount = count($missingProducts);

        if ($missingCount === 0) {
            $this->info('All legacy products exist in the new database!');

            return 0;
        }

        $this->warn("Found {$missingCount} products in legacy that are NOT in the new database:");
        $this->newLine();

        // Display results
        $displayProducts = $this->option('show-all') ? $missingProducts : array_slice($missingProducts, 0, 100);

        $this->table(
            ['Legacy ID', 'Title', 'SKU', 'Price', 'Status', 'Created'],
            collect($displayProducts)->map(fn ($p) => [
                $p['legacy_id'],
                \Illuminate\Support\Str::limit($p['title'] ?? 'N/A', 40),
                $p['sku'] ?? 'N/A',
                $p['price'] ? '$'.number_format($p['price'], 2) : 'N/A',
                $p['status'] ?? 'N/A',
                $p['created_at'] ? date('Y-m-d', strtotime($p['created_at'])) : 'N/A',
            ])->toArray()
        );

        if (! $this->option('show-all') && $missingCount > 100) {
            $this->info("Showing first 100 of {$missingCount} missing products. Use --show-all to see all.");
        }

        // Export to CSV if requested
        if ($this->option('export')) {
            $filename = "missing_products_store_{$legacyStoreId}_".date('Y-m-d_His').'.csv';
            $filepath = storage_path("app/{$filename}");

            $handle = fopen($filepath, 'w');
            fputcsv($handle, ['Legacy ID', 'Expected New ID', 'Title', 'SKU', 'Price', 'Status', 'Created At']);
            foreach ($missingProducts as $product) {
                fputcsv($handle, [
                    $product['legacy_id'],
                    $product['expected_new_id'],
                    $product['title'],
                    $product['sku'],
                    $product['price'],
                    $product['status'],
                    $product['created_at'],
                ]);
            }
            fclose($handle);

            $this->info("Exported to: {$filepath}");
        }

        $this->newLine();
        $this->info("Summary: {$missingCount} missing out of {$legacyProducts->count()} legacy products");

        return 0;
    }
}
