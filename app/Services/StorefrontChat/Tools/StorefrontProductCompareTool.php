<?php

namespace App\Services\StorefrontChat\Tools;

use App\Models\Product;
use App\Services\Chat\Tools\ChatToolInterface;

class StorefrontProductCompareTool implements ChatToolInterface
{
    public function name(): string
    {
        return 'compare_products';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Compare multiple products side by side. Use when a customer wants to compare features, prices, or attributes of 2-4 products.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'product_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'Array of 2-4 product IDs to compare',
                        'minItems' => 2,
                        'maxItems' => 4,
                    ],
                ],
                'required' => ['product_ids'],
            ],
        ];
    }

    public function execute(array $params, int $storeId): array
    {
        $productIds = $params['product_ids'] ?? [];

        if (count($productIds) < 2) {
            return ['error' => 'At least 2 product IDs are required for comparison'];
        }

        if (count($productIds) > 4) {
            return ['error' => 'Maximum 4 products can be compared at once'];
        }

        $products = Product::where('store_id', $storeId)
            ->where('status', Product::STATUS_ACTIVE)
            ->whereIn('id', $productIds)
            ->with(['brand', 'category', 'variants', 'attributeValues.field.options'])
            ->get();

        if ($products->count() < 2) {
            return ['found' => false, 'message' => 'Could not find enough products to compare'];
        }

        $comparison = $products->map(function (Product $product) {
            $defaultVariant = $product->variants->first();

            $attributes = [];
            foreach ($product->attributeValues ?? collect() as $av) {
                if ($av->value && $av->field) {
                    $attributes[$av->field->label] = $av->resolveDisplayValue() ?? $av->value;
                }
            }

            return [
                'id' => $product->id,
                'title' => $product->title,
                'price' => $defaultVariant?->price ? round($defaultVariant->price, 2) : null,
                'price_formatted' => $defaultVariant?->price ? '$'.number_format($defaultVariant->price, 2) : null,
                'brand' => $product->brand?->name,
                'category' => $product->category?->name,
                'condition' => $product->condition,
                'available' => ($product->total_quantity ?? 0) > 0,
                'attributes' => $attributes,
            ];
        })->toArray();

        // Collect all unique attribute names across products
        $allAttributes = [];
        foreach ($comparison as $product) {
            foreach ($product['attributes'] as $key => $value) {
                if (! in_array($key, $allAttributes)) {
                    $allAttributes[] = $key;
                }
            }
        }

        return [
            'found' => true,
            'products' => $comparison,
            'attribute_names' => $allAttributes,
        ];
    }
}
