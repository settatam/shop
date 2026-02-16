<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillTransactionItemPrices extends Command
{
    protected $signature = 'backfill:transaction-item-prices
                            {--store-id= : Specific store ID to process}
                            {--dry-run : Show what would be updated without making changes}';

    protected $description = 'Backfill estimated values (price) for transaction items that have 0 price but their transaction has a final_offer';

    public function handle(): int
    {
        $storeId = $this->option('store-id');
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $this->info('Finding transactions with items that need price backfill...');

        // Find transactions where:
        // 1. final_offer > 0
        // 2. Has at least one item with price = 0 or null
        $query = Transaction::query()
            ->where('final_offer', '>', 0)
            ->whereHas('items', function ($q) {
                $q->where(function ($q2) {
                    $q2->whereNull('price')
                        ->orWhere('price', 0);
                });
            });

        if ($storeId) {
            $query->where('store_id', $storeId);
        }

        $transactions = $query->with('items')->get();

        $this->info("Found {$transactions->count()} transactions to process");

        if ($transactions->isEmpty()) {
            $this->info('No transactions need backfilling.');

            return 0;
        }

        $updatedItems = 0;
        $processedTransactions = 0;

        DB::beginTransaction();

        try {
            foreach ($transactions as $transaction) {
                $items = $transaction->items;
                $finalOffer = (float) $transaction->final_offer;

                // Calculate prices for all items
                $itemPrices = $this->calculateItemPrices($items, $finalOffer);

                foreach ($items as $index => $item) {
                    $currentPrice = (float) $item->price;
                    $newPrice = $itemPrices[$index]['price'];
                    $newBuyPrice = $itemPrices[$index]['buy_price'];

                    // Only update items that have 0 or null price
                    if ($currentPrice <= 0 && $newPrice > 0) {
                        if ($isDryRun) {
                            $this->line("  Would update item #{$item->id}: price=0 -> {$newPrice}, buy_price -> {$newBuyPrice}");
                        } else {
                            $item->update([
                                'price' => $newPrice,
                                'buy_price' => $newBuyPrice,
                            ]);
                        }
                        $updatedItems++;
                    }
                }

                $processedTransactions++;

                if ($processedTransactions % 100 === 0) {
                    $this->line("  Processed {$processedTransactions} transactions...");
                }
            }

            if ($isDryRun) {
                DB::rollBack();
                $this->info("DRY RUN: Would have updated {$updatedItems} items across {$processedTransactions} transactions");
            } else {
                DB::commit();
                $this->info("Updated {$updatedItems} items across {$processedTransactions} transactions");
            }

            return 0;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Error: {$e->getMessage()}");

            return 1;
        }
    }

    /**
     * Calculate estimated value (price) and buy_price for transaction items.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $items
     * @return array<int, array{price: float, buy_price: float}>
     */
    protected function calculateItemPrices($items, float $finalOffer): array
    {
        $itemPrices = [];
        $itemsWithoutPrice = [];
        $totalExistingPrices = 0;

        // First pass: identify items with and without prices
        foreach ($items as $index => $item) {
            $existingPrice = (float) ($item->price ?? 0);
            $buyPrice = (float) ($item->buy_price ?? 0);

            if ($existingPrice > 0 || $buyPrice > 0) {
                // Item has a price - preserve existing values, only fill missing ones
                // Use buy_price as reference for price if price is missing (est. value >= buy price)
                // Do NOT fill buy_price from price - buy_price of 0 means it wasn't entered
                $itemPrices[$index] = [
                    'price' => $existingPrice > 0 ? $existingPrice : $buyPrice,
                    'buy_price' => $buyPrice, // Preserve original buy_price, don't override with price
                ];
                $totalExistingPrices += max($existingPrice, $buyPrice);
            } else {
                // Mark for distribution
                $itemsWithoutPrice[$index] = $item;
                $itemPrices[$index] = ['price' => 0, 'buy_price' => 0];
            }
        }

        // If no items need price distribution, we're done
        if (empty($itemsWithoutPrice)) {
            return $itemPrices;
        }

        // Calculate remaining amount to distribute
        $remainingAmount = max(0, $finalOffer - $totalExistingPrices);

        if ($remainingAmount <= 0) {
            return $itemPrices;
        }

        // Calculate total weight of items without prices
        $totalWeight = 0;
        foreach ($itemsWithoutPrice as $item) {
            $totalWeight += (float) ($item->dwt ?? 0);
        }

        // Distribute based on weight if available, otherwise evenly
        if ($totalWeight > 0) {
            // Proportional distribution based on weight
            $distributedAmount = 0;
            $lastIndex = array_key_last($itemsWithoutPrice);

            foreach ($itemsWithoutPrice as $index => $item) {
                $itemWeight = (float) ($item->dwt ?? 0);
                if ($itemWeight > 0) {
                    if ($index === $lastIndex) {
                        // Last item gets the remainder to avoid rounding issues
                        $calculatedPrice = $remainingAmount - $distributedAmount;
                    } else {
                        $proportion = $itemWeight / $totalWeight;
                        $calculatedPrice = round($remainingAmount * $proportion, 2);
                        $distributedAmount += $calculatedPrice;
                    }
                } else {
                    $calculatedPrice = 0;
                }
                $itemPrices[$index] = [
                    'price' => $calculatedPrice,
                    'buy_price' => $calculatedPrice,
                ];
            }

            // Handle items with no weight - distribute any remainder evenly
            $itemsWithNoWeight = [];
            foreach ($itemsWithoutPrice as $index => $item) {
                if (((float) ($item->dwt ?? 0)) <= 0) {
                    $itemsWithNoWeight[$index] = $item;
                }
            }

            if (! empty($itemsWithNoWeight)) {
                $distributedSoFar = 0;
                foreach ($itemPrices as $prices) {
                    $distributedSoFar += $prices['buy_price'];
                }
                $remainder = $finalOffer - $distributedSoFar;
                if ($remainder > 0) {
                    $perItem = round($remainder / count($itemsWithNoWeight), 2);
                    foreach (array_keys($itemsWithNoWeight) as $index) {
                        $itemPrices[$index] = [
                            'price' => $perItem,
                            'buy_price' => $perItem,
                        ];
                    }
                }
            }
        } else {
            // No weights available, distribute evenly
            $perItem = round($remainingAmount / count($itemsWithoutPrice), 2);
            $distributedAmount = 0;
            $lastIndex = array_key_last($itemsWithoutPrice);

            foreach (array_keys($itemsWithoutPrice) as $index) {
                if ($index === $lastIndex) {
                    // Last item gets the remainder
                    $calculatedPrice = $remainingAmount - $distributedAmount;
                } else {
                    $calculatedPrice = $perItem;
                    $distributedAmount += $perItem;
                }
                $itemPrices[$index] = [
                    'price' => $calculatedPrice,
                    'buy_price' => $calculatedPrice,
                ];
            }
        }

        return $itemPrices;
    }
}
