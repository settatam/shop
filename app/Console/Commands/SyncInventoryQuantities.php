<?php

namespace App\Console\Commands;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncInventoryQuantities extends Command
{
    protected $signature = 'inventory:sync-quantities';

    protected $description = 'Sync product_variants.quantity and products.quantity from inventory table totals';

    public function handle(): int
    {
        $this->info('Syncing variant quantities from inventory...');

        $variantTotals = Inventory::query()
            ->select('product_variant_id', DB::raw('SUM(quantity) as total'))
            ->groupBy('product_variant_id')
            ->get();

        $variantCount = 0;
        foreach ($variantTotals as $row) {
            ProductVariant::where('id', $row->product_variant_id)
                ->update(['quantity' => $row->total]);
            $variantCount++;
        }

        $this->info("Updated {$variantCount} variant(s).");

        $this->info('Syncing product quantities from variants...');

        $productTotals = ProductVariant::query()
            ->select('product_id', DB::raw('SUM(quantity) as total'))
            ->groupBy('product_id')
            ->get();

        $productCount = 0;
        foreach ($productTotals as $row) {
            Product::where('id', $row->product_id)
                ->update(['quantity' => $row->total]);
            $productCount++;
        }

        $this->info("Updated {$productCount} product(s).");
        $this->info('Inventory quantities synced successfully.');

        return self::SUCCESS;
    }
}
