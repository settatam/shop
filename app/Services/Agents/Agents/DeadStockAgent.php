<?php

namespace App\Services\Agents\Agents;

use App\Enums\AgentType;
use App\Models\AgentAction;
use App\Models\AgentRun;
use App\Models\Product;
use App\Models\StoreAgent;
use App\Services\Agents\Contracts\AgentInterface;
use App\Services\Agents\Results\AgentRunResult;
use Illuminate\Support\Carbon;

class DeadStockAgent implements AgentInterface
{
    public function getName(): string
    {
        return 'Dead Stock Agent';
    }

    public function getSlug(): string
    {
        return 'dead-stock';
    }

    public function getType(): AgentType
    {
        return AgentType::Background;
    }

    public function getDescription(): string
    {
        return 'Identifies slow-moving inventory and schedules progressive markdowns to improve turnover.';
    }

    public function getDefaultConfig(): array
    {
        return [
            'run_frequency' => 'weekly',
            'slow_mover_threshold_days' => 90,
            'dead_stock_threshold_days' => 180,
            'markdown_schedule' => [
                ['days' => 90, 'discount_percent' => 10],
                ['days' => 120, 'discount_percent' => 20],
                ['days' => 150, 'discount_percent' => 30],
                ['days' => 180, 'discount_percent' => 40],
            ],
            'exclude_categories' => [],
            'min_value_threshold' => 25,
            'max_items_per_run' => 100,
        ];
    }

    public function getConfigSchema(): array
    {
        return [
            'run_frequency' => [
                'type' => 'select',
                'label' => 'Run Frequency',
                'description' => 'How often to check for dead stock',
                'options' => [
                    'daily' => 'Daily',
                    'weekly' => 'Weekly',
                    'monthly' => 'Monthly',
                ],
                'default' => 'weekly',
            ],
            'slow_mover_threshold_days' => [
                'type' => 'number',
                'label' => 'Slow Mover Threshold (Days)',
                'description' => 'Days without sale before item is considered slow-moving',
                'default' => 90,
            ],
            'dead_stock_threshold_days' => [
                'type' => 'number',
                'label' => 'Dead Stock Threshold (Days)',
                'description' => 'Days without sale before item is considered dead stock',
                'default' => 180,
            ],
            'min_value_threshold' => [
                'type' => 'number',
                'label' => 'Minimum Value Threshold',
                'description' => 'Minimum product price to consider for markdowns',
                'default' => 25,
            ],
            'max_items_per_run' => [
                'type' => 'number',
                'label' => 'Maximum Items Per Run',
                'description' => 'Limit the number of products to process per run',
                'default' => 100,
            ],
        ];
    }

    public function run(AgentRun $run, StoreAgent $storeAgent): AgentRunResult
    {
        $config = $storeAgent->getMergedConfig();
        $storeId = $storeAgent->store_id;

        $slowMoverThreshold = Carbon::now()->subDays($config['slow_mover_threshold_days'] ?? 90);
        $deadStockThreshold = Carbon::now()->subDays($config['dead_stock_threshold_days'] ?? 180);
        $minValue = $config['min_value_threshold'] ?? 25;
        $maxItems = $config['max_items_per_run'] ?? 100;
        $excludeCategories = $config['exclude_categories'] ?? [];
        $markdownSchedule = $config['markdown_schedule'] ?? [];

        // Find slow-moving products
        $query = Product::forStore($storeId)
            ->where('status', 'active')
            ->where('price', '>=', $minValue)
            ->where('created_at', '<=', $slowMoverThreshold)
            ->whereDoesntHave('orderItems', function ($q) use ($slowMoverThreshold) {
                $q->where('created_at', '>=', $slowMoverThreshold);
            })
            ->limit($maxItems);

        if (! empty($excludeCategories)) {
            $query->whereNotIn('category_id', $excludeCategories);
        }

        $products = $query->get();

        $slowMovers = 0;
        $deadStock = 0;
        $actionsCreated = 0;

        foreach ($products as $product) {
            $daysSinceCreated = Carbon::parse($product->created_at)->diffInDays(now());
            $isDeadStock = $daysSinceCreated >= ($config['dead_stock_threshold_days'] ?? 180);

            if ($isDeadStock) {
                $deadStock++;
            } else {
                $slowMovers++;
            }

            // Determine appropriate markdown
            $discountPercent = $this->calculateDiscount($daysSinceCreated, $markdownSchedule);

            if ($discountPercent > 0) {
                $newPrice = round($product->price * (1 - $discountPercent / 100), 2);

                AgentAction::create([
                    'agent_run_id' => $run->id,
                    'store_id' => $storeId,
                    'action_type' => 'markdown_schedule',
                    'actionable_type' => Product::class,
                    'actionable_id' => $product->id,
                    'status' => 'pending',
                    'requires_approval' => $storeAgent->requiresApproval() || $discountPercent >= 30,
                    'payload' => [
                        'before' => ['price' => $product->price],
                        'after' => ['price' => $newPrice],
                        'discount_percent' => $discountPercent,
                        'days_since_created' => $daysSinceCreated,
                        'reason' => $isDeadStock ? 'dead_stock' : 'slow_mover',
                        'reasoning' => $this->generateReasoning($product, $daysSinceCreated, $discountPercent),
                    ],
                ]);

                $actionsCreated++;
            }
        }

        return AgentRunResult::success([
            'products_analyzed' => $products->count(),
            'slow_movers_found' => $slowMovers,
            'dead_stock_found' => $deadStock,
            'markdowns_proposed' => $actionsCreated,
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

    protected function calculateDiscount(int $days, array $schedule): int
    {
        // Sort schedule by days descending to find the highest applicable discount
        usort($schedule, fn ($a, $b) => $b['days'] <=> $a['days']);

        foreach ($schedule as $tier) {
            if ($days >= $tier['days']) {
                return $tier['discount_percent'];
            }
        }

        return 0;
    }

    protected function generateReasoning(Product $product, int $daysSinceCreated, int $discountPercent): string
    {
        return "Product '{$product->title}' (SKU: {$product->sku}) has been in inventory for {$daysSinceCreated} days without a sale. "
            ."Based on the configured markdown schedule, a {$discountPercent}% discount is recommended to improve turnover.";
    }
}
