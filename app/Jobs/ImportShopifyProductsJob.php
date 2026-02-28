<?php

namespace App\Jobs;

use App\Models\PlatformListing;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StoreMarketplace;
use App\Services\Platforms\Shopify\ShopifyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ImportShopifyProductsJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public StoreMarketplace $marketplace) {}

    public function handle(ShopifyService $shopifyService): void
    {
        $storeId = $this->marketplace->store_id;

        Log::info('Starting Shopify product import', [
            'marketplace_id' => $this->marketplace->id,
            'store_id' => $storeId,
        ]);

        $shopifyProducts = $shopifyService->pullProducts($this->marketplace);

        foreach ($shopifyProducts as $shopifyProduct) {
            $this->importProduct($shopifyProduct, $storeId);
        }

        Log::info('Shopify product import completed', [
            'marketplace_id' => $this->marketplace->id,
            'imported_count' => $shopifyProducts->count(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $shopifyProduct
     */
    protected function importProduct(array $shopifyProduct, int $storeId): void
    {
        // Check if product already linked via PlatformListing
        $existingListing = PlatformListing::where('store_marketplace_id', $this->marketplace->id)
            ->where('external_listing_id', $shopifyProduct['external_id'])
            ->first();

        if ($existingListing) {
            return;
        }

        $product = Product::create([
            'store_id' => $storeId,
            'title' => $shopifyProduct['title'],
            'description' => $shopifyProduct['description'] ?? null,
            'handle' => $shopifyProduct['handle'] ?? null,
            'status' => Product::STATUS_ACTIVE,
            'is_published' => true,
            'has_variants' => count($shopifyProduct['variants'] ?? []) > 1,
            'quantity' => collect($shopifyProduct['variants'] ?? [])->sum('quantity'),
        ]);

        foreach ($shopifyProduct['variants'] ?? [] as $variantData) {
            ProductVariant::create([
                'product_id' => $product->id,
                'sku' => $variantData['sku'] ?? null,
                'price' => $variantData['price'] ?? 0,
                'quantity' => $variantData['quantity'] ?? 0,
                'barcode' => $variantData['barcode'] ?? null,
            ]);
        }

        PlatformListing::create([
            'store_marketplace_id' => $this->marketplace->id,
            'product_id' => $product->id,
            'external_listing_id' => $shopifyProduct['external_id'],
            'status' => PlatformListing::STATUS_LISTED,
            'listing_url' => $shopifyProduct['handle']
                ? "https://{$this->marketplace->shop_domain}/products/{$shopifyProduct['handle']}"
                : null,
            'last_synced_at' => now(),
            'published_at' => now(),
        ]);
    }
}
