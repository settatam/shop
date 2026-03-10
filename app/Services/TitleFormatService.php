<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Log;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;

class TitleFormatService
{
    /**
     * Resolve the title format for a product using its category's Twig template.
     */
    public function resolve(Product $product): ?string
    {
        $format = $product->category?->getEffectiveTitleFormat();

        if (! $format) {
            return null;
        }

        try {
            $context = $this->buildContext($product);

            $loader = new ArrayLoader(['title' => $format]);
            $twig = new Environment($loader, [
                'autoescape' => false,
            ]);

            $twig->addFilter(new TwigFilter('number_format', function ($value, int $decimals = 0, string $decPoint = '.', string $thousandsSep = ',') {
                if ($value === null || $value === '') {
                    return '';
                }

                return number_format((float) $value, $decimals, $decPoint, $thousandsSep);
            }));

            $result = $twig->render('title', $context);

            return preg_replace('/\s+/', ' ', trim($result));
        } catch (\Exception $e) {
            Log::warning('Failed to resolve title format', [
                'product_id' => $product->id,
                'format' => $format,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Build the Twig context from product attributes and built-in fields.
     *
     * @return array<string, string|null>
     */
    protected function buildContext(Product $product): array
    {
        $context = [];

        // Template attribute values keyed by field name and canonical_name
        $product->loadMissing('attributeValues.field.options');

        foreach ($product->attributeValues as $attributeValue) {
            $field = $attributeValue->field;
            if (! $field) {
                continue;
            }

            $displayValue = $attributeValue->resolveDisplayValue() ?? '';

            if ($field->name) {
                $context[$field->name] = $displayValue;
            }

            if ($field->canonical_name && $field->canonical_name !== $field->name) {
                $context[$field->canonical_name] = $displayValue;
            }
        }

        // Built-in fields
        $context['price_code'] = $product->price_code ?? '';
        $context['category'] = $product->category?->name ?? '';
        $context['price'] = $product->variants?->first()?->price ?? '';
        $context['brand'] = $product->brand?->name ?? '';
        $context['sku'] = $product->variants?->first()?->sku ?? '';

        return $context;
    }
}
