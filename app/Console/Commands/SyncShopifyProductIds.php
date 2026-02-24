<?php

namespace App\Console\Commands;

use App\Enums\Platform;
use App\Models\OrderItem;
use App\Models\PlatformListing;
use App\Models\PlatformListingVariant;
use App\Models\PlatformOrder;
use App\Models\ProductVariant;
use App\Models\StoreMarketplace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncShopifyProductIds extends Command
{
    protected $signature = 'shopify:sync-product-ids
                            {--marketplace= : Specific StoreMarketplace ID to sync}
                            {--backfill-order-items : Backfill external_item_id on existing order items}
                            {--dry-run : Show what would be updated without making changes}';

    protected $description = 'Sync external product/variant IDs from Shopify to platform_listings and optionally backfill order item external IDs';

    protected string $apiVersion = '2024-01';

    protected int $matched = 0;

    protected int $missed = 0;

    protected int $created = 0;

    protected int $updated = 0;

    public function handle(): int
    {
        $marketplaces = $this->getMarketplaces();

        if ($marketplaces->isEmpty()) {
            $this->warn('No active Shopify marketplaces found.');

            return 1;
        }

        foreach ($marketplaces as $marketplace) {
            $this->syncMarketplace($marketplace);
        }

        $this->newLine();
        $this->info("Sync complete: {$this->matched} matched, {$this->missed} missed, {$this->created} created, {$this->updated} updated.");

        if ($this->option('backfill-order-items')) {
            $this->backfillOrderItems();
        }

        return 0;
    }

    /**
     * @return \Illuminate\Support\Collection<int, StoreMarketplace>
     */
    protected function getMarketplaces(): \Illuminate\Support\Collection
    {
        $query = StoreMarketplace::where('platform', Platform::Shopify)
            ->whereNotNull('shop_domain')
            ->whereNotNull('access_token');

        if ($id = $this->option('marketplace')) {
            $query->where('id', $id);
        }

        return $query->get();
    }

    protected function syncMarketplace(StoreMarketplace $marketplace): void
    {
        $this->info("Syncing marketplace #{$marketplace->id}: {$marketplace->name} ({$marketplace->shop_domain})");

        $page = null;

        do {
            $response = $this->fetchProducts($marketplace, $page);

            if ($response === null) {
                $this->error('  Failed to fetch products.');
                break;
            }

            $products = $response->json('products', []);

            foreach ($products as $shopifyProduct) {
                $this->processShopifyProduct($shopifyProduct, $marketplace);
            }

            $page = $this->extractNextPageInfo($response);
        } while ($page);
    }

    protected function fetchProducts(StoreMarketplace $marketplace, ?string $pageInfo = null): ?\Illuminate\Http\Client\Response
    {
        $url = "https://{$marketplace->shop_domain}/admin/api/{$this->apiVersion}/products.json";

        $params = ['limit' => 250];
        if ($pageInfo) {
            $params = ['limit' => 250, 'page_info' => $pageInfo];
        }

        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $marketplace->access_token,
                'Content-Type' => 'application/json',
            ])->get($url, $params);

            if ($response->failed()) {
                $this->error("  Shopify API error: {$response->status()} - {$response->body()}");

                return null;
            }

            return $response;
        } catch (\Exception $e) {
            $this->error("  Request failed: {$e->getMessage()}");

            return null;
        }
    }

    protected function extractNextPageInfo(\Illuminate\Http\Client\Response $response): ?string
    {
        $linkHeader = $response->header('Link');

        if (! $linkHeader) {
            return null;
        }

        // Parse Link header for rel="next"
        if (preg_match('/<[^>]*page_info=([^&>]+)[^>]*>;\s*rel="next"/', $linkHeader, $matches)) {
            return $matches[1];
        }

        return null;
    }

    protected function processShopifyProduct(array $shopifyProduct, StoreMarketplace $marketplace): void
    {
        $shopifyProductId = (string) $shopifyProduct['id'];
        $variants = $shopifyProduct['variants'] ?? [];

        foreach ($variants as $shopifyVariant) {
            $shopifyVariantId = (string) $shopifyVariant['id'];
            $inventoryItemId = (string) ($shopifyVariant['inventory_item_id'] ?? '');
            $sku = $shopifyVariant['sku'] ?? null;

            if (empty($sku)) {
                $this->missed++;

                continue;
            }

            $variant = ProductVariant::with('product')
                ->whereHas('product', function ($query) use ($marketplace) {
                    $query->where('store_id', $marketplace->store_id);
                })
                ->where('sku', $sku)
                ->first();

            if (! $variant) {
                $this->missed++;
                $this->line("  Miss: SKU '{$sku}' not found locally (Shopify product {$shopifyProductId})");

                continue;
            }

            $this->matched++;

            if ($this->option('dry-run')) {
                $this->line("  [DRY RUN] Would update listing for product #{$variant->product_id}, variant #{$variant->id}");

                continue;
            }

            // Find or create the PlatformListing (product-level)
            $listing = PlatformListing::where('store_marketplace_id', $marketplace->id)
                ->where('product_id', $variant->product_id)
                ->first();

            if ($listing) {
                $listing->update([
                    'external_listing_id' => $shopifyProductId,
                ]);
            } else {
                $listing = PlatformListing::create([
                    'store_marketplace_id' => $marketplace->id,
                    'product_id' => $variant->product_id,
                    'external_listing_id' => $shopifyProductId,
                    'status' => PlatformListing::STATUS_LISTED,
                    'last_synced_at' => now(),
                ]);
                $this->created++;
            }

            // Find or create the PlatformListingVariant (variant-level)
            $listingVariant = PlatformListingVariant::where('platform_listing_id', $listing->id)
                ->where('product_variant_id', $variant->id)
                ->first();

            if ($listingVariant) {
                $listingVariant->update([
                    'external_variant_id' => $shopifyVariantId,
                    'external_inventory_item_id' => $inventoryItemId,
                    'platform_data' => $shopifyVariant,
                ]);
                $this->updated++;
            } else {
                PlatformListingVariant::create([
                    'platform_listing_id' => $listing->id,
                    'product_variant_id' => $variant->id,
                    'external_variant_id' => $shopifyVariantId,
                    'external_inventory_item_id' => $inventoryItemId,
                    'price' => $variant->price,
                    'quantity' => $variant->quantity,
                    'sku' => $variant->sku,
                    'platform_data' => $shopifyVariant,
                ]);
                $this->created++;
            }

            $this->line("  Synced: SKU '{$sku}' -> listing #{$listing->id}, variant {$shopifyVariantId}, inventory {$inventoryItemId}");
        }
    }

    protected function backfillOrderItems(): void
    {
        $this->newLine();
        $this->info('Backfilling external_item_id on existing order items...');

        $backfilled = 0;
        $skipped = 0;

        PlatformOrder::whereNotNull('order_id')
            ->whereNotNull('line_items')
            ->chunkById(100, function ($platformOrders) use (&$backfilled, &$skipped) {
                foreach ($platformOrders as $platformOrder) {
                    foreach ($platformOrder->line_items as $lineItem) {
                        $externalId = (string) ($lineItem['external_id'] ?? $lineItem['id'] ?? '');

                        if (empty($externalId)) {
                            continue;
                        }

                        $sku = $lineItem['sku'] ?? null;
                        $title = $lineItem['title'] ?? null;

                        $query = OrderItem::where('order_id', $platformOrder->order_id)
                            ->whereNull('external_item_id');

                        if ($sku) {
                            $match = (clone $query)->where('sku', $sku)->first();
                        }

                        if (empty($match) && $title) {
                            $match = (clone $query)->where('title', $title)->first();
                        }

                        if (empty($match)) {
                            $skipped++;

                            continue;
                        }

                        if (! $this->option('dry-run')) {
                            $match->update(['external_item_id' => $externalId]);
                        }

                        $backfilled++;
                    }
                }
            });

        $dryLabel = $this->option('dry-run') ? ' [DRY RUN]' : '';
        $this->info("Backfill complete{$dryLabel}: {$backfilled} items updated, {$skipped} skipped.");
    }
}
