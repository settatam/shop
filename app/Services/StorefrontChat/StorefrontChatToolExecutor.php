<?php

namespace App\Services\StorefrontChat;

use App\Services\Chat\Tools\ChatToolInterface;
use App\Services\StorefrontChat\Tools\StorefrontAvailabilityTool;
use App\Services\StorefrontChat\Tools\StorefrontProductCompareTool;
use App\Services\StorefrontChat\Tools\StorefrontProductDetailTool;
use App\Services\StorefrontChat\Tools\StorefrontProductSearchTool;
use App\Services\StorefrontChat\Tools\StorefrontStoreInfoTool;

class StorefrontChatToolExecutor
{
    /** @var array<string, ChatToolInterface> */
    protected array $tools = [];

    public function __construct()
    {
        $this->register(new StorefrontProductSearchTool);
        $this->register(new StorefrontProductDetailTool);
        $this->register(new StorefrontAvailabilityTool);
        $this->register(new StorefrontStoreInfoTool);
        $this->register(new StorefrontProductCompareTool);
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
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function execute(string $name, array $params, int $storeId): array
    {
        if (! isset($this->tools[$name])) {
            return [
                'error' => "Unknown tool: {$name}",
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
     * Get a friendly description for a tool (for UI display).
     */
    public function getToolDescription(string $name): string
    {
        return match ($name) {
            'search_products' => 'Searching products...',
            'get_product_details' => 'Getting product details...',
            'check_availability' => 'Checking availability...',
            'get_store_info' => 'Looking up store information...',
            'compare_products' => 'Comparing products...',
            default => 'Processing...',
        };
    }
}
