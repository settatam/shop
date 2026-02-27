<?php

namespace App\Services\StorefrontChat\Tools;

use App\Models\Product;
use App\Services\Chat\Tools\ChatToolInterface;

class StorefrontAvailabilityTool implements ChatToolInterface
{
    public function name(): string
    {
        return 'check_availability';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Check if a product is currently available (in stock). Use when a customer asks about availability or wants to know if something is in stock.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'product_id' => [
                        'type' => 'integer',
                        'description' => 'The product ID to check availability for',
                    ],
                ],
                'required' => ['product_id'],
            ],
        ];
    }

    public function execute(array $params, int $storeId): array
    {
        $productId = $params['product_id'] ?? null;

        if (! $productId) {
            return ['error' => 'Product ID is required'];
        }

        $product = Product::where('store_id', $storeId)
            ->where('status', Product::STATUS_ACTIVE)
            ->where('id', $productId)
            ->with('variants')
            ->first();

        if (! $product) {
            return ['found' => false, 'message' => 'Product not found'];
        }

        $totalAvailable = ($product->total_quantity ?? 0) > 0;

        $variants = $product->variants->map(function ($variant) {
            $options = $variant->options_title ?? $variant->sku ?? 'Default';

            return [
                'name' => $options,
                'available' => ($variant->quantity ?? 0) > 0,
            ];
        })->toArray();

        return [
            'found' => true,
            'product_title' => $product->title,
            'available' => $totalAvailable,
            'variants' => $variants,
        ];
    }
}
