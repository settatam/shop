<?php

namespace App\Console\Commands;

use App\Models\Inventory;
use App\Models\InventoryAdjustment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateHistoricalInventoryAdjustments extends Command
{
    protected $signature = 'app:generate-historical-inventory-adjustments
                            {--store-id= : The store ID to process}
                            {--dry-run : Show what would be created without actually creating}';

    protected $description = 'Generate historical inventory adjustments based on product creation dates, sales, and deletions';

    protected int $initialAdjustmentsCreated = 0;

    protected int $saleAdjustmentsCreated = 0;

    protected int $deletionAdjustmentsCreated = 0;

    public function handle(): int
    {
        $storeId = $this->option('store-id');
        $dryRun = $this->option('dry-run');

        if (! $storeId) {
            $this->error('Please provide a store ID with --store-id');

            return self::FAILURE;
        }

        $store = Store::find($storeId);
        if (! $store) {
            $this->error("Store with ID {$storeId} not found");

            return self::FAILURE;
        }

        $this->info("Generating historical inventory adjustments for store: {$store->name}");

        if ($dryRun) {
            $this->warn('DRY RUN - No records will be created');
        }

        // Check if adjustments already exist
        $existingCount = InventoryAdjustment::where('store_id', $storeId)->count();
        if ($existingCount > 0) {
            if (! $this->confirm("Store already has {$existingCount} adjustments. Continue anyway?")) {
                return self::SUCCESS;
            }
        }

        // Get all inventory records for the store (including deleted products)
        $inventoryRecords = Inventory::where('store_id', $storeId)
            ->with(['variant' => function ($q) {
                $q->withTrashed()->with(['product' => function ($q) {
                    $q->withTrashed();
                }]);
            }])
            ->get();

        $this->info("Found {$inventoryRecords->count()} inventory records");

        // Get all completed orders for the store with their items
        $completedOrders = Order::where('store_id', $storeId)
            ->whereIn('status', Order::PAID_STATUSES)
            ->with('items')
            ->orderBy('date_of_purchase')
            ->orderBy('created_at')
            ->get();

        $this->info("Found {$completedOrders->count()} completed orders");

        // Count deleted products
        $deletedCount = $inventoryRecords->filter(function ($inv) {
            return $inv->variant?->product?->deleted_at !== null;
        })->count();
        $this->info("Found {$deletedCount} deleted products with inventory");

        // Build a map of variant_id => inventory
        $variantInventoryMap = [];
        foreach ($inventoryRecords as $inventory) {
            $variantInventoryMap[$inventory->product_variant_id] = $inventory;
        }

        // Collect all events chronologically
        $events = collect();

        // Add initial inventory events (additions)
        foreach ($inventoryRecords as $inventory) {
            $variant = $inventory->variant;
            $product = $variant?->product;
            $createdAt = $variant?->created_at ?? $inventory->created_at;

            // Calculate original quantity by adding back all sales and considering deletions
            $salesQuantity = OrderItem::whereHas('order', function ($q) use ($storeId) {
                $q->where('store_id', $storeId)
                    ->whereIn('status', Order::PAID_STATUSES);
            })
                ->where('product_variant_id', $inventory->product_variant_id)
                ->sum('quantity');

            // For deleted products, the current quantity is 0, so original = sales quantity
            // For active products, original = current + sales
            $isDeleted = $product?->deleted_at !== null;
            $originalQuantity = $isDeleted
                ? $salesQuantity + $inventory->quantity // Add back whatever was there before deletion
                : $inventory->quantity + $salesQuantity;

            // If deleted and no sales, original quantity was the inventory quantity before deletion
            if ($isDeleted && $salesQuantity == 0) {
                $originalQuantity = $inventory->quantity > 0 ? $inventory->quantity : 1; // At least 1 if it existed
            }

            if ($originalQuantity > 0) {
                $events->push([
                    'type' => 'initial',
                    'inventory_id' => $inventory->id,
                    'variant_id' => $inventory->product_variant_id,
                    'quantity' => $originalQuantity,
                    'unit_cost' => $inventory->unit_cost ?? 0,
                    'date' => $createdAt,
                    'reference' => 'Initial inventory',
                ]);
            }

            // Add deletion event if product is deleted
            if ($isDeleted && $product->deleted_at) {
                // Calculate remaining quantity at deletion time (after sales)
                $remainingAtDeletion = $originalQuantity - $salesQuantity;
                if ($remainingAtDeletion > 0) {
                    $events->push([
                        'type' => 'deleted',
                        'inventory_id' => $inventory->id,
                        'variant_id' => $inventory->product_variant_id,
                        'quantity' => -$remainingAtDeletion, // Negative for removal
                        'unit_cost' => $inventory->unit_cost ?? 0,
                        'date' => $product->deleted_at,
                        'reference' => 'Product deleted',
                    ]);
                }
            }
        }

        // Add sale events (removals)
        foreach ($completedOrders as $order) {
            $orderDate = $order->date_of_purchase ?? $order->created_at;

            foreach ($order->items as $item) {
                if (! $item->product_variant_id) {
                    continue;
                }

                if (! isset($variantInventoryMap[$item->product_variant_id])) {
                    continue; // No inventory record for this variant
                }

                $inventory = $variantInventoryMap[$item->product_variant_id];

                $events->push([
                    'type' => 'sale',
                    'inventory_id' => $inventory->id,
                    'variant_id' => $item->product_variant_id,
                    'quantity' => -$item->quantity, // Negative for sales
                    'unit_cost' => $item->cost ?? $inventory->unit_cost ?? 0,
                    'date' => $orderDate,
                    'reference' => "Order #{$order->order_id}",
                    'order_id' => $order->id,
                ]);
            }
        }

        // Sort events chronologically
        $events = $events->sortBy('date')->values();

        $this->info("Total events to process: {$events->count()}");

        // Track running totals per inventory
        $runningTotals = [];

        $this->output->progressStart($events->count());

        DB::beginTransaction();

        try {
            foreach ($events as $event) {
                $inventoryId = $event['inventory_id'];

                // Initialize running total if needed
                if (! isset($runningTotals[$inventoryId])) {
                    $runningTotals[$inventoryId] = 0;
                }

                $quantityBefore = $runningTotals[$inventoryId];
                $quantityChange = $event['quantity'];
                $quantityAfter = $quantityBefore + $quantityChange;

                // Update running total
                $runningTotals[$inventoryId] = $quantityAfter;

                if (! $dryRun) {
                    $adjustment = new InventoryAdjustment;
                    $adjustment->store_id = $storeId;
                    $adjustment->inventory_id = $inventoryId;
                    $adjustment->type = match ($event['type']) {
                        'initial' => InventoryAdjustment::TYPE_INITIAL,
                        'sale' => InventoryAdjustment::TYPE_SOLD,
                        'deleted' => InventoryAdjustment::TYPE_DELETED,
                    };
                    $adjustment->quantity_before = $quantityBefore;
                    $adjustment->quantity_change = $quantityChange;
                    $adjustment->quantity_after = $quantityAfter;
                    $adjustment->unit_cost = $event['unit_cost'];
                    $adjustment->total_cost_impact = $quantityChange * $event['unit_cost'];
                    $adjustment->reference = $event['reference'];
                    $adjustment->notes = match ($event['type']) {
                        'initial' => 'Historical initial inventory',
                        'sale' => 'Historical sale',
                        'deleted' => 'Historical deletion',
                    };
                    $adjustment->created_at = $event['date'];
                    $adjustment->updated_at = $event['date'];
                    $adjustment->save();
                }

                match ($event['type']) {
                    'initial' => $this->initialAdjustmentsCreated++,
                    'sale' => $this->saleAdjustmentsCreated++,
                    'deleted' => $this->deletionAdjustmentsCreated++,
                };

                $this->output->progressAdvance();
            }

            if (! $dryRun) {
                DB::commit();
            } else {
                DB::rollBack();
            }
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Error: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->output->progressFinish();

        $this->newLine();
        $this->info('=== Summary ===');
        $this->info("Initial adjustments (items added): {$this->initialAdjustmentsCreated}");
        $this->info("Sale adjustments (items sold): {$this->saleAdjustmentsCreated}");
        $this->info("Deletion adjustments (items removed): {$this->deletionAdjustmentsCreated}");
        $this->info('Total: '.($this->initialAdjustmentsCreated + $this->saleAdjustmentsCreated + $this->deletionAdjustmentsCreated));

        if ($dryRun) {
            $this->warn('DRY RUN - No records were created');
        } else {
            $this->info('Historical inventory adjustments generated successfully!');
        }

        return self::SUCCESS;
    }
}
