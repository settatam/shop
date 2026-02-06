<?php

namespace App\Services\Agents\Agents;

use App\Enums\AgentType;
use App\Models\AgentAction;
use App\Models\AgentRun;
use App\Models\Customer;
use App\Models\StoreAgent;
use App\Models\TransactionItem;
use App\Services\Agents\Contracts\AgentInterface;
use App\Services\Agents\Results\AgentRunResult;
use App\Services\AI\TransactionItemResearcher;
use App\Services\Search\WebPriceSearchService;
use App\Services\SimilarItemFinder;

class NewItemResearcherAgent implements AgentInterface
{
    public function __construct(
        protected TransactionItemResearcher $researcher,
        protected WebPriceSearchService $priceSearchService,
        protected SimilarItemFinder $similarItemFinder,
    ) {}

    public function getName(): string
    {
        return 'New Item Researcher';
    }

    public function getSlug(): string
    {
        return 'new-item-researcher';
    }

    public function getType(): AgentType
    {
        return AgentType::EventTriggered;
    }

    public function getDescription(): string
    {
        return 'Automatically researches new items when added to inventory, finds market prices, and notifies interested customers.';
    }

    public function getDefaultConfig(): array
    {
        return [
            'auto_research' => true,
            'auto_generate_listing' => false,
            'notify_interested_customers' => true,
            'research_depth' => 'comprehensive', // quick, standard, comprehensive
            'platforms_to_check' => ['ebay', 'google_shopping'],
        ];
    }

    public function getConfigSchema(): array
    {
        return [
            'auto_research' => [
                'type' => 'boolean',
                'label' => 'Auto Research',
                'description' => 'Automatically research new items when added',
                'default' => true,
            ],
            'auto_generate_listing' => [
                'type' => 'boolean',
                'label' => 'Auto Generate Listing',
                'description' => 'Automatically generate listing content for new items',
                'default' => false,
            ],
            'notify_interested_customers' => [
                'type' => 'boolean',
                'label' => 'Notify Interested Customers',
                'description' => 'Notify customers who have expressed interest in similar items',
                'default' => true,
            ],
            'research_depth' => [
                'type' => 'select',
                'label' => 'Research Depth',
                'description' => 'How comprehensive the research should be',
                'options' => [
                    'quick' => 'Quick (Basic info only)',
                    'standard' => 'Standard (Market prices + basic research)',
                    'comprehensive' => 'Comprehensive (Full AI analysis + customer matching)',
                ],
                'default' => 'comprehensive',
            ],
        ];
    }

    public function run(AgentRun $run, StoreAgent $storeAgent): AgentRunResult
    {
        // This agent is primarily event-triggered
        // Manual runs can process recent unresearched items
        $config = $storeAgent->getMergedConfig();
        $storeId = $storeAgent->store_id;

        $items = TransactionItem::whereHas('transaction', function ($q) use ($storeId) {
            $q->where('store_id', $storeId);
        })
            ->whereNull('ai_research')
            ->where('status', 'ready_for_inventory')
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        $processed = 0;
        $actionsCreated = 0;

        foreach ($items as $item) {
            $result = $this->processItem($item, $run, $storeAgent, $config);
            $processed++;
            $actionsCreated += $result['actions_created'];
        }

        return AgentRunResult::success([
            'items_processed' => $processed,
            'notifications_created' => $actionsCreated,
        ], $actionsCreated);
    }

    public function canRun(StoreAgent $storeAgent): bool
    {
        return $storeAgent->canRun();
    }

    public function getSubscribedEvents(): array
    {
        return [
            'transaction_item.ready_for_inventory',
            'product.created',
        ];
    }

    public function handleEvent(string $event, array $payload, StoreAgent $storeAgent): void
    {
        $config = $storeAgent->getMergedConfig();

        if ($event === 'transaction_item.ready_for_inventory') {
            $itemId = $payload['transaction_item_id'] ?? null;

            if (! $itemId) {
                return;
            }

            $item = TransactionItem::find($itemId);

            if (! $item) {
                return;
            }

            // Create a run for this event
            $run = $this->createEventRun($storeAgent, $event, $payload);

            $this->processItem($item, $run, $storeAgent, $config);

            $run->complete([
                'event' => $event,
                'item_id' => $itemId,
            ]);
        }
    }

    protected function createEventRun(StoreAgent $storeAgent, string $event, array $payload): AgentRun
    {
        return AgentRun::create([
            'store_id' => $storeAgent->store_id,
            'agent_id' => $storeAgent->agent_id,
            'store_agent_id' => $storeAgent->id,
            'status' => 'running',
            'started_at' => now(),
            'trigger_type' => 'event',
            'trigger_data' => ['event' => $event, 'payload' => $payload],
        ]);
    }

    protected function processItem(TransactionItem $item, AgentRun $run, StoreAgent $storeAgent, array $config): array
    {
        $actionsCreated = 0;
        $storeId = $storeAgent->store_id;

        // Perform AI research if enabled
        if ($config['auto_research'] ?? true) {
            $research = $this->researcher->generateResearch($item);

            if (! isset($research['error'])) {
                $item->update(['ai_research' => $research]);
            }
        }

        // Get market pricing
        $criteria = $this->buildSearchCriteria($item);
        $priceResults = $this->priceSearchService->searchPrices($storeId, $criteria);

        if (! isset($priceResults['error'])) {
            $item->update([
                'market_price_data' => [
                    'summary' => $priceResults['summary'],
                    'searched_at' => $priceResults['searched_at'],
                    'query' => $priceResults['query'],
                ],
            ]);
        }

        // Find and notify interested customers if enabled
        if ($config['notify_interested_customers'] ?? true) {
            $interestedCustomers = $this->findInterestedCustomers($item, $storeId);

            foreach ($interestedCustomers as $customer) {
                AgentAction::create([
                    'agent_run_id' => $run->id,
                    'store_id' => $storeId,
                    'action_type' => 'send_notification',
                    'actionable_type' => Customer::class,
                    'actionable_id' => $customer->id,
                    'status' => 'pending',
                    'requires_approval' => $storeAgent->requiresApproval(),
                    'payload' => [
                        'notification_type' => 'new_item_match',
                        'subject' => 'New Item You Might Like',
                        'message' => $this->buildNotificationMessage($item, $customer),
                        'item_id' => $item->id,
                        'reasoning' => 'Customer has previously purchased or shown interest in similar items.',
                    ],
                ]);

                $actionsCreated++;
            }
        }

        return ['actions_created' => $actionsCreated];
    }

    protected function buildSearchCriteria(TransactionItem $item): array
    {
        $criteria = [];

        if ($item->title) {
            $criteria['title'] = $item->title;
        } elseif ($item->description) {
            // Use first 50 chars of description
            $criteria['title'] = substr($item->description, 0, 50);
        }

        if ($item->category) {
            $criteria['category'] = $item->category->name;
        }

        $attributes = $item->attributes ?? [];
        if (! empty($attributes)) {
            $criteria['attributes'] = $attributes;
        }

        return $criteria;
    }

    protected function findInterestedCustomers(TransactionItem $item, int $storeId): array
    {
        // Use SimilarItemFinder logic to find customers who bought similar items
        // This is a simplified implementation - could be enhanced with more sophisticated matching

        $category = $item->category;

        if (! $category) {
            return [];
        }

        // Find customers who previously purchased from this category
        $customers = Customer::forStore($storeId)
            ->whereHas('orders', function ($q) use ($category) {
                $q->whereHas('items', function ($q2) use ($category) {
                    $q2->whereHas('product', function ($q3) use ($category) {
                        $q3->where('category_id', $category->id);
                    });
                });
            })
            ->where('receives_marketing', true)
            ->limit(10)
            ->get();

        return $customers->all();
    }

    protected function buildNotificationMessage(TransactionItem $item, Customer $customer): string
    {
        $itemTitle = $item->title ?? 'a new item';

        return "Hi {$customer->first_name}, we just received {$itemTitle} that we think you'll love! "
            .'Based on your previous purchases, this might be perfect for you. '
            .'Stop by or reply to this message to learn more.';
    }
}
