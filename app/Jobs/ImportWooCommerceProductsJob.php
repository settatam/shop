<?php

namespace App\Jobs;

use App\Models\PlatformListing;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StoreMarketplace;
use App\Services\Platforms\WooCommerce\WooCommerceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ImportWooCommerceProductsJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public StoreMarketplace $marketplace) {}

    public function handle(WooCommerceService $wooService): void
    {
        $storeId = $this->marketplace->store_id;

        Log::info('Starting WooCommerce product import', [
            'marketplace_id' => $this->marketplace->id,
            'store_id' => $storeId,
        ]);

        $wooProducts = $wooService->pullProducts($this->marketplace);

        foreach ($wooProducts as $wooProduct) {
            $this->importProduct($wooProduct, $storeId);
        }

        Log::info('WooCommerce product import completed', [
            'marketplace_id' => $this->marketplace->id,
            'imported_count' => $wooProducts->count(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $wooProduct
     */
    protected function importProduct(array $wooProduct, int $storeId): void
    {
        $existingListing = PlatformListing::where('store_marketplace_id', $this->marketplace->id)
            ->where('external_listing_id', $wooProduct['external_id'])
            ->first();

        if ($existingListing) {
            return;
        }

        $product = Product::create([
            'store_id' => $storeId,
            'title' => $wooProduct['title'],
            'description' => $wooProduct['description'] ?? null,
            'handle' => $wooProduct['sku'] ?? null,
            'status' => Product::STATUS_ACTIVE,
            'is_published' => $wooProduct['status'] === 'publish',
            'has_variants' => count($wooProduct['variants'] ?? []) > 1,
            'quantity' => $wooProduct['quantity'] ?? 0,
        ]);

        if (! empty($wooProduct['variants'])) {
            foreach ($wooProduct['variants'] as $variantData) {
                ProductVariant::create([
                    'product_id' => $product->id,
                    'sku' => $variantData['sku'] ?? null,
                    'price' => $variantData['price'] ?? 0,
                    'quantity' => $variantData['quantity'] ?? 0,
                    'barcode' => $variantData['barcode'] ?? null,
                ]);
            }
        } else {
            ProductVariant::create([
                'product_id' => $product->id,
                'sku' => $wooProduct['sku'] ?? null,
                'price' => $wooProduct['price'] ?? $wooProduct['regular_price'] ?? 0,
                'quantity' => $wooProduct['quantity'] ?? 0,
            ]);
        }

        $siteUrl = $this->marketplace->credentials['site_url'] ?? $this->marketplace->shop_domain;

        PlatformListing::create([
            'store_marketplace_id' => $this->marketplace->id,
            'product_id' => $product->id,
            'external_listing_id' => $wooProduct['external_id'],
            'status' => PlatformListing::STATUS_LISTED,
            'listing_url' => $wooProduct['permalink'] ?? null,
            'last_synced_at' => now(),
            'published_at' => $wooProduct['status'] === 'publish' ? now() : null,
        ]);
    }
}
