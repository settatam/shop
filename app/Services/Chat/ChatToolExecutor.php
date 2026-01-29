<?php

namespace App\Services\Chat;

use App\Services\Chat\Tools\ChatToolInterface;
use App\Services\Chat\Tools\CustomerInsightsTool;
use App\Services\Chat\Tools\InventoryAlertsTool;
use App\Services\Chat\Tools\OrderStatusTool;
use App\Services\Chat\Tools\SalesSummaryTool;
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
        $this->register(new SalesSummaryTool);
        $this->register(new OrderStatusTool);
        $this->register(new InventoryAlertsTool);
        $this->register(new TopProductsTool);
        $this->register(new CustomerInsightsTool);
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
            'get_sales_summary' => 'Checking sales data...',
            'get_order_status' => 'Looking up orders...',
            'get_inventory_alerts' => 'Checking inventory...',
            'get_top_products' => 'Analyzing product performance...',
            'get_customer_insights' => 'Reviewing customer data...',
            default => 'Processing...',
        };
    }
}
