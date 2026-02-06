<?php

namespace App\Services\Agents\Contracts;

use App\Models\AgentAction;
use App\Models\StoreAgent;
use App\Services\Agents\Results\ActionResult;

interface AgentActionInterface
{
    /**
     * Get the action type identifier.
     */
    public function getType(): string;

    /**
     * Get a human-readable description of this action type.
     */
    public function getDescription(): string;

    /**
     * Determine if this action requires approval based on store config and payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function requiresApproval(StoreAgent $storeAgent, array $payload): bool;

    /**
     * Execute the action.
     */
    public function execute(AgentAction $action): ActionResult;

    /**
     * Attempt to rollback the action (if supported).
     */
    public function rollback(AgentAction $action): bool;

    /**
     * Validate the action payload before execution.
     *
     * @param  array<string, mixed>  $payload
     */
    public function validatePayload(array $payload): bool;
}
