<?php

namespace App\Services\StorefrontChat\Tools;

use App\Models\Store;
use App\Models\StoreKnowledgeBaseEntry;
use App\Services\Chat\Tools\ChatToolInterface;

class StorefrontStoreInfoTool implements ChatToolInterface
{
    public function name(): string
    {
        return 'get_store_info';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Get store information such as return policy, shipping details, care instructions, FAQ, or about the store. Use when a customer asks about store policies, shipping, returns, or general store information.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'topic' => [
                        'type' => 'string',
                        'description' => 'The topic to look up. One of: return_policy, shipping_info, care_instructions, faq, about, all',
                        'enum' => ['return_policy', 'shipping_info', 'care_instructions', 'faq', 'about', 'all'],
                    ],
                ],
                'required' => ['topic'],
            ],
        ];
    }

    public function execute(array $params, int $storeId): array
    {
        $topic = $params['topic'] ?? 'all';

        $query = StoreKnowledgeBaseEntry::where('store_id', $storeId)
            ->where('is_active', true)
            ->orderBy('sort_order');

        if ($topic !== 'all') {
            $query->where('type', $topic);
        }

        $entries = $query->get();

        if ($entries->isEmpty()) {
            // Fall back to basic store info
            $store = Store::find($storeId);

            return [
                'found' => false,
                'message' => 'No specific information available for this topic.',
                'store' => $store ? [
                    'name' => $store->name,
                ] : null,
            ];
        }

        $grouped = $entries->groupBy('type')->map(function ($group) {
            return $group->map(fn (StoreKnowledgeBaseEntry $entry) => [
                'title' => $entry->title,
                'content' => $entry->content,
            ])->toArray();
        })->toArray();

        return [
            'found' => true,
            'entries' => $grouped,
        ];
    }
}
