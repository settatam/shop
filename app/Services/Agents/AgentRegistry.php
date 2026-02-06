<?php

namespace App\Services\Agents;

use App\Models\Agent;
use App\Services\Agents\Contracts\AgentActionInterface;
use App\Services\Agents\Contracts\AgentInterface;
use InvalidArgumentException;

class AgentRegistry
{
    /**
     * @var array<string, class-string<AgentInterface>>
     */
    protected array $agents = [];

    /**
     * @var array<string, class-string<AgentActionInterface>>
     */
    protected array $actions = [];

    /**
     * @var array<string, array<string>>
     */
    protected array $eventSubscribers = [];

    /**
     * Register an agent implementation.
     *
     * @param  class-string<AgentInterface>  $agentClass
     */
    public function registerAgent(string $agentClass): void
    {
        $agent = app($agentClass);

        if (! $agent instanceof AgentInterface) {
            throw new InvalidArgumentException('Agent class must implement AgentInterface');
        }

        $slug = $agent->getSlug();
        $this->agents[$slug] = $agentClass;

        // Register event subscriptions
        foreach ($agent->getSubscribedEvents() as $event) {
            $this->eventSubscribers[$event][] = $slug;
        }
    }

    /**
     * Register an action implementation.
     *
     * @param  class-string<AgentActionInterface>  $actionClass
     */
    public function registerAction(string $actionClass): void
    {
        $action = app($actionClass);

        if (! $action instanceof AgentActionInterface) {
            throw new InvalidArgumentException('Action class must implement AgentActionInterface');
        }

        $this->actions[$action->getType()] = $actionClass;
    }

    /**
     * Get an agent implementation by slug.
     */
    public function getAgent(string $slug): ?AgentInterface
    {
        if (! isset($this->agents[$slug])) {
            return null;
        }

        return app($this->agents[$slug]);
    }

    /**
     * Get an action implementation by type.
     */
    public function getAction(string $type): ?AgentActionInterface
    {
        if (! isset($this->actions[$type])) {
            return null;
        }

        return app($this->actions[$type]);
    }

    /**
     * Get all registered agent slugs.
     *
     * @return array<string>
     */
    public function getRegisteredAgentSlugs(): array
    {
        return array_keys($this->agents);
    }

    /**
     * Get all registered action types.
     *
     * @return array<string>
     */
    public function getRegisteredActionTypes(): array
    {
        return array_keys($this->actions);
    }

    /**
     * Get agent slugs that subscribe to a specific event.
     *
     * @return array<string>
     */
    public function getAgentsForEvent(string $event): array
    {
        return $this->eventSubscribers[$event] ?? [];
    }

    /**
     * Get all agent implementations.
     *
     * @return array<AgentInterface>
     */
    public function getAllAgents(): array
    {
        return array_map(fn ($class) => app($class), $this->agents);
    }

    /**
     * Sync agent definitions to database.
     */
    public function syncToDatabase(): void
    {
        foreach ($this->getAllAgents() as $agent) {
            Agent::updateOrCreate(
                ['slug' => $agent->getSlug()],
                [
                    'name' => $agent->getName(),
                    'description' => $agent->getDescription(),
                    'type' => $agent->getType(),
                    'default_enabled' => true,
                    'default_config' => $agent->getDefaultConfig(),
                ]
            );
        }
    }
}
