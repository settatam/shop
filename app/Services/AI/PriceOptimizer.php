<?php

namespace App\Services\AI;

use App\Models\AiSuggestion;
use App\Models\Product;

class PriceOptimizer
{
    protected AIManager $aiManager;

    public function __construct(AIManager $aiManager)
    {
        $this->aiManager = $aiManager;
    }

    public function optimize(Product $product, array $options = []): AiSuggestion
    {
        $platform = $options['platform'] ?? null;
        $competitorPrices = $options['competitor_prices'] ?? [];
        $strategy = $options['strategy'] ?? 'balanced'; // competitive, premium, value, balanced

        $prompt = $this->buildOptimizationPrompt($product, $competitorPrices, $platform, $strategy);

        $response = $this->aiManager->generateJson($prompt, $this->getPriceSchema(), [
            'feature' => 'price_optimization',
            'temperature' => 0.4,
        ]);

        $result = $response->toJson();

        $currentPrice = $product->variants->first()?->price ?? 0;

        return AiSuggestion::create([
            'store_id' => $product->store_id,
            'suggestable_type' => Product::class,
            'suggestable_id' => $product->id,
            'type' => 'price',
            'platform' => $platform,
            'original_content' => (string) $currentPrice,
            'suggested_content' => json_encode($result),
            'metadata' => [
                'current_price' => $currentPrice,
                'suggested_price' => $result['suggested_price'] ?? null,
                'min_price' => $result['min_price'] ?? null,
                'max_price' => $result['max_price'] ?? null,
                'confidence' => $result['confidence'] ?? null,
                'reasoning' => $result['reasoning'] ?? null,
                'price_change_percent' => $currentPrice > 0
                    ? round((($result['suggested_price'] ?? $currentPrice) - $currentPrice) / $currentPrice * 100, 2)
                    : 0,
                'strategy' => $strategy,
                'competitor_count' => count($competitorPrices),
                'tokens_used' => $response->totalTokens(),
                'model' => $response->model,
            ],
        ]);
    }

    public function optimizeBulk(array $products, array $options = []): array
    {
        $suggestions = [];
        foreach ($products as $product) {
            $suggestions[] = $this->optimize($product, $options);
        }

        return $suggestions;
    }

    public function analyzeProfitMargin(Product $product, array $options = []): array
    {
        $variant = $product->variants->first();
        $price = $variant?->price ?? 0;
        $cost = $variant?->cost ?? 0;

        if ($price <= 0 || $cost <= 0) {
            return [
                'margin_percent' => 0,
                'margin_amount' => 0,
                'analysis' => 'Unable to calculate margin - missing price or cost data',
            ];
        }

        $marginAmount = $price - $cost;
        $marginPercent = ($marginAmount / $price) * 100;

        // Factor in platform fees if specified
        $platformFeePercent = $options['platform_fee_percent'] ?? 0;
        $netMarginPercent = $marginPercent - $platformFeePercent;

        return [
            'price' => $price,
            'cost' => $cost,
            'margin_amount' => round($marginAmount, 2),
            'margin_percent' => round($marginPercent, 2),
            'platform_fee_percent' => $platformFeePercent,
            'net_margin_percent' => round($netMarginPercent, 2),
            'health' => $this->assessMarginHealth($netMarginPercent),
        ];
    }

    public function suggestSalePrice(Product $product, array $options = []): AiSuggestion
    {
        $discountPercent = $options['discount_percent'] ?? null;
        $targetMargin = $options['target_margin'] ?? 20;

        $variant = $product->variants->first();
        $currentPrice = $variant?->price ?? 0;
        $cost = $variant?->cost ?? 0;

        $systemPrompt = <<<'PROMPT'
You are a pricing strategist helping determine optimal sale prices.
Consider the cost, current price, target margin, and market factors.
Suggest a sale price that maintains profitability while being attractive to buyers.
PROMPT;

        $userPrompt = $this->buildSalePricePrompt($product, $currentPrice, $cost, $targetMargin, $discountPercent);

        $response = $this->aiManager->generateJson($userPrompt, $this->getSalePriceSchema(), [
            'feature' => 'sale_price_suggestion',
            'temperature' => 0.4,
        ]);

        $result = $response->toJson();

        return AiSuggestion::create([
            'store_id' => $product->store_id,
            'suggestable_type' => Product::class,
            'suggestable_id' => $product->id,
            'type' => 'sale_price',
            'platform' => $options['platform'] ?? null,
            'original_content' => (string) $currentPrice,
            'suggested_content' => json_encode($result),
            'metadata' => [
                'current_price' => $currentPrice,
                'suggested_sale_price' => $result['sale_price'] ?? null,
                'discount_percent' => $result['discount_percent'] ?? null,
                'projected_margin' => $result['projected_margin'] ?? null,
                'reasoning' => $result['reasoning'] ?? null,
                'tokens_used' => $response->totalTokens(),
                'model' => $response->model,
            ],
        ]);
    }

    public function suggestBundlePrice(array $products, array $options = []): array
    {
        $totalIndividualPrice = 0;
        $totalCost = 0;
        $productInfo = [];

        foreach ($products as $product) {
            $variant = $product->variants->first();
            $price = $variant?->price ?? 0;
            $cost = $variant?->cost ?? 0;

            $totalIndividualPrice += $price;
            $totalCost += $cost;

            $productInfo[] = [
                'id' => $product->id,
                'title' => $product->title,
                'price' => $price,
                'cost' => $cost,
            ];
        }

        $discountPercent = $options['discount_percent'] ?? 15;
        $suggestedBundlePrice = $totalIndividualPrice * (1 - ($discountPercent / 100));
        $bundleMargin = $totalCost > 0 ? (($suggestedBundlePrice - $totalCost) / $suggestedBundlePrice) * 100 : 0;

        return [
            'products' => $productInfo,
            'total_individual_price' => round($totalIndividualPrice, 2),
            'total_cost' => round($totalCost, 2),
            'suggested_bundle_price' => round($suggestedBundlePrice, 2),
            'bundle_discount_percent' => $discountPercent,
            'bundle_margin_percent' => round($bundleMargin, 2),
            'savings_amount' => round($totalIndividualPrice - $suggestedBundlePrice, 2),
        ];
    }

    protected function buildOptimizationPrompt(
        Product $product,
        array $competitorPrices,
        ?string $platform,
        string $strategy
    ): string {
        $variant = $product->variants->first();
        $currentPrice = $variant?->price ?? 0;
        $cost = $variant?->cost ?? 0;

        $competitorInfo = '';
        if (! empty($competitorPrices)) {
            $competitorInfo = "\n\nCompetitor Prices:\n";
            foreach ($competitorPrices as $competitor) {
                $competitorInfo .= "- {$competitor['name']}: \${$competitor['price']}\n";
            }
            $avgCompetitorPrice = collect($competitorPrices)->avg('price');
            $competitorInfo .= "Average competitor price: \${$avgCompetitorPrice}\n";
        }

        $strategyGuideline = match ($strategy) {
            'competitive' => 'Prioritize competitive positioning, willing to sacrifice some margin for market share.',
            'premium' => 'Position as a premium product, emphasize value and quality justification for higher prices.',
            'value' => 'Focus on value proposition, competitive pricing while maintaining reasonable margins.',
            'balanced' => 'Balance between competitiveness and profitability.',
            default => 'Balance between competitiveness and profitability.',
        };

        return <<<PROMPT
Analyze the following product and suggest an optimal price.

Product Information:
- Title: {$product->title}
- Brand: {$product->brand?->name}
- Category: {$product->category?->name}
- Current Price: \${$currentPrice}
- Cost: \${$cost}
- Current Margin: {$this->calculateMarginPercent($currentPrice, $cost)}%
{$competitorInfo}
Platform: {$platform}
Pricing Strategy: {$strategyGuideline}

Respond with a JSON object containing:
- suggested_price: The recommended optimal price (number)
- min_price: The minimum viable price to maintain profitability (number)
- max_price: The maximum reasonable price before demand drops (number)
- confidence: A score from 0-100 indicating confidence in the suggestion
- reasoning: A brief explanation of the pricing rationale
PROMPT;
    }

    protected function buildSalePricePrompt(
        Product $product,
        float $currentPrice,
        float $cost,
        float $targetMargin,
        ?float $discountPercent
    ): string {
        $discountInfo = $discountPercent
            ? "Requested discount percentage: {$discountPercent}%"
            : 'No specific discount percentage requested';

        return <<<PROMPT
Suggest a sale price for the following product:

Product: {$product->title}
Current Price: \${$currentPrice}
Cost: \${$cost}
Current Margin: {$this->calculateMarginPercent($currentPrice, $cost)}%
Target Minimum Margin: {$targetMargin}%
{$discountInfo}

Respond with a JSON object containing:
- sale_price: The suggested sale price (number)
- discount_percent: The discount percentage from current price
- projected_margin: The projected profit margin at the sale price
- reasoning: Brief explanation of the sale price recommendation
PROMPT;
    }

    protected function getPriceSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'suggested_price' => ['type' => 'number'],
                'min_price' => ['type' => 'number'],
                'max_price' => ['type' => 'number'],
                'confidence' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                'reasoning' => ['type' => 'string'],
            ],
            'required' => ['suggested_price', 'confidence'],
        ];
    }

    protected function getSalePriceSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sale_price' => ['type' => 'number'],
                'discount_percent' => ['type' => 'number'],
                'projected_margin' => ['type' => 'number'],
                'reasoning' => ['type' => 'string'],
            ],
            'required' => ['sale_price', 'discount_percent'],
        ];
    }

    protected function calculateMarginPercent(float $price, float $cost): float
    {
        if ($price <= 0) {
            return 0;
        }

        return round((($price - $cost) / $price) * 100, 2);
    }

    protected function assessMarginHealth(float $marginPercent): string
    {
        return match (true) {
            $marginPercent >= 50 => 'excellent',
            $marginPercent >= 35 => 'good',
            $marginPercent >= 20 => 'acceptable',
            $marginPercent >= 10 => 'low',
            $marginPercent >= 0 => 'critical',
            default => 'negative',
        };
    }
}
