<?php

namespace App\Services\Chat\Tools;

interface ChatToolInterface
{
    /**
     * Get the tool name (used in API calls).
     */
    public function name(): string;

    /**
     * Get the tool definition for Claude's tool_use.
     */
    public function definition(): array;

    /**
     * Execute the tool with given parameters.
     *
     * @param  array<string, mixed>  $params  Tool parameters from Claude
     * @param  int  $storeId  Current store ID for scoping queries
     * @return array<string, mixed> Tool execution result
     */
    public function execute(array $params, int $storeId): array;
}
