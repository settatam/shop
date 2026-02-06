<?php

namespace App\Services\Agents\Agents;

use App\Enums\AgentType;
use App\Models\AgentAction;
use App\Models\AgentRun;
use App\Models\Product;
use App\Models\StoreAgent;
use App\Services\Agents\Contracts\AgentInterface;
use App\Services\Agents\Results\AgentRunResult;
use App\Services\Search\WebPriceSearchService;
use Illuminate\Support\Carbon;

class AutoPricingAgent implements AgentInterface
{
    public function __construct(
        protected WebPriceSearchService $priceSearchService
    ) {}

    public function getName(): string
    {
        return 'Auto-Pricing Agent';
    }

    public function getSlug(): string
    {
        return 'auto-pricing';
    }

    public function getType(): AgentType
    {
        return AgentType::Background;
    }

    public function getDescription(): string
    {
        return 'Monitors market prices and adjusts inventory pricing automatically based on competitive analysis.';
    }

    public function getDefaultConfig(): array
    {
        return [
            'run_frequency' => 'daily',
            'price_check_threshold_days' => 30,
            'max_items_per_run' => 50,
            'market_data_sources' => ['ebay_sold', 'google_shopping'],
            'auto_adjust_threshold' => 10, // % difference triggers adjustment
            'require_approval_above' => 100, // $ threshold for approval
            'pricing_strategy' => 'competitive', // competitive, undercut, premium
            'max_price_decrease_percent' => 25,
            'max_price_increase_percent' => 15,
        ];
    }

    public function getConfigSchema(): array
    {
        return [
            'run_frequency' => [
                'type' => 'select',
                'label' => 'Run Frequency',
                'description' => 'How often to check market prices',
                'options' => [
                    'hourly' => 'Hourly',
                    'every_six_hours' => 'Every 6 Hours',
                    'daily' => 'Daily',
                    'weekly' => 'Weekly',
                ],
                'default' => 'daily',
            ],
            'price_check_threshold_days' => [
                'type' => 'number',
                'label' => 'Price Check Threshold (Days)',
                'description' => 'Days since last price check before re-checking',
                'default' => 30,
            ],
            'max_items_per_run' => [
                'type' => 'number',
                'label' => 'Maximum Items Per Run',
                'description' => 'Limit the number of products to analyze per run',
                'default' => 50,
            ],
            'auto_adjust_threshold' => [
                'type' => 'number',
                'label' => 'Auto-Adjust Threshold (%)',
                'description' => 'Minimum price difference percentage to trigger adjustment',
                'default' => 10,
            ],
            'require_approval_above' => [
                'type' => 'number',
                'label' => 'Require Approval Above ($)',
                'description' => 'Price threshold above which changes require approval',
                'default' => 100,
            ],
            'pricing_strategy' => [
                'type' => 'select',
                'label' => 'Pricing Strategy',
                'description' => 'How to price relative to market',
                'options' => [
                    'competitive' => 'Competitive (Match median)',
                    'undercut' => 'Undercut (Below median)',
                    'premium' => 'Premium (Above median)',
                ],
                'default' => 'competitive',
            ],
        ];
    }

    public function run(AgentRun $run, StoreAgent $storeAgent): AgentRunResult
    {
        $config = $storeAgent->getMergedConfig();
        $storeId = $storeAgent->store_id;

        $thresholdDays = $config['price_check_threshold_days'] ?? 30;
        $maxItems = $config['max_items_per_run'] ?? 50;
        $autoAdjustThreshold = $config['auto_adjust_threshold'] ?? 10;
        $approvalThreshold = $config['require_approval_above'] ?? 100;
        $strategy = $config['pricing_strategy'] ?? 'competitive';

        // Find products needing price check
        $products = Product::forStore($storeId)
            ->where('status', 'active')
            ->where('price', '>', 0)
            ->where(function ($query) use ($thresholdDays) {
                $query->whereNull('last_price_check_at')
                    ->orWhere('last_price_check_at', '<=', Carbon::now()->subDays($thresholdDays));
            })
            ->orderBy('last_price_check_at', 'asc')
            ->limit($maxItems)
            ->get();

        $analyzed = 0;
        $actionsCreated = 0;
        $skipped = 0;

        foreach ($products as $product) {
            $analyzed++;

            // Build search criteria from product
            $criteria = $this->buildSearchCriteria($product);

            // Search for market prices
            $results = $this->priceSearchService->searchPrices($storeId, $criteria);

            // Update last price check
            $product->update(['last_price_check_at' => now()]);

            if (isset($results['error']) || empty($results['summary']['median'])) {
                $skipped++;

                continue;
            }

            $marketMedian = $results['summary']['median'];
            $currentPrice = $product->price;
            $priceDifferencePercent = abs(($currentPrice - $marketMedian) / $marketMedian * 100);

            // Skip if difference is below threshold
            if ($priceDifferencePercent < $autoAdjustThreshold) {
                continue;
            }

            // Calculate suggested price based on strategy
            $suggestedPrice = $this->calculateSuggestedPrice($marketMedian, $strategy, $config);

            // Apply price change limits
            $suggestedPrice = $this->applyPriceLimits($currentPrice, $suggestedPrice, $config);

            // Skip if suggested price equals current price after limits
            if (abs($suggestedPrice - $currentPrice) < 0.01) {
                continue;
            }

            // Create action
            AgentAction::create([
                'agent_run_id' => $run->id,
                'store_id' => $storeId,
                'action_type' => 'price_update',
                'actionable_type' => Product::class,
                'actionable_id' => $product->id,
                'status' => 'pending',
                'requires_approval' => $storeAgent->requiresApproval() || $suggestedPrice > $approvalThreshold,
                'payload' => [
                    'before' => ['price' => $currentPrice],
                    'after' => ['price' => $suggestedPrice],
                    'market_data' => [
                        'median' => $marketMedian,
                        'min' => $results['summary']['min'],
                        'max' => $results['summary']['max'],
                        'sample_size' => $results['summary']['count'],
                    ],
                    'price_difference_percent' => round($priceDifferencePercent, 2),
                    'strategy' => $strategy,
                    'reasoning' => $this->generateReasoning($product, $currentPrice, $suggestedPrice, $results['summary'], $strategy),
                ],
            ]);

            $actionsCreated++;
        }

        return AgentRunResult::success([
            'products_analyzed' => $analyzed,
            'products_skipped' => $skipped,
            'price_updates_proposed' => $actionsCreated,
        ], $actionsCreated);
    }

    public function canRun(StoreAgent $storeAgent): bool
    {
        return $storeAgent->canRun();
    }

    public function getSubscribedEvents(): array
    {
        return []; // Background agent, no events
    }

    public function handleEvent(string $event, array $payload, StoreAgent $storeAgent): void
    {
        // Not an event-triggered agent
    }

    protected function buildSearchCriteria(Product $product): array
    {
        $criteria = [
            'title' => $product->title,
        ];

        if ($product->category) {
            $criteria['category'] = $product->category->name;
        }

        // Add relevant attributes
        $attributes = $product->attributes ?? [];
        if (! empty($attributes)) {
            $criteria['attributes'] = $attributes;
        }

        return $criteria;
    }

    protected function calculateSuggestedPrice(float $median, string $strategy, array $config): float
    {
        return match ($strategy) {
            'undercut' => round($median * 0.95, 2), // 5% below median
            'premium' => round($median * 1.1, 2), // 10% above median
            default => round($median, 2), // competitive - match median
        };
    }

    protected function applyPriceLimits(float $currentPrice, float $suggestedPrice, array $config): float
    {
        $maxDecrease = $config['max_price_decrease_percent'] ?? 25;
        $maxIncrease = $config['max_price_increase_percent'] ?? 15;

        $minAllowed = $currentPrice * (1 - $maxDecrease / 100);
        $maxAllowed = $currentPrice * (1 + $maxIncrease / 100);

        return max($minAllowed, min($maxAllowed, $suggestedPrice));
    }

    protected function generateReasoning(
        Product $product,
        float $currentPrice,
        float $suggestedPrice,
        array $marketSummary,
        string $strategy
    ): string {
        $direction = $suggestedPrice > $currentPrice ? 'increase' : 'decrease';
        $changePercent = abs(($suggestedPrice - $currentPrice) / $currentPrice * 100);

        $strategyLabel = match ($strategy) {
            'undercut' => 'slightly below market median',
            'premium' => 'above market median for premium positioning',
            default => 'at market median',
        };

        return "Market analysis for '{$product->title}' shows median price of \${$marketSummary['median']} "
            ."(range: \${$marketSummary['min']} - \${$marketSummary['max']}, based on {$marketSummary['count']} listings). "
            ."Current price of \${$currentPrice} suggests a ".round($changePercent, 1)."% {$direction} to \${$suggestedPrice} "
            ."following the '{$strategy}' strategy to position {$strategyLabel}.";
    }
}
