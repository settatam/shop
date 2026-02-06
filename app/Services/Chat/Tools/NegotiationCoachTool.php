<?php

namespace App\Services\Chat\Tools;

use App\Models\MetalPrice;
use App\Models\Product;

class NegotiationCoachTool implements ChatToolInterface
{
    public function name(): string
    {
        return 'get_negotiation_advice';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Get negotiation advice for buying items from customers. Use when user asks "what should I offer", "help me price this", "negotiation help", or describes an item they want to buy. Calculates fair offer based on metal content, market prices, and margins.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'metal_type' => [
                        'type' => 'string',
                        'enum' => ['gold_10k', 'gold_14k', 'gold_18k', 'gold_22k', 'gold_24k', 'silver', 'platinum', 'palladium'],
                        'description' => 'Type of precious metal',
                    ],
                    'weight_grams' => [
                        'type' => 'number',
                        'description' => 'Weight in grams',
                    ],
                    'weight_dwt' => [
                        'type' => 'number',
                        'description' => 'Weight in pennyweight (DWT)',
                    ],
                    'item_type' => [
                        'type' => 'string',
                        'description' => 'Type of item (e.g., chain, ring, bracelet, watch)',
                    ],
                    'condition' => [
                        'type' => 'string',
                        'enum' => ['excellent', 'good', 'fair', 'poor'],
                        'description' => 'Condition of the item',
                    ],
                    'has_stones' => [
                        'type' => 'boolean',
                        'description' => 'Whether item has gemstones',
                    ],
                    'brand' => [
                        'type' => 'string',
                        'description' => 'Brand name if applicable (e.g., Rolex, Cartier)',
                    ],
                    'target_margin' => [
                        'type' => 'number',
                        'description' => 'Target profit margin percentage (default 40)',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    public function execute(array $params, int $storeId): array
    {
        $metalType = $params['metal_type'] ?? null;
        $weightGrams = $params['weight_grams'] ?? null;
        $weightDwt = $params['weight_dwt'] ?? null;
        $itemType = $params['item_type'] ?? 'jewelry';
        $condition = $params['condition'] ?? 'good';
        $hasStones = $params['has_stones'] ?? false;
        $brand = $params['brand'] ?? null;
        $targetMargin = $params['target_margin'] ?? 40;

        // Convert DWT to grams if needed (1 DWT = 1.555 grams)
        if ($weightDwt && ! $weightGrams) {
            $weightGrams = $weightDwt * 1.555;
        }

        $result = [
            'item_description' => $this->buildDescription($metalType, $weightGrams, $itemType, $brand),
            'calculations' => [],
            'offer_range' => [],
            'similar_sales' => [],
            'negotiation_tips' => [],
        ];

        // Metal value calculation
        if ($metalType && $weightGrams) {
            $metalCalc = $this->calculateMetalValue($metalType, $weightGrams);
            $result['calculations']['metal_value'] = $metalCalc;

            // Calculate offer range based on payout percentages
            $spotValue = $metalCalc['spot_value'];
            $payoutPercentages = $this->getPayoutPercentages($condition, $hasStones);

            $result['offer_range'] = [
                'low' => [
                    'amount' => round($spotValue * $payoutPercentages['low'], 2),
                    'amount_formatted' => '$'.number_format($spotValue * $payoutPercentages['low'], 0),
                    'payout_percent' => $payoutPercentages['low'] * 100,
                ],
                'recommended' => [
                    'amount' => round($spotValue * $payoutPercentages['recommended'], 2),
                    'amount_formatted' => '$'.number_format($spotValue * $payoutPercentages['recommended'], 0),
                    'payout_percent' => $payoutPercentages['recommended'] * 100,
                ],
                'high' => [
                    'amount' => round($spotValue * $payoutPercentages['high'], 2),
                    'amount_formatted' => '$'.number_format($spotValue * $payoutPercentages['high'], 0),
                    'payout_percent' => $payoutPercentages['high'] * 100,
                ],
            ];

            // Expected retail price
            $recommendedOffer = $spotValue * $payoutPercentages['recommended'];
            $expectedRetail = $recommendedOffer / (1 - ($targetMargin / 100));
            $result['expected_retail'] = [
                'amount' => round($expectedRetail, 2),
                'amount_formatted' => '$'.number_format($expectedRetail, 0),
                'margin_percent' => $targetMargin,
            ];
        }

        // Look up similar sales
        $result['similar_sales'] = $this->findSimilarSales($storeId, $metalType, $itemType, $brand);

        // Generate negotiation tips
        $result['negotiation_tips'] = $this->generateTips($metalType, $condition, $hasStones, $brand, $itemType);

        return $result;
    }

    protected function calculateMetalValue(string $metalType, float $weightGrams): array
    {
        $purityRatios = MetalPrice::PURITY_RATIOS;
        $purityRatio = $purityRatios[$metalType] ?? 1.0;

        // Get base metal type
        $baseMetal = str_starts_with($metalType, 'gold') ? 'gold' : $metalType;

        $price = MetalPrice::getLatest($baseMetal);

        if (! $price) {
            return [
                'error' => 'Metal prices not available',
                'metal_type' => $metalType,
                'weight_grams' => $weightGrams,
            ];
        }

        $pureMetalGrams = $weightGrams * $purityRatio;
        $spotValue = $pureMetalGrams * (float) $price->price_per_gram;

        return [
            'metal_type' => $metalType,
            'weight_grams' => round($weightGrams, 2),
            'weight_dwt' => round($weightGrams / 1.555, 2),
            'purity_ratio' => $purityRatio,
            'pure_metal_grams' => round($pureMetalGrams, 2),
            'spot_price_per_gram' => round((float) $price->price_per_gram, 2),
            'spot_value' => round($spotValue, 2),
            'spot_value_formatted' => '$'.number_format($spotValue, 0),
        ];
    }

    protected function getPayoutPercentages(string $condition, bool $hasStones): array
    {
        // Base percentages
        $base = match ($condition) {
            'excellent' => ['low' => 0.60, 'recommended' => 0.65, 'high' => 0.70],
            'good' => ['low' => 0.55, 'recommended' => 0.60, 'high' => 0.65],
            'fair' => ['low' => 0.50, 'recommended' => 0.55, 'high' => 0.60],
            'poor' => ['low' => 0.45, 'recommended' => 0.50, 'high' => 0.55],
            default => ['low' => 0.55, 'recommended' => 0.60, 'high' => 0.65],
        };

        // Reduce slightly if has stones (harder to value, may need to remove)
        if ($hasStones) {
            $base['low'] -= 0.05;
            $base['recommended'] -= 0.05;
            $base['high'] -= 0.05;
        }

        return $base;
    }

    protected function findSimilarSales(int $storeId, ?string $metalType, ?string $itemType, ?string $brand): array
    {
        $query = Product::where('store_id', $storeId)
            ->where('status', 'sold')
            ->whereNotNull('price')
            ->where('price', '>', 0);

        if ($metalType) {
            $baseMetal = str_starts_with($metalType, 'gold') ? 'gold' : $metalType;
            $query->where(function ($q) use ($baseMetal, $metalType) {
                $q->where('title', 'like', "%{$baseMetal}%")
                    ->orWhere('title', 'like', "%{$metalType}%");
            });
        }

        if ($itemType) {
            $query->where('title', 'like', "%{$itemType}%");
        }

        if ($brand) {
            $query->where('title', 'like', "%{$brand}%");
        }

        $sales = $query->orderBy('updated_at', 'desc')
            ->limit(5)
            ->get();

        return $sales->map(function ($product) {
            return [
                'title' => $product->title,
                'sold_price' => round($product->price, 2),
                'sold_price_formatted' => '$'.number_format($product->price, 0),
                'cost' => $product->cost ? round($product->cost, 2) : null,
                'margin' => $product->cost ? round((($product->price - $product->cost) / $product->price) * 100) : null,
                'sold_date' => $product->updated_at->format('M j'),
            ];
        })->toArray();
    }

    protected function generateTips(
        ?string $metalType,
        string $condition,
        bool $hasStones,
        ?string $brand,
        ?string $itemType
    ): array {
        $tips = [];

        if ($metalType && str_starts_with($metalType, 'gold')) {
            $tips[] = 'Test the gold with acid to verify karat';
            $tips[] = 'Check for stamps/hallmarks inside the piece';
        }

        if ($hasStones) {
            $tips[] = 'Stones need separate evaluation - offer based on metal only, stones are bonus';
            $tips[] = 'Check if stones are real or synthetic';
        }

        if ($brand) {
            $tips[] = "Verify authenticity - {$brand} fakes are common";
            $tips[] = 'Brand premium applies if authentic with box/papers';
        }

        if ($condition === 'poor') {
            $tips[] = 'Factor in repair/cleaning costs';
            $tips[] = 'May need to sell as scrap if too damaged';
        }

        if ($itemType === 'watch') {
            $tips[] = 'Check if watch runs and keeps time';
            $tips[] = 'Look up recent eBay sold listings for this model';
        }

        $tips[] = 'Start with the low offer and negotiate up';
        $tips[] = "Ask what they're hoping to get - they might be lower than you expected";

        return $tips;
    }

    protected function buildDescription(?string $metalType, ?float $weightGrams, ?string $itemType, ?string $brand): string
    {
        $parts = [];

        if ($brand) {
            $parts[] = $brand;
        }

        if ($metalType) {
            $parts[] = str_replace('_', ' ', $metalType);
        }

        if ($itemType) {
            $parts[] = $itemType;
        }

        if ($weightGrams) {
            $parts[] = round($weightGrams, 1).'g';
        }

        return implode(' ', $parts) ?: 'Item';
    }
}
