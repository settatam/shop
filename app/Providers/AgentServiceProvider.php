<?php

namespace App\Providers;

use App\Services\Agents\ActionExecutor;
use App\Services\Agents\Actions\ChannelRepriceAction;
use App\Services\Agents\Actions\CreateListingAction;
use App\Services\Agents\Actions\MarkdownScheduleAction;
use App\Services\Agents\Actions\PriceUpdateAction;
use App\Services\Agents\Actions\SendNotificationAction;
use App\Services\Agents\Actions\SyncInventoryAction;
use App\Services\Agents\Actions\SyncOrderAction;
use App\Services\Agents\Actions\UpdateListingAction;
use App\Services\Agents\AgentOrchestrator;
use App\Services\Agents\AgentRegistry;
use App\Services\Agents\AgentRunner;
use App\Services\Agents\Agents\AutoPricingAgent;
use App\Services\Agents\Agents\ChannelRepricingAgent;
use App\Services\Agents\Agents\ChannelSyncAgent;
use App\Services\Agents\Agents\DeadStockAgent;
use App\Services\Agents\Agents\NewItemResearcherAgent;
use App\Services\Agents\Agents\ProductListingAgent;
use App\Services\Agents\Agents\SalesIntelligenceAgent;
use App\Services\Agents\DigestGenerator;
use Illuminate\Support\ServiceProvider;

class AgentServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register the registry as a singleton
        $this->app->singleton(AgentRegistry::class, function ($app) {
            return new AgentRegistry;
        });

        // Register other services
        $this->app->singleton(ActionExecutor::class, function ($app) {
            return new ActionExecutor(
                $app->make(AgentRegistry::class)
            );
        });

        $this->app->singleton(AgentRunner::class, function ($app) {
            return new AgentRunner(
                $app->make(AgentRegistry::class),
                $app->make(ActionExecutor::class)
            );
        });

        $this->app->singleton(AgentOrchestrator::class, function ($app) {
            return new AgentOrchestrator(
                $app->make(AgentRegistry::class),
                $app->make(AgentRunner::class)
            );
        });

        $this->app->singleton(DigestGenerator::class, function ($app) {
            return new DigestGenerator;
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $registry = $this->app->make(AgentRegistry::class);

        // Register agents
        $this->registerAgents($registry);

        // Register actions
        $this->registerActions($registry);
    }

    protected function registerAgents(AgentRegistry $registry): void
    {
        $agents = [
            DeadStockAgent::class,
            AutoPricingAgent::class,
            NewItemResearcherAgent::class,
            ProductListingAgent::class,
            ChannelSyncAgent::class,
            SalesIntelligenceAgent::class,
            ChannelRepricingAgent::class,
        ];

        foreach ($agents as $agentClass) {
            if (class_exists($agentClass)) {
                $registry->registerAgent($agentClass);
            }
        }
    }

    protected function registerActions(AgentRegistry $registry): void
    {
        $actions = [
            PriceUpdateAction::class,
            MarkdownScheduleAction::class,
            SendNotificationAction::class,
            CreateListingAction::class,
            UpdateListingAction::class,
            SyncInventoryAction::class,
            SyncOrderAction::class,
            ChannelRepriceAction::class,
        ];

        foreach ($actions as $actionClass) {
            if (class_exists($actionClass)) {
                $registry->registerAction($actionClass);
            }
        }
    }
}
