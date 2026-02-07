<?php

namespace App\Services\Agents\Agents;

use App\Enums\AgentType;
use App\Models\AgentAction;
use App\Models\AgentRun;
use App\Models\PlatformListing;
use App\Models\PlatformOrder;
use App\Models\Product;
use App\Models\StoreAgent;
use App\Models\StoreMarketplace;
use App\Services\Agents\Contracts\AgentInterface;
use App\Services\Agents\Results\AgentRunResult;
use App\Services\AI\AIManager;
use Illuminate\Support\Facades\DB;

class SalesIntelligenceAgent implements AgentInterface
{
    public function __construct(
        protected AIManager $ai
    ) {}

    public function getName(): string
    {
        return 'Sales Intelligence Agent';
    }

    public function getSlug(): string
    {
        return 'sales-intelligence';
    }

    public function getType(): AgentType
    {
        return AgentType::Proactive;
    }

    public function getDescription(): string
    {
        return 'Analyzes sales data across all channels to identify trends, opportunities, and issues. Provides actionable insights and proactive recommendations for improving sales performance.';
    }

    public function getDefaultConfig(): array
    {
        return [
            'analysis_period_days' => 30,
            'comparison_period_days' => 30,
            'min_sales_for_trend' => 5,
            'generate_insights' => true,
            'generate_recommendations' => true,
            'track_channel_performance' => true,
            'identify_opportunities' => true,
            'alert_on_declining_products' => true,
            'revenue_decline_threshold' => 20,
        ];
    }

    public function getConfigSchema(): array
    {
        return [
            'analysis_period_days' => [
                'type' => 'number',
                'label' => 'Analysis Period (Days)',
                'description' => 'Number of days to analyze for trends',
                'default' => 30,
            ],
            'comparison_period_days' => [
                'type' => 'number',
                'label' => 'Comparison Period (Days)',
                'description' => 'Days to compare against for growth metrics',
                'default' => 30,
            ],
            'generate_insights' => [
                'type' => 'boolean',
                'label' => 'Generate AI Insights',
                'description' => 'Use AI to generate actionable insights',
                'default' => true,
            ],
            'generate_recommendations' => [
                'type' => 'boolean',
                'label' => 'Generate Recommendations',
                'description' => 'Provide specific action recommendations',
                'default' => true,
            ],
            'track_channel_performance' => [
                'type' => 'boolean',
                'label' => 'Track Channel Performance',
                'description' => 'Compare performance across marketplaces',
                'default' => true,
            ],
            'alert_on_declining_products' => [
                'type' => 'boolean',
                'label' => 'Alert on Declining Sales',
                'description' => 'Notify when product sales are declining',
                'default' => true,
            ],
            'revenue_decline_threshold' => [
                'type' => 'number',
                'label' => 'Decline Threshold (%)',
                'description' => 'Alert when revenue drops by this percentage',
                'default' => 20,
            ],
        ];
    }

    public function run(AgentRun $run, StoreAgent $storeAgent): AgentRunResult
    {
        $config = $storeAgent->getMergedConfig();
        $storeId = $storeAgent->store_id;

        $analysisPeriod = $config['analysis_period_days'] ?? 30;
        $comparisonPeriod = $config['comparison_period_days'] ?? 30;

        $results = [
            'period' => "{$analysisPeriod} days",
            'summary' => [],
            'channel_performance' => [],
            'top_performers' => [],
            'declining_products' => [],
            'opportunities' => [],
            'insights' => [],
            'recommendations' => [],
        ];

        $actionsCreated = 0;

        // Analyze overall performance
        $results['summary'] = $this->analyzeSummary($storeId, $analysisPeriod, $comparisonPeriod);

        // Analyze channel performance
        if ($config['track_channel_performance'] ?? true) {
            $results['channel_performance'] = $this->analyzeChannelPerformance($storeId, $analysisPeriod);
        }

        // Identify top performers
        $results['top_performers'] = $this->identifyTopPerformers($storeId, $analysisPeriod);

        // Identify declining products
        if ($config['alert_on_declining_products'] ?? true) {
            $threshold = $config['revenue_decline_threshold'] ?? 20;
            $results['declining_products'] = $this->identifyDecliningProducts(
                $storeId,
                $analysisPeriod,
                $comparisonPeriod,
                $threshold
            );

            // Create alerts for significantly declining products
            foreach ($results['declining_products'] as $product) {
                if ($product['decline_percent'] >= $threshold) {
                    AgentAction::create([
                        'agent_run_id' => $run->id,
                        'store_id' => $storeId,
                        'action_type' => 'send_notification',
                        'actionable_type' => Product::class,
                        'actionable_id' => $product['product_id'],
                        'status' => 'pending',
                        'requires_approval' => false,
                        'payload' => [
                            'type' => 'declining_sales',
                            'product_title' => $product['title'],
                            'decline_percent' => $product['decline_percent'],
                            'message' => "'{$product['title']}' sales declined by {$product['decline_percent']}% compared to previous period",
                        ],
                    ]);
                    $actionsCreated++;
                }
            }
        }

        // Identify opportunities
        if ($config['identify_opportunities'] ?? true) {
            $results['opportunities'] = $this->identifyOpportunities($storeId, $analysisPeriod);
        }

        // Generate AI insights
        if ($config['generate_insights'] ?? true) {
            $results['insights'] = $this->generateAIInsights($results);
        }

        // Generate recommendations
        if ($config['generate_recommendations'] ?? true) {
            $results['recommendations'] = $this->generateRecommendations($results, $run, $storeId);
            $actionsCreated += count($results['recommendations']);
        }

        return AgentRunResult::success($results, $actionsCreated);
    }

    /**
     * Analyze overall sales summary.
     */
    protected function analyzeSummary(int $storeId, int $days, int $comparisonDays): array
    {
        $currentStart = now()->subDays($days);
        $previousStart = now()->subDays($days + $comparisonDays);
        $previousEnd = now()->subDays($days);

        // Current period metrics
        $current = DB::table('orders')
            ->where('store_id', $storeId)
            ->where('created_at', '>=', $currentStart)
            ->whereIn('status', ['completed', 'shipped', 'delivered'])
            ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(total), 0) as revenue, COALESCE(AVG(total), 0) as avg_order')
            ->first();

        // Previous period metrics
        $previous = DB::table('orders')
            ->where('store_id', $storeId)
            ->whereBetween('created_at', [$previousStart, $previousEnd])
            ->whereIn('status', ['completed', 'shipped', 'delivered'])
            ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(total), 0) as revenue, COALESCE(AVG(total), 0) as avg_order')
            ->first();

        // Platform orders
        $platformRevenue = DB::table('platform_orders')
            ->join('store_marketplaces', 'platform_orders.store_marketplace_id', '=', 'store_marketplaces.id')
            ->where('store_marketplaces.store_id', $storeId)
            ->where('platform_orders.ordered_at', '>=', $currentStart)
            ->sum('platform_orders.total');

        return [
            'total_revenue' => (float) $current->revenue + $platformRevenue,
            'order_count' => (int) $current->order_count,
            'average_order_value' => round((float) $current->avg_order, 2),
            'revenue_growth' => $previous->revenue > 0
                ? round((($current->revenue - $previous->revenue) / $previous->revenue) * 100, 1)
                : 0,
            'order_growth' => $previous->order_count > 0
                ? round((($current->order_count - $previous->order_count) / $previous->order_count) * 100, 1)
                : 0,
            'platform_revenue' => $platformRevenue,
        ];
    }

    /**
     * Analyze performance by channel.
     */
    protected function analyzeChannelPerformance(int $storeId, int $days): array
    {
        $since = now()->subDays($days);
        $performance = [];

        // Local/POS sales
        $localSales = DB::table('orders')
            ->where('store_id', $storeId)
            ->where('created_at', '>=', $since)
            ->whereIn('status', ['completed', 'shipped', 'delivered'])
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(total), 0) as revenue')
            ->first();

        $performance['local'] = [
            'channel' => 'Local/POS',
            'orders' => (int) $localSales->orders,
            'revenue' => (float) $localSales->revenue,
        ];

        // Platform sales
        $marketplaces = StoreMarketplace::where('store_id', $storeId)
            ->where('status', 'active')
            ->get();

        foreach ($marketplaces as $marketplace) {
            $platformSales = PlatformOrder::where('store_marketplace_id', $marketplace->id)
                ->where('ordered_at', '>=', $since)
                ->selectRaw('COUNT(*) as orders, COALESCE(SUM(total), 0) as revenue')
                ->first();

            $performance[$marketplace->platform->value] = [
                'channel' => $marketplace->platform->label(),
                'orders' => (int) $platformSales->orders,
                'revenue' => (float) $platformSales->revenue,
            ];
        }

        // Calculate percentages
        $totalRevenue = array_sum(array_column($performance, 'revenue'));
        foreach ($performance as $key => $channel) {
            $performance[$key]['revenue_percent'] = $totalRevenue > 0
                ? round(($channel['revenue'] / $totalRevenue) * 100, 1)
                : 0;
        }

        return $performance;
    }

    /**
     * Identify top performing products.
     */
    protected function identifyTopPerformers(int $storeId, int $days, int $limit = 10): array
    {
        $since = now()->subDays($days);

        return DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where('orders.store_id', $storeId)
            ->where('orders.created_at', '>=', $since)
            ->whereIn('orders.status', ['completed', 'shipped', 'delivered'])
            ->groupBy('products.id', 'products.title', 'products.sku')
            ->selectRaw('
                products.id as product_id,
                products.title,
                products.sku,
                SUM(order_items.quantity) as units_sold,
                SUM(order_items.total) as revenue
            ')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->toArray();
    }

    /**
     * Identify products with declining sales.
     */
    protected function identifyDecliningProducts(
        int $storeId,
        int $currentDays,
        int $comparisonDays,
        float $threshold
    ): array {
        $currentStart = now()->subDays($currentDays);
        $previousStart = now()->subDays($currentDays + $comparisonDays);
        $previousEnd = now()->subDays($currentDays);

        // Get products with sales in both periods
        $currentSales = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.store_id', $storeId)
            ->where('orders.created_at', '>=', $currentStart)
            ->whereIn('orders.status', ['completed', 'shipped', 'delivered'])
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id, SUM(order_items.total) as revenue')
            ->pluck('revenue', 'product_id')
            ->toArray();

        $previousSales = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.store_id', $storeId)
            ->whereBetween('orders.created_at', [$previousStart, $previousEnd])
            ->whereIn('orders.status', ['completed', 'shipped', 'delivered'])
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id, SUM(order_items.total) as revenue')
            ->pluck('revenue', 'product_id')
            ->toArray();

        $declining = [];

        foreach ($previousSales as $productId => $previousRevenue) {
            if ($previousRevenue <= 0) {
                continue;
            }

            $currentRevenue = $currentSales[$productId] ?? 0;
            $declinePercent = (($previousRevenue - $currentRevenue) / $previousRevenue) * 100;

            if ($declinePercent >= $threshold) {
                $product = Product::find($productId);

                if ($product) {
                    $declining[] = [
                        'product_id' => $productId,
                        'title' => $product->title,
                        'sku' => $product->sku,
                        'previous_revenue' => $previousRevenue,
                        'current_revenue' => $currentRevenue,
                        'decline_percent' => round($declinePercent, 1),
                    ];
                }
            }
        }

        // Sort by decline percentage
        usort($declining, fn ($a, $b) => $b['decline_percent'] <=> $a['decline_percent']);

        return array_slice($declining, 0, 10);
    }

    /**
     * Identify sales opportunities.
     */
    protected function identifyOpportunities(int $storeId, int $days): array
    {
        $opportunities = [];

        // Products performing well locally but not listed on marketplaces
        $localTopSellers = $this->identifyTopPerformers($storeId, $days, 20);

        foreach ($localTopSellers as $product) {
            $listingCount = PlatformListing::where('product_id', $product['product_id'])
                ->whereIn('status', ['active', 'pending'])
                ->count();

            $marketplaceCount = StoreMarketplace::where('store_id', $storeId)
                ->where('status', 'active')
                ->count();

            if ($listingCount < $marketplaceCount && $marketplaceCount > 0) {
                $opportunities[] = [
                    'type' => 'expand_distribution',
                    'product_id' => $product['product_id'],
                    'title' => $product['title'],
                    'current_channels' => $listingCount,
                    'available_channels' => $marketplaceCount,
                    'potential' => 'List on additional marketplaces to increase reach',
                    'priority' => 'high',
                ];
            }
        }

        // Products with high inventory but low recent sales
        $slowMoving = Product::where('store_id', $storeId)
            ->where('status', 'active')
            ->where('quantity', '>', 10)
            ->whereDoesntHave('orderItems', function ($query) use ($days) {
                $query->whereHas('order', function ($q) use ($days) {
                    $q->where('created_at', '>=', now()->subDays($days));
                });
            })
            ->limit(10)
            ->get();

        foreach ($slowMoving as $product) {
            $opportunities[] = [
                'type' => 'slow_moving_inventory',
                'product_id' => $product->id,
                'title' => $product->title,
                'quantity' => $product->quantity,
                'potential' => 'Consider promotion or price reduction',
                'priority' => 'medium',
            ];
        }

        return $opportunities;
    }

    /**
     * Generate AI-powered insights.
     */
    protected function generateAIInsights(array $data): array
    {
        try {
            $prompt = <<<PROMPT
            Analyze this e-commerce sales data and provide 3-5 key insights:

            Summary:
            - Total Revenue: \${$data['summary']['total_revenue']}
            - Order Count: {$data['summary']['order_count']}
            - Revenue Growth: {$data['summary']['revenue_growth']}%
            - Order Growth: {$data['summary']['order_growth']}%

            Channel Performance:
            {$this->formatChannelPerformance($data['channel_performance'])}

            Top Products:
            {$this->formatTopProducts($data['top_performers'])}

            Declining Products: {$this->formatDeclining($data['declining_products'])}

            Provide actionable insights in JSON format:
            {
                "insights": [
                    {"title": "...", "description": "...", "importance": "high|medium|low"}
                ]
            }
            PROMPT;

            $response = $this->ai->generateJson($prompt, [
                'type' => 'object',
                'properties' => [
                    'insights' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'title' => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                                'importance' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ]);

            return $response['insights'] ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Generate recommendations and create actions.
     */
    protected function generateRecommendations(array $data, AgentRun $run, int $storeId): array
    {
        $recommendations = [];

        // Recommendation: Expand distribution
        foreach ($data['opportunities'] as $opp) {
            if ($opp['type'] === 'expand_distribution') {
                $recommendations[] = [
                    'type' => 'expand_distribution',
                    'product_id' => $opp['product_id'],
                    'title' => "List '{$opp['title']}' on more channels",
                    'description' => "This product sells well locally but is only on {$opp['current_channels']} of {$opp['available_channels']} available channels.",
                    'priority' => $opp['priority'],
                ];

                AgentAction::create([
                    'agent_run_id' => $run->id,
                    'store_id' => $storeId,
                    'action_type' => 'send_notification',
                    'actionable_type' => Product::class,
                    'actionable_id' => $opp['product_id'],
                    'status' => 'pending',
                    'requires_approval' => false,
                    'payload' => [
                        'type' => 'sales_opportunity',
                        'recommendation' => 'expand_distribution',
                        'product_title' => $opp['title'],
                        'message' => "'{$opp['title']}' is a top seller locally. Consider listing it on more marketplaces.",
                    ],
                ]);
            }
        }

        // Recommendation: Address declining products
        if (count($data['declining_products']) > 3) {
            $recommendations[] = [
                'type' => 'review_declining',
                'title' => 'Multiple products showing declining sales',
                'description' => count($data['declining_products']).' products have seen significant sales declines. Review pricing and marketing.',
                'priority' => 'high',
            ];
        }

        // Recommendation: Channel diversification
        $channelCount = count($data['channel_performance']);
        $localPercent = $data['channel_performance']['local']['revenue_percent'] ?? 0;

        if ($channelCount < 3 && $localPercent > 80) {
            $recommendations[] = [
                'type' => 'channel_diversification',
                'title' => 'Consider adding more sales channels',
                'description' => "{$localPercent}% of revenue comes from local sales. Adding more marketplace channels could reduce risk and increase reach.",
                'priority' => 'medium',
            ];
        }

        return $recommendations;
    }

    protected function formatChannelPerformance(array $channels): string
    {
        $lines = [];
        foreach ($channels as $channel) {
            $lines[] = "- {$channel['channel']}: \${$channel['revenue']} ({$channel['revenue_percent']}%)";
        }

        return implode("\n", $lines);
    }

    protected function formatTopProducts(array $products): string
    {
        $lines = [];
        foreach (array_slice($products, 0, 5) as $product) {
            $lines[] = "- {$product['title']}: \${$product['revenue']} ({$product['units_sold']} units)";
        }

        return implode("\n", $lines);
    }

    protected function formatDeclining(array $products): string
    {
        if (empty($products)) {
            return 'None';
        }

        return count($products).' products with declining sales';
    }

    public function canRun(StoreAgent $storeAgent): bool
    {
        return $storeAgent->canRun();
    }

    public function getSubscribedEvents(): array
    {
        return [
            'order.completed',
            'daily.summary',
            'weekly.summary',
        ];
    }

    public function handleEvent(string $event, array $payload, StoreAgent $storeAgent): void
    {
        // Could trigger immediate analysis on significant events
    }
}
