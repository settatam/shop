<?php

namespace App\Services\StorefrontChat\Tools;

use App\Models\PlatformListing;
use App\Models\Product;
use App\Services\Chat\Tools\ChatToolInterface;

class StorefrontAddToCartTool implements ChatToolInterface
{
    public function name(): string
    {
        return 'add_to_cart';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Generate an add-to-cart link for a product. Use when a customer wants to buy or add a product to their cart.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'product_id' => [
                        'type' => 'integer',
                        'description' => 'The product ID to add to cart',
                    ],
                    'variant_id' => [
                        'type' => 'integer',
                        'description' => 'Specific variant ID (optional, uses default variant if omitted)',
                    ],
                    'quantity' => [
                        'type' => 'integer',
                        'description' => 'Quantity to add (default 1)',
                    ],
                ],
                'required' => ['product_id'],
            ],
        ];
    }

    /**
     * @param  array{product_id: int, variant_id?: int, quantity?: int}  $params
     * @return array{success: bool, shopify_variant_id?: string, cart_url?: string, product_title?: string, error?: string}
     */
    public function execute(array $params, int $storeId): array
    {
        $productId = $params['product_id'] ?? null;
        $variantId = $params['variant_id'] ?? null;
        $quantity = max(1, $params['quantity'] ?? 1);

        if (! $productId) {
            return ['error' => 'Product ID is required.'];
        }

        $product = Product::where('store_id', $storeId)
            ->where('id', $productId)
            ->where('status', Product::STATUS_ACTIVE)
            ->with(['variants', 'platformListings.listingVariants'])
            ->first();

        if (! $product) {
            return ['error' => 'Product not found or unavailable.'];
        }

        // Find the specific variant or use the first/default variant
        $productVariant = $variantId
            ? $product->variants->firstWhere('id', $variantId)
            : $product->variants->first();

        if (! $productVariant) {
            return ['error' => 'Product variant not found.'];
        }

        // Check availability
        if (($productVariant->quantity ?? 0) <= 0) {
            return [
                'success' => false,
                'product_title' => $product->title,
                'error' => 'This product is currently out of stock.',
            ];
        }

        // Find the Shopify variant ID across all listed platform listings
        $shopifyVariantId = null;
        $listedListings = $product->platformListings
            ->where('status', PlatformListing::STATUS_LISTED);

        foreach ($listedListings as $listing) {
            $listingVariant = $listing->listingVariants
                ->firstWhere('product_variant_id', $productVariant->id);

            if ($listingVariant?->external_variant_id) {
                $shopifyVariantId = $listingVariant->external_variant_id;
                break;
            }
        }

        if (! $shopifyVariantId) {
            return [
                'success' => false,
                'product_title' => $product->title,
                'error' => 'This product is not currently available for online purchase.',
            ];
        }

        return [
            'success' => true,
            'shopify_variant_id' => $shopifyVariantId,
            'product_title' => $product->title,
            'quantity' => $quantity,
            'cart_url' => "/cart/add?id={$shopifyVariantId}&quantity={$quantity}",
        ];
    }
}
