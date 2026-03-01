<?php

namespace App\Jobs;

use App\Models\PlatformListing;
use App\Models\PlatformListingVariant;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StoreMarketplace;
use App\Services\Platforms\BigCommerce\BigCommerceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ImportBigCommerceProductsJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public StoreMarketplace $marketplace) {}

    public function handle(BigCommerceService $bcService): void
    {
        $storeId = $this->marketplace->store_id;

        Log::info('Starting BigCommerce product import', [
            'marketplace_id' => $this->marketplace->id,
            'store_id' => $storeId,
        ]);

        $bcProducts = $bcService->pullProducts($this->marketplace);

        foreach ($bcProducts as $bcProduct) {
            $this->importProduct($bcProduct, $storeId);
        }

        Log::info('BigCommerce product import completed', [
            'marketplace_id' => $this->marketplace->id,
            'imported_count' => $bcProducts->count(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $bcProduct
     */
    protected function importProduct(array $bcProduct, int $storeId): void
    {
        $existingListing = PlatformListing::where('store_marketplace_id', $this->marketplace->id)
            ->where('external_listing_id', $bcProduct['external_id'])
            ->first();

        if ($existingListing) {
            return;
        }

        $product = Product::create([
            'store_id' => $storeId,
            'title' => $bcProduct['title'],
            'description' => $bcProduct['description'] ?? null,
            'handle' => $bcProduct['sku'] ?? null,
            'status' => Product::STATUS_ACTIVE,
            'is_published' => $bcProduct['status'] === 'active',
            'has_variants' => count($bcProduct['variants'] ?? []) > 1,
            'quantity' => $bcProduct['quantity'] ?? 0,
        ]);

        $createdVariants = [];

        if (! empty($bcProduct['variants'])) {
            foreach ($bcProduct['variants'] as $variantData) {
                $productVariant = ProductVariant::create([
                    'product_id' => $product->id,
                    'sku' => $variantData['sku'] ?? null,
                    'price' => $variantData['price'] ?? 0,
                    'quantity' => $variantData['quantity'] ?? 0,
                ]);

                $createdVariants[] = [
                    'product_variant' => $productVariant,
                    'variant_data' => $variantData,
                ];
            }
        } else {
            $productVariant = ProductVariant::create([
                'product_id' => $product->id,
                'sku' => $bcProduct['sku'] ?? null,
                'price' => $bcProduct['price'] ?? $bcProduct['regular_price'] ?? 0,
                'quantity' => $bcProduct['quantity'] ?? 0,
            ]);

            $createdVariants[] = [
                'product_variant' => $productVariant,
                'variant_data' => [
                    'external_id' => $bcProduct['external_id'],
                    'sku' => $bcProduct['sku'] ?? null,
                    'price' => $bcProduct['price'] ?? $bcProduct['regular_price'] ?? 0,
                    'quantity' => $bcProduct['quantity'] ?? 0,
                ],
            ];
        }

        $shopDomain = $this->marketplace->shop_domain ?? '';

        $listing = PlatformListing::create([
            'store_marketplace_id' => $this->marketplace->id,
            'product_id' => $product->id,
            'external_listing_id' => $bcProduct['external_id'],
            'status' => PlatformListing::STATUS_LISTED,
            'listing_url' => $shopDomain ? "https://{$shopDomain}/products" : null,
            'last_synced_at' => now(),
            'published_at' => $bcProduct['status'] === 'active' ? now() : null,
        ]);

        foreach ($createdVariants as $entry) {
            PlatformListingVariant::create([
                'platform_listing_id' => $listing->id,
                'product_variant_id' => $entry['product_variant']->id,
                'external_variant_id' => (string) ($entry['variant_data']['external_id'] ?? ''),
                'price' => $entry['variant_data']['price'] ?? null,
                'quantity' => $entry['variant_data']['quantity'] ?? 0,
                'sku' => $entry['variant_data']['sku'] ?? null,
            ]);
        }
    }
}
