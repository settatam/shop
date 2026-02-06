<?php

namespace App\Services\Chat\Tools;

use App\Models\Product;

class ProductLookupTool implements ChatToolInterface
{
    public function name(): string
    {
        return 'lookup_product';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Look up a product by SKU, barcode, or ID. Use this when users ask about a specific product, price check, or want to find an item.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'sku' => [
                        'type' => 'string',
                        'description' => 'The product SKU to search for',
                    ],
                    'barcode' => [
                        'type' => 'string',
                        'description' => 'The product barcode to search for',
                    ],
                    'product_id' => [
                        'type' => 'integer',
                        'description' => 'The product ID to search for',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    public function execute(array $params, int $storeId): array
    {
        $sku = $params['sku'] ?? null;
        $barcode = $params['barcode'] ?? null;
        $productId = $params['product_id'] ?? null;

        $product = null;

        if ($productId) {
            $product = Product::where('store_id', $storeId)
                ->where('id', $productId)
                ->first();
        } elseif ($sku) {
            $product = Product::where('store_id', $storeId)
                ->where('sku', $sku)
                ->first();
        } elseif ($barcode) {
            $product = Product::where('store_id', $storeId)
                ->where('barcode', $barcode)
                ->first();
        }

        if (! $product) {
            return [
                'found' => false,
                'message' => 'Product not found',
                'searched' => array_filter([
                    'sku' => $sku,
                    'barcode' => $barcode,
                    'product_id' => $productId,
                ]),
            ];
        }

        return [
            'found' => true,
            'product' => [
                'id' => $product->id,
                'sku' => $product->sku,
                'title' => $product->title,
                'price' => round($product->price, 2),
                'price_formatted' => '$'.number_format($product->price, 2),
                'cost' => $product->cost ? round($product->cost, 2) : null,
                'cost_formatted' => $product->cost ? '$'.number_format($product->cost, 2) : null,
                'quantity' => $product->quantity,
                'category' => $product->category?->name,
                'condition' => $product->condition,
                'location' => $product->location,
                'status' => $product->status,
                'days_in_inventory' => $product->created_at->diffInDays(now()),
                'barcode' => $product->barcode,
            ],
        ];
    }
}
