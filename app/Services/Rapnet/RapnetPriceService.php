<?php

namespace App\Services\Rapnet;

use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductTemplate;
use App\Models\RapnetPrice;
use App\Models\Store;
use App\Models\StoreIntegration;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class RapnetPriceService
{
    /**
     * Get the Rapnet integration for a store.
     */
    public function getIntegration(Store $store): ?StoreIntegration
    {
        return StoreIntegration::where('store_id', $store->id)
            ->where('provider', StoreIntegration::PROVIDER_RAPNET)
            ->where('status', StoreIntegration::STATUS_ACTIVE)
            ->first();
    }

    /**
     * Sync all prices from Rapnet API and store them locally.
     *
     * @return array{round: int, pear: int}
     */
    public function syncPricesFromApi(StoreIntegration $integration): array
    {
        $apiService = new RapnetApiService($integration);
        $counts = ['round' => 0, 'pear' => 0];

        // Fetch and store Round prices
        $roundPrices = $apiService->getRoundPriceList();
        foreach ($roundPrices as $data) {
            $this->upsertPrice($data);
            $counts['round']++;
        }

        // Fetch and store Pear/Fancy prices
        $pearPrices = $apiService->getPearPriceList();
        foreach ($pearPrices as $data) {
            $this->upsertPrice($data);
            $counts['pear']++;
        }

        return $counts;
    }

    /**
     * Upsert a single price record.
     */
    protected function upsertPrice(array $data): RapnetPrice
    {
        return RapnetPrice::updateOrCreate(
            [
                'shape' => $data['shape'],
                'color' => strtoupper($data['color']),
                'clarity' => strtoupper($data['clarity']),
                'low_size' => $data['low_size'],
                'high_size' => $data['high_size'],
            ],
            [
                'carat_price' => $data['caratprice'],
                'price_date' => Carbon::createFromFormat('Y-m-d', $data['date']),
            ]
        );
    }

    /**
     * Look up the current rap price for a diamond.
     *
     * @return array{price: float, date: Carbon}|null
     */
    public function lookupPrice(string $shape, string $color, string $clarity, float $weight): ?array
    {
        $rapPrice = RapnetPrice::findPrice($shape, $color, $clarity, $weight);

        if (! $rapPrice) {
            return null;
        }

        return [
            'price' => (float) $rapPrice->carat_price,
            'date' => $rapPrice->price_date,
        ];
    }

    /**
     * Set rap price attributes on a product.
     */
    public function setProductRapPrice(
        Product $product,
        string $shape,
        string $color,
        string $clarity,
        float $weight,
        bool $isInitial = true,
    ): bool {
        $priceData = $this->lookupPrice($shape, $color, $clarity, $weight);

        if (! $priceData) {
            Log::info('No rap price found', [
                'product_id' => $product->id,
                'shape' => $shape,
                'color' => $color,
                'clarity' => $clarity,
                'weight' => $weight,
            ]);

            return false;
        }

        $template = $product->getTemplate();
        if (! $template) {
            return false;
        }

        $fields = $template->fields;

        // Set rap_price and date_of_rap_price (initial values, set when diamond is added)
        if ($isInitial) {
            $this->setAttributeByName($product, $fields, 'rap_price', (string) $priceData['price']);
            $this->setAttributeByName($product, $fields, 'date_of_rap_price', $priceData['date']->format('Y-m-d'));
        }

        // Always update current_rap_price
        $this->setAttributeByName($product, $fields, 'current_rap_price', (string) $priceData['price']);

        return true;
    }

    /**
     * Update current rap prices for all diamonds in a store.
     *
     * @return array{updated: int, skipped: int, errors: int}
     */
    public function updateStoreProductPrices(Store $store): array
    {
        $counts = ['updated' => 0, 'skipped' => 0, 'errors' => 0];

        // Get all products with Loose Stones template
        $looseStoneTemplate = ProductTemplate::where('store_id', $store->id)
            ->where('name', 'Loose Stones')
            ->first();

        if (! $looseStoneTemplate) {
            return $counts;
        }

        $products = Product::where('store_id', $store->id)
            ->where('template_id', $looseStoneTemplate->id)
            ->get();

        foreach ($products as $product) {
            try {
                $result = $this->updateProductCurrentRapPrice($product);
                if ($result) {
                    $counts['updated']++;
                } else {
                    $counts['skipped']++;
                }
            } catch (\Exception $e) {
                Log::error('Error updating rap price', [
                    'product_id' => $product->id,
                    'error' => $e->getMessage(),
                ]);
                $counts['errors']++;
            }
        }

        return $counts;
    }

    /**
     * Update the current rap price for a single product.
     */
    public function updateProductCurrentRapPrice(Product $product): bool
    {
        $template = $product->getTemplate();
        if (! $template) {
            return false;
        }

        // Get the diamond attributes from the product
        $attributes = $product->attributeValues()
            ->with('field')
            ->get()
            ->keyBy(fn ($av) => $av->field?->name);

        $shape = $attributes->get('main_stone_shape')?->value;
        $color = $attributes->get('diamond_color')?->value;
        $clarity = $attributes->get('diamond_clarity')?->value;
        $weightValue = $attributes->get('main_stone_wt')?->value;

        // Parse weight from value like "0.63 carat"
        $weight = null;
        if ($weightValue && preg_match('/^([\d.]+)/', $weightValue, $matches)) {
            $weight = (float) $matches[1];
        }

        if (! $shape || ! $color || ! $clarity || ! $weight) {
            return false;
        }

        // Color and clarity come from DB as lowercase, need uppercase for lookup
        return $this->setProductRapPrice(
            $product,
            $shape,
            strtoupper($color),
            strtoupper($clarity),
            $weight,
            isInitial: false, // Only update current_rap_price
        );
    }

    /**
     * Set attribute value by field name.
     */
    protected function setAttributeByName(Product $product, $fields, string $fieldName, string $value): void
    {
        $field = $fields->firstWhere('name', $fieldName);
        if ($field) {
            ProductAttributeValue::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'product_template_field_id' => $field->id,
                ],
                ['value' => $value]
            );
        }
    }
}
