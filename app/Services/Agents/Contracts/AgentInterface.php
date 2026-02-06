<?php

namespace App\Services\Agents\Contracts;

use App\Enums\AgentType;
use App\Models\AgentRun;
use App\Models\StoreAgent;
use App\Services\Agents\Results\AgentRunResult;

interface AgentInterface
{
    /**
     * Get the agent's display name.
     */
    public function getName(): string;

    /**
     * Get the agent's unique slug identifier.
     */
    public function getSlug(): string;

    /**
     * Get the agent type (background, event_triggered, goal_oriented).
     */
    public function getType(): AgentType;

    /**
     * Get a description of what this agent does.
     */
    public function getDescription(): string;

    /**
     * Get the default configuration for this agent.
     */
    public function getDefaultConfig(): array;

    /**
     * Get the configuration schema for UI rendering.
     *
     * @return array<string, array{type: string, label: string, description?: string, default?: mixed, options?: array}>
     */
    public function getConfigSchema(): array;

    /**
     * Execute the agent's main logic.
     */
    public function run(AgentRun $run, StoreAgent $storeAgent): AgentRunResult;

    /**
     * Check if the agent can run for the given store configuration.
     */
    public function canRun(StoreAgent $storeAgent): bool;

    /**
     * Get the list of events this agent subscribes to (for event-triggered agents).
     *
     * @return array<string>
     */
    public function getSubscribedEvents(): array;

    /**
     * Handle an event (for event-triggered agents).
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleEvent(string $event, array $payload, StoreAgent $storeAgent): void;
}
