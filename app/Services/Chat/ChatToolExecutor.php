<?php

namespace App\Services\Chat;

use App\Services\Chat\Tools\ChatToolInterface;
use App\Services\Chat\Tools\CustomerInsightsTool;
use App\Services\Chat\Tools\CustomerIntelligenceTool;
use App\Services\Chat\Tools\DeadStockTool;
use App\Services\Chat\Tools\DynamicQueryTool;
use App\Services\Chat\Tools\EndOfDayTool;
use App\Services\Chat\Tools\InventoryAlertsTool;
use App\Services\Chat\Tools\MarketPriceCheckTool;
use App\Services\Chat\Tools\MetalCalculatorTool;
use App\Services\Chat\Tools\MorningBriefingTool;
use App\Services\Chat\Tools\NegotiationCoachTool;
use App\Services\Chat\Tools\OrderStatusTool;
use App\Services\Chat\Tools\PendingActionsTool;
use App\Services\Chat\Tools\ProductLookupTool;
use App\Services\Chat\Tools\SalesReportTool;
use App\Services\Chat\Tools\SalesSummaryTool;
use App\Services\Chat\Tools\SpotPriceTool;
use App\Services\Chat\Tools\TopProductsTool;

class ChatToolExecutor
{
    /** @var array<string, ChatToolInterface> */
    protected array $tools = [];

    public function __construct()
    {
        $this->registerDefaultTools();
    }

    protected function registerDefaultTools(): void
    {
        // Store Manager Tools
        $this->register(new MorningBriefingTool);
        $this->register(new EndOfDayTool);
        $this->register(new SalesReportTool);
        $this->register(new SalesSummaryTool);

        // Customer Tools
        $this->register(new CustomerIntelligenceTool);
        $this->register(new CustomerInsightsTool);

        // Pricing & Negotiation Tools
        $this->register(new NegotiationCoachTool);
        $this->register(new MetalCalculatorTool);
        $this->register(new MarketPriceCheckTool);
        $this->register(new SpotPriceTool);

        // Inventory Tools
        $this->register(new InventoryAlertsTool);
        $this->register(new ProductLookupTool);
        $this->register(new DeadStockTool);
        $this->register(new TopProductsTool);

        // Operations Tools
        $this->register(new OrderStatusTool);
        $this->register(new PendingActionsTool);

        // Dynamic Query Tool (requires service container resolution)
        $this->register(app(DynamicQueryTool::class));
    }

    public function register(ChatToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    /**
     * Get all tool definitions for Claude API.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDefinitions(): array
    {
        return array_map(
            fn (ChatToolInterface $tool) => $tool->definition(),
            array_values($this->tools)
        );
    }

    /**
     * Execute a tool by name.
     *
     * @param  string  $name  Tool name
     * @param  array<string, mixed>  $params  Tool parameters
     * @param  int  $storeId  Store ID for scoping
     * @return array<string, mixed>
     */
    public function execute(string $name, array $params, int $storeId): array
    {
        if (! isset($this->tools[$name])) {
            return [
                'error' => "Unknown tool: {$name}",
                'available_tools' => array_keys($this->tools),
            ];
        }

        try {
            return $this->tools[$name]->execute($params, $storeId);
        } catch (\Throwable $e) {
            return [
                'error' => "Tool execution failed: {$e->getMessage()}",
            ];
        }
    }

    /**
     * Check if a tool exists.
     */
    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * Get a friendly description for a tool (for UI display).
     */
    public function getToolDescription(string $name): string
    {
        return match ($name) {
            // Store Manager
            'get_morning_briefing' => 'Preparing your morning briefing...',
            'get_end_of_day_report' => 'Running end of day report...',
            'get_sales_report' => 'Pulling sales report...',
            'get_sales_summary' => 'Checking sales data...',
            // Customers
            'get_customer_intelligence' => 'Looking up customer...',
            'get_customer_insights' => 'Reviewing customer data...',
            // Pricing & Negotiation
            'get_negotiation_advice' => 'Calculating offer...',
            'calculate_metal_value' => 'Calculating metal value...',
            'check_market_prices' => 'Checking market prices...',
            'get_spot_prices' => 'Fetching spot prices...',
            // Inventory
            'get_inventory_alerts' => 'Checking inventory...',
            'lookup_product' => 'Looking up product...',
            'get_dead_stock' => 'Analyzing slow-moving inventory...',
            'get_top_products' => 'Analyzing product performance...',
            // Operations
            'get_order_status' => 'Looking up orders...',
            'get_pending_actions' => 'Checking pending actions...',
            // Dynamic Query
            'run_dynamic_query' => 'Running custom query...',
            default => 'Processing...',
        };
    }
}
