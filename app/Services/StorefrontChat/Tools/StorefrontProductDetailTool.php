<?php

namespace App\Services\StorefrontChat\Tools;

use App\Models\AssistantDataGap;
use App\Models\PlatformListing;
use App\Models\Product;
use App\Services\Chat\Tools\ChatToolInterface;

class StorefrontProductDetailTool implements ChatToolInterface
{
    /**
     * Key jewelry fields to check for data gap detection.
     */
    protected const JEWELRY_FIELDS = [
        'weight',
        'hallmark',
        'certification',
        'gemstone',
        'material',
        'metal_type',
        'karat',
        'cut',
        'clarity',
        'color',
        'carat',
    ];

    public function name(): string
    {
        return 'get_product_details';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Get detailed information about a specific product including materials, attributes, pricing, images, and availability. Use when a customer wants to know more about a particular piece.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'product_id' => [
                        'type' => 'integer',
                        'description' => 'The product ID to get details for',
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
            return ['found' => false, 'error' => 'Product ID is required'];
        }

        $product = Product::where('store_id', $storeId)
            ->where('status', Product::STATUS_ACTIVE)
            ->where('id', $productId)
            ->with([
                'brand',
                'category',
                'variants',
                'attributeValues.field.options',
            ])
            ->first();

        if (! $product) {
            return ['found' => false, 'message' => 'Product not found or unavailable'];
        }

        // Build attributes with resolved display values
        $attributes = [];
        $presentFields = [];
        foreach ($product->attributeValues ?? collect() as $av) {
            if ($av->value && $av->field) {
                $displayValue = $av->resolveDisplayValue() ?? $av->value;
                $attributes[$av->field->label] = $displayValue;
                $presentFields[] = strtolower($av->field->name);
                $presentFields[] = strtolower($av->field->label);
            }
        }

        // Detect missing jewelry fields and log data gaps
        $this->detectDataGaps($storeId, $product->id, $presentFields, $product);

        // Build variants (no cost data)
        $variants = $product->variants->map(function ($variant) {
            $options = [];
            for ($i = 1; $i <= 3; $i++) {
                $name = $variant->{"option{$i}_name"};
                $value = $variant->{"option{$i}_value"};
                if ($name && $value) {
                    $options[] = ['name' => $name, 'value' => $value];
                }
            }

            return [
                'sku' => $variant->sku,
                'price' => $variant->price ? round($variant->price, 2) : null,
                'price_formatted' => $variant->price ? '$'.number_format($variant->price, 2) : null,
                'options' => $options,
                'available' => ($variant->quantity ?? 0) > 0,
            ];
        })->toArray();

        // Get public images only
        $images = [];
        if (method_exists($product, 'publicImages')) {
            $images = $product->publicImages()
                ->orderBy('sort_order')
                ->limit(5)
                ->get()
                ->map(fn ($img) => [
                    'url' => $img->url,
                    'thumbnail_url' => $img->thumbnail_url,
                    'alt_text' => $img->alt_text,
                ])
                ->toArray();
        }

        $listing = $product->platformListings
            ->where('status', PlatformListing::STATUS_LISTED)
            ->first();

        return [
            'found' => true,
            'product' => [
                'id' => $product->id,
                'title' => $product->title,
                'description' => $product->description ? strip_tags($product->description) : null,
                'brand' => $product->brand?->name,
                'category' => $product->category?->name,
                'condition' => $product->condition,
                'available' => ($product->total_quantity ?? 0) > 0,
                'attributes' => $attributes,
                'variants' => $variants,
                'images' => $images,
                'listing_url' => $listing?->listing_url,
            ],
        ];
    }

    /**
     * Detect and log missing jewelry-related fields as data gaps.
     *
     * @param  array<int, string>  $presentFields
     */
    protected function detectDataGaps(int $storeId, int $productId, array $presentFields, Product $product): void
    {
        $missingFields = [];

        foreach (self::JEWELRY_FIELDS as $field) {
            $found = false;
            foreach ($presentFields as $present) {
                if (str_contains($present, $field)) {
                    $found = true;
                    break;
                }
            }

            // Also check direct product fields
            if (! $found && $field === 'weight' && $product->weight) {
                $found = true;
            }

            if (! $found) {
                $missingFields[] = $field;
            }
        }

        // Log top missing fields (limit to avoid excessive writes)
        foreach (array_slice($missingFields, 0, 3) as $fieldName) {
            AssistantDataGap::recordGap($storeId, $productId, $fieldName);
        }
    }
}
