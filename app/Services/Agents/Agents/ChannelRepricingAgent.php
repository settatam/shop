<?php

namespace App\Services\Agents\Agents;

use App\Enums\AgentType;
use App\Enums\Platform;
use App\Models\AgentAction;
use App\Models\AgentRun;
use App\Models\PlatformListing;
use App\Models\StoreAgent;
use App\Models\StoreMarketplace;
use App\Services\Agents\Contracts\AgentInterface;
use App\Services\Agents\Results\AgentRunResult;
use App\Services\AI\AIManager;
use App\Services\Marketplace\PlatformConnectorManager;

class ChannelRepricingAgent implements AgentInterface
{
    public function __construct(
        protected PlatformConnectorManager $connectorManager,
        protected AIManager $ai
    ) {}

    public function getName(): string
    {
        return 'Channel Repricing Agent';
    }

    public function getSlug(): string
    {
        return 'channel-repricing';
    }

    public function getType(): AgentType
    {
        return AgentType::Background;
    }

    public function getDescription(): string
    {
        return 'Optimizes pricing across all sales channels. Monitors competitor prices on each marketplace, adjusts prices dynamically, and protects margins while staying competitive.';
    }

    public function getDefaultConfig(): array
    {
        return [
            'enabled_platforms' => ['amazon', 'walmart', 'shopify'],
            'repricing_strategy' => 'smart', // smart, aggressive, conservative
            'min_margin_percent' => 15,
            'target_margin_percent' => 25,
            'max_price_reduction_percent' => 20,
            'match_lowest_competitor' => false,
            'amazon_buy_box_strategy' => true,
            'walmart_price_parity' => true,
            'update_frequency_hours' => 6,
            'max_items_per_run' => 100,
            'require_approval_for_major_changes' => true,
            'major_change_threshold_percent' => 15,
        ];
    }

    public function getConfigSchema(): array
    {
        return [
            'enabled_platforms' => [
                'type' => 'multiselect',
                'label' => 'Enabled Platforms',
                'description' => 'Platforms to optimize pricing on',
                'options' => [
                    'amazon' => 'Amazon',
                    'walmart' => 'Walmart',
                    'shopify' => 'Shopify',
                    'bigcommerce' => 'BigCommerce',
                    'ebay' => 'eBay',
                ],
                'default' => ['amazon', 'walmart'],
            ],
            'repricing_strategy' => [
                'type' => 'select',
                'label' => 'Repricing Strategy',
                'description' => 'Overall approach to pricing',
                'options' => [
                    'smart' => 'Smart (AI-optimized)',
                    'aggressive' => 'Aggressive (Maximize sales)',
                    'conservative' => 'Conservative (Protect margins)',
                ],
                'default' => 'smart',
            ],
            'min_margin_percent' => [
                'type' => 'number',
                'label' => 'Minimum Margin (%)',
                'description' => 'Never price below this margin',
                'default' => 15,
            ],
            'target_margin_percent' => [
                'type' => 'number',
                'label' => 'Target Margin (%)',
                'description' => 'Ideal margin to achieve',
                'default' => 25,
            ],
            'max_price_reduction_percent' => [
                'type' => 'number',
                'label' => 'Max Price Reduction (%)',
                'description' => 'Maximum allowed price reduction per cycle',
                'default' => 20,
            ],
            'amazon_buy_box_strategy' => [
                'type' => 'boolean',
                'label' => 'Amazon Buy Box Optimization',
                'description' => 'Optimize pricing to win the Buy Box',
                'default' => true,
            ],
            'walmart_price_parity' => [
                'type' => 'boolean',
                'label' => 'Walmart Price Parity',
                'description' => 'Maintain competitive pricing for Walmart',
                'default' => true,
            ],
        ];
    }

    public function run(AgentRun $run, StoreAgent $storeAgent): AgentRunResult
    {
        $config = $storeAgent->getMergedConfig();
        $storeId = $storeAgent->store_id;

        $enabledPlatforms = $config['enabled_platforms'] ?? ['amazon', 'walmart'];
        $maxItems = $config['max_items_per_run'] ?? 100;
        $strategy = $config['repricing_strategy'] ?? 'smart';

        $results = [
            'listings_analyzed' => 0,
            'price_changes_proposed' => 0,
            'by_platform' => [],
            'margin_protected' => 0,
            'buy_box_optimizations' => 0,
        ];

        $actionsCreated = 0;

        // Get active marketplaces
        $marketplaces = StoreMarketplace::where('store_id', $storeId)
            ->where('status', 'active')
            ->whereIn('platform', array_map(fn ($p) => Platform::from($p), $enabledPlatforms))
            ->get();

        foreach ($marketplaces as $marketplace) {
            $platformResults = [
                'analyzed' => 0,
                'changes' => 0,
            ];

            try {
                $listings = PlatformListing::where('store_marketplace_id', $marketplace->id)
                    ->whereIn('status', ['active', 'pending'])
                    ->with('product')
                    ->limit($maxItems)
                    ->get();

                foreach ($listings as $listing) {
                    $platformResults['analyzed']++;
                    $results['listings_analyzed']++;

                    $priceUpdate = $this->calculateOptimalPrice(
                        $listing,
                        $marketplace,
                        $config,
                        $strategy
                    );

                    if ($priceUpdate) {
                        $changePercent = abs(($priceUpdate['new_price'] - $listing->platform_price) / $listing->platform_price * 100);
                        $majorChangeThreshold = $config['major_change_threshold_percent'] ?? 15;

                        AgentAction::create([
                            'agent_run_id' => $run->id,
                            'store_id' => $storeId,
                            'action_type' => 'channel_reprice',
                            'actionable_type' => PlatformListing::class,
                            'actionable_id' => $listing->id,
                            'status' => 'pending',
                            'requires_approval' => ($config['require_approval_for_major_changes'] ?? true)
                                && $changePercent >= $majorChangeThreshold,
                            'payload' => [
                                'platform' => $marketplace->platform->value,
                                'listing_id' => $listing->id,
                                'product_id' => $listing->product_id,
                                'product_title' => $listing->product?->title,
                                'current_price' => $listing->platform_price,
                                'new_price' => $priceUpdate['new_price'],
                                'change_percent' => round($changePercent, 2),
                                'reason' => $priceUpdate['reason'],
                                'competitor_data' => $priceUpdate['competitor_data'] ?? null,
                                'margin_info' => $priceUpdate['margin_info'] ?? null,
                            ],
                        ]);

                        $actionsCreated++;
                        $platformResults['changes']++;
                        $results['price_changes_proposed']++;

                        if (isset($priceUpdate['margin_protected']) && $priceUpdate['margin_protected']) {
                            $results['margin_protected']++;
                        }

                        if (isset($priceUpdate['buy_box_optimized']) && $priceUpdate['buy_box_optimized']) {
                            $results['buy_box_optimizations']++;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $platformResults['error'] = $e->getMessage();
            }

            $results['by_platform'][$marketplace->platform->value] = $platformResults;
        }

        return AgentRunResult::success($results, $actionsCreated);
    }

    /**
     * Calculate optimal price for a listing.
     */
    protected function calculateOptimalPrice(
        PlatformListing $listing,
        StoreMarketplace $marketplace,
        array $config,
        string $strategy
    ): ?array {
        $product = $listing->product;

        if (! $product) {
            return null;
        }

        $currentPrice = $listing->platform_price;
        $cost = $product->cost ?? 0;
        $basePrice = $product->price;

        // Calculate margins
        $minMargin = $config['min_margin_percent'] ?? 15;
        $targetMargin = $config['target_margin_percent'] ?? 25;
        $maxReduction = $config['max_price_reduction_percent'] ?? 20;

        // Calculate floor price based on minimum margin
        $floorPrice = $cost > 0 ? $cost * (1 + $minMargin / 100) : $basePrice * 0.7;

        // Get competitive pricing data if available
        $competitorData = $this->getCompetitorPricing($listing, $marketplace);

        $newPrice = null;
        $reason = '';
        $marginProtected = false;
        $buyBoxOptimized = false;

        // Platform-specific strategies
        if ($marketplace->platform === Platform::Amazon && ($config['amazon_buy_box_strategy'] ?? true)) {
            $result = $this->optimizeForAmazonBuyBox($listing, $competitorData, $floorPrice, $strategy);
            if ($result) {
                $newPrice = $result['price'];
                $reason = $result['reason'];
                $buyBoxOptimized = true;
            }
        } elseif ($marketplace->platform === Platform::Walmart && ($config['walmart_price_parity'] ?? true)) {
            $result = $this->optimizeForWalmart($listing, $competitorData, $floorPrice, $basePrice, $strategy);
            if ($result) {
                $newPrice = $result['price'];
                $reason = $result['reason'];
            }
        } else {
            // General repricing strategy
            $result = $this->applyGeneralStrategy($listing, $competitorData, $floorPrice, $basePrice, $strategy);
            if ($result) {
                $newPrice = $result['price'];
                $reason = $result['reason'];
            }
        }

        if ($newPrice === null) {
            return null;
        }

        // Enforce floor price (margin protection)
        if ($newPrice < $floorPrice) {
            $newPrice = $floorPrice;
            $reason .= ' (adjusted to protect minimum margin)';
            $marginProtected = true;
        }

        // Apply max reduction limit
        $minAllowed = $currentPrice * (1 - $maxReduction / 100);
        if ($newPrice < $minAllowed) {
            $newPrice = $minAllowed;
            $reason .= ' (limited by max reduction cap)';
        }

        // Round to 2 decimal places
        $newPrice = round($newPrice, 2);

        // Skip if no significant change
        if (abs($newPrice - $currentPrice) < 0.01) {
            return null;
        }

        // Calculate margin info
        $marginInfo = null;
        if ($cost > 0) {
            $marginInfo = [
                'cost' => $cost,
                'current_margin_percent' => round((($currentPrice - $cost) / $currentPrice) * 100, 2),
                'new_margin_percent' => round((($newPrice - $cost) / $newPrice) * 100, 2),
            ];
        }

        return [
            'new_price' => $newPrice,
            'reason' => $reason,
            'competitor_data' => $competitorData,
            'margin_info' => $marginInfo,
            'margin_protected' => $marginProtected,
            'buy_box_optimized' => $buyBoxOptimized,
        ];
    }

    /**
     * Get competitor pricing for a listing.
     */
    protected function getCompetitorPricing(PlatformListing $listing, StoreMarketplace $marketplace): ?array
    {
        // This would integrate with competitive intelligence APIs
        // For now, return mock data structure
        // In production, this would call Amazon SP-API, Walmart API, etc.

        return [
            'lowest_price' => null,
            'buy_box_price' => null,
            'average_price' => null,
            'seller_count' => null,
        ];
    }

    /**
     * Optimize pricing for Amazon Buy Box.
     */
    protected function optimizeForAmazonBuyBox(
        PlatformListing $listing,
        ?array $competitorData,
        float $floorPrice,
        string $strategy
    ): ?array {
        $currentPrice = $listing->platform_price;
        $buyBoxPrice = $competitorData['buy_box_price'] ?? null;
        $lowestPrice = $competitorData['lowest_price'] ?? null;

        if (! $buyBoxPrice && ! $lowestPrice) {
            return null;
        }

        $targetPrice = $buyBoxPrice ?? $lowestPrice;

        switch ($strategy) {
            case 'aggressive':
                // Match or beat the Buy Box price
                $newPrice = min($currentPrice, $targetPrice - 0.01);
                $reason = 'Aggressive Buy Box competition';
                break;

            case 'conservative':
                // Only adjust if significantly higher than Buy Box
                if ($currentPrice > $targetPrice * 1.05) {
                    $newPrice = $targetPrice;
                    $reason = 'Conservative Buy Box alignment';
                } else {
                    return null;
                }
                break;

            case 'smart':
            default:
                // Smart: consider multiple factors
                if ($currentPrice > $targetPrice * 1.03) {
                    // Price down to compete
                    $newPrice = $targetPrice;
                    $reason = 'Smart Buy Box optimization - price reduction';
                } elseif ($currentPrice < $targetPrice * 0.95) {
                    // We're too low, raise price
                    $newPrice = $targetPrice * 0.99;
                    $reason = 'Smart Buy Box optimization - price increase';
                } else {
                    return null;
                }
                break;
        }

        return [
            'price' => max($floorPrice, $newPrice),
            'reason' => $reason,
        ];
    }

    /**
     * Optimize pricing for Walmart.
     */
    protected function optimizeForWalmart(
        PlatformListing $listing,
        ?array $competitorData,
        float $floorPrice,
        float $basePrice,
        string $strategy
    ): ?array {
        $currentPrice = $listing->platform_price;

        // Walmart prefers price parity with other channels
        // Ensure we're competitive but not too far below our base price

        $targetPrice = $basePrice;

        if ($currentPrice > $targetPrice * 1.05) {
            return [
                'price' => max($floorPrice, $targetPrice),
                'reason' => 'Walmart price parity alignment',
            ];
        } elseif ($currentPrice < $targetPrice * 0.90) {
            return [
                'price' => $targetPrice * 0.95,
                'reason' => 'Walmart price increase to maintain parity',
            ];
        }

        return null;
    }

    /**
     * Apply general repricing strategy.
     */
    protected function applyGeneralStrategy(
        PlatformListing $listing,
        ?array $competitorData,
        float $floorPrice,
        float $basePrice,
        string $strategy
    ): ?array {
        $currentPrice = $listing->platform_price;

        // Sync platform price with base price if significantly different
        $priceDiff = abs($currentPrice - $basePrice) / $basePrice * 100;

        if ($priceDiff > 10) {
            return [
                'price' => max($floorPrice, $basePrice),
                'reason' => 'Price sync with base price',
            ];
        }

        return null;
    }

    public function canRun(StoreAgent $storeAgent): bool
    {
        if (! $storeAgent->canRun()) {
            return false;
        }

        // Check if store has any marketplace connections
        return StoreMarketplace::where('store_id', $storeAgent->store_id)
            ->where('status', 'active')
            ->exists();
    }

    public function getSubscribedEvents(): array
    {
        return [
            'competitor.price_changed',
            'product.cost_updated',
            'listing.buy_box_lost',
        ];
    }

    public function handleEvent(string $event, array $payload, StoreAgent $storeAgent): void
    {
        // Could trigger immediate repricing on Buy Box loss
    }
}
