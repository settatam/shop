<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductTemplateField;
use App\Models\ProductVariant;

class LabelDataService
{
    /**
     * Format a product variant's data for label printing.
     *
     * @return array<string, array<string, string|null>>
     */
    public static function formatProductVariantForLabel(ProductVariant $variant): array
    {
        $product = $variant->product;

        // Build options title
        $options = collect([
            $variant->option1_value ? ($variant->option1_name.': '.$variant->option1_value) : null,
            $variant->option2_value ? ($variant->option2_name.': '.$variant->option2_value) : null,
            $variant->option3_value ? ($variant->option3_name.': '.$variant->option3_value) : null,
        ])->filter()->implode(' / ');

        // Build the attribute line (same logic as frontend getLabelLine)
        $attributeLine = static::buildAttributeLine($variant);

        // Build individual attribute values (1-indexed)
        $individualAttributes = static::buildIndividualAttributes($variant);

        return [
            'product' => [
                'title' => $product->title,
                'weight' => $product->weight ? $product->weight.'g' : null,
                'upc' => $product->upc,
                'ean' => $product->ean,
                'jan' => $product->jan,
                'isbn' => $product->isbn,
                'mpn' => $product->mpn,
                'category' => $product->category?->name,
                'brand' => $product->brand?->name,
                'price_code' => $product->price_code,
                'barcode_label_text' => $product->barcode_label_text,
                'attribute_line' => $attributeLine,
                'attribute_1' => $individualAttributes[0] ?? null,
                'attribute_2' => $individualAttributes[1] ?? null,
                'attribute_3' => $individualAttributes[2] ?? null,
                'attribute_4' => $individualAttributes[3] ?? null,
                'attribute_5' => $individualAttributes[4] ?? null,
                'metal_type' => $product->metal_type,
                'metal_purity' => $product->metal_purity,
                'metal_weight_grams' => $product->metal_weight_grams,
                'jewelry_type' => $product->jewelry_type,
                'ring_size' => $product->ring_size,
                'chain_length_inches' => $product->chain_length_inches,
                'main_stone_type' => $product->main_stone_type,
                'total_carat_weight' => $product->total_carat_weight,
            ],
            'variant' => [
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'price' => $variant->price ? '$'.number_format((float) $variant->price, 2) : null,
                'cost' => $variant->cost ? '$'.number_format((float) $variant->cost, 2) : null,
                'quantity' => (string) ($variant->quantity ?? 0),
                'option1' => $variant->option1_value ? ($variant->option1_name.': '.$variant->option1_value) : null,
                'option2' => $variant->option2_value ? ($variant->option2_name.': '.$variant->option2_value) : null,
                'option3' => $variant->option3_value ? ($variant->option3_name.': '.$variant->option3_value) : null,
                'options_title' => $options ?: null,
            ],
        ];
    }

    /**
     * Build the attribute line for a variant label.
     * Uses barcode_label_text override if set, otherwise joins category barcode_attributes values.
     */
    public static function buildAttributeLine(ProductVariant $variant): ?string
    {
        $product = $variant->product;

        // Per-product override takes priority
        if ($product->barcode_label_text) {
            return $product->barcode_label_text;
        }

        // Get barcode attributes from category (with inheritance)
        $barcodeAttributes = $product->category?->getEffectiveBarcodeAttributes()
            ?? ['price_code', 'category', 'price'];

        // Load template field values for resolving custom attributes
        $templateFieldValues = static::getTemplateFieldValues($product);

        $values = [];
        foreach ($barcodeAttributes as $attr) {
            $value = static::resolveAttributeValue($attr, $product, $variant, $templateFieldValues);
            if ($value !== null && $value !== '') {
                $values[] = $value;
            }
        }

        return ! empty($values) ? implode(', ', $values) : null;
    }

    /**
     * Build individual attribute values from category barcode_attributes.
     * Returns a 0-indexed array of resolved values (skipping empty ones).
     *
     * @return array<int, string>
     */
    public static function buildIndividualAttributes(ProductVariant $variant): array
    {
        $product = $variant->product;

        // Per-product override: split by comma if set
        if ($product->barcode_label_text) {
            return array_map('trim', explode(',', $product->barcode_label_text));
        }

        $barcodeAttributes = $product->category?->getEffectiveBarcodeAttributes()
            ?? ['price_code', 'category', 'price'];

        $templateFieldValues = static::getTemplateFieldValues($product);

        $values = [];
        foreach ($barcodeAttributes as $attr) {
            $value = static::resolveAttributeValue($attr, $product, $variant, $templateFieldValues);
            if ($value !== null && $value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * Get the resolved template field values for a product.
     * Same logic as ProductController::printBarcode template field resolution.
     *
     * @return array<string, string|null>
     */
    protected static function getTemplateFieldValues(Product $product): array
    {
        $values = [];

        $product->loadMissing('attributeValues.field.options');
        $template = $product->getTemplate();

        if (! $template) {
            return $values;
        }

        foreach ($product->attributeValues as $attrValue) {
            $field = $attrValue->field;
            if (! $field) {
                continue;
            }

            $storedValue = $attrValue->value;
            $displayValue = $storedValue;

            // Map to label for select/radio/checkbox fields
            if ($storedValue && in_array($field->type, [
                ProductTemplateField::TYPE_SELECT,
                ProductTemplateField::TYPE_RADIO,
                ProductTemplateField::TYPE_CHECKBOX,
            ])) {
                $option = $field->options->firstWhere('value', $storedValue);
                $displayValue = $option?->label ?? $storedValue;
            }

            // For brand fields, get the brand name
            if ($field->type === ProductTemplateField::TYPE_BRAND && $storedValue) {
                $brand = Brand::find($storedValue);
                $displayValue = $brand?->name ?? $storedValue;
            }

            $values[$field->name] = $displayValue;
            if ($field->canonical_name) {
                $values[$field->canonical_name] = $displayValue;
            }
        }

        return $values;
    }

    /**
     * Resolve a single barcode attribute value from product/variant data.
     *
     * @param  array<string, string|null>  $templateFieldValues
     */
    protected static function resolveAttributeValue(string $attr, Product $product, ProductVariant $variant, array $templateFieldValues = []): ?string
    {
        // Check well-known attributes first
        $value = match ($attr) {
            'price_code' => $product->price_code,
            'category' => $product->category?->name,
            'price' => $variant->price ? '$'.number_format((float) $variant->price, 2) : null,
            'sku' => $variant->sku,
            'barcode' => $variant->barcode,
            'brand' => $product->brand?->name,
            default => null,
        };

        if ($value !== null) {
            return $value;
        }

        // Fall back to template field values (try original name and snake_case)
        $snakeAttr = strtolower(str_replace(' ', '_', $attr));

        return $templateFieldValues[$attr] ?? $templateFieldValues[$snakeAttr] ?? null;
    }
}
