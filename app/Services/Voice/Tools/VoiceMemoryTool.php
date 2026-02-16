<?php

namespace App\Services\Voice\Tools;

use App\Models\VoiceMemory;
use App\Services\Chat\Tools\ChatToolInterface;
use App\Services\Voice\VoiceMemoryService;

class VoiceMemoryTool implements ChatToolInterface
{
    public function __construct(
        protected VoiceMemoryService $memoryService
    ) {}

    public function name(): string
    {
        return 'voice_memory';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Store or recall business memories and facts. Use when the user says things like "remember that...", "what\'s our policy on...", "what did I say about...", or when you learn important business information that should be remembered for future conversations.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'enum' => ['store', 'search', 'recall'],
                        'description' => 'The action to perform: store (save new memory), search (find relevant memories), or recall (get all memories in a category)',
                    ],
                    'content' => [
                        'type' => 'string',
                        'description' => 'For store: the fact or preference to remember. For search: the search query.',
                    ],
                    'memory_type' => [
                        'type' => 'string',
                        'enum' => ['fact', 'preference', 'context'],
                        'description' => 'Type of memory. fact: business facts, preference: user/customer preferences, context: situational context.',
                    ],
                    'category' => [
                        'type' => 'string',
                        'enum' => ['pricing', 'customers', 'inventory', 'operations', 'gold', 'silver', 'general'],
                        'description' => 'Category of the memory for organization.',
                    ],
                ],
                'required' => ['action'],
            ],
        ];
    }

    public function execute(array $params, int $storeId): array
    {
        $action = $params['action'] ?? 'search';

        return match ($action) {
            'store' => $this->storeMemory($params, $storeId),
            'search' => $this->searchMemories($params, $storeId),
            'recall' => $this->recallMemories($params, $storeId),
            default => ['success' => false, 'error' => 'Unknown action: '.$action],
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function storeMemory(array $params, int $storeId): array
    {
        $content = $params['content'] ?? null;

        if (! $content || strlen($content) < 5) {
            return [
                'success' => false,
                'error' => 'Content is required and must be at least 5 characters.',
            ];
        }

        $memory = $this->memoryService->remember(
            storeId: $storeId,
            content: $content,
            type: $params['memory_type'] ?? 'fact',
            category: $params['category'] ?? null,
            confidence: 1.0
        );

        return [
            'success' => true,
            'message' => 'Got it, I\'ll remember that.',
            'memory_id' => $memory->id,
            'stored' => [
                'content' => $memory->content,
                'type' => $memory->memory_type,
                'category' => $memory->category,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function searchMemories(array $params, int $storeId): array
    {
        $query = $params['content'] ?? '';

        if (strlen($query) < 2) {
            return [
                'success' => false,
                'error' => 'Search query is too short.',
            ];
        }

        $memories = $this->memoryService->search($storeId, $query, 5);

        if ($memories->isEmpty()) {
            return [
                'success' => true,
                'found' => false,
                'message' => 'I don\'t have any memories matching that.',
                'memories' => [],
            ];
        }

        return [
            'success' => true,
            'found' => true,
            'count' => $memories->count(),
            'memories' => $memories->map(fn (VoiceMemory $m) => [
                'id' => $m->id,
                'content' => $m->content,
                'type' => $m->memory_type,
                'category' => $m->category,
                'confidence' => $m->confidence,
                'stored_at' => $m->created_at->diffForHumans(),
            ])->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function recallMemories(array $params, int $storeId): array
    {
        $category = $params['category'] ?? null;
        $memories = $this->memoryService->recall($storeId, $category, 10);

        if ($memories->isEmpty()) {
            $categoryText = $category ? " in the {$category} category" : '';

            return [
                'success' => true,
                'found' => false,
                'message' => "I don't have any memories stored{$categoryText}.",
                'memories' => [],
            ];
        }

        return [
            'success' => true,
            'found' => true,
            'category' => $category,
            'count' => $memories->count(),
            'memories' => $memories->map(fn (VoiceMemory $m) => [
                'id' => $m->id,
                'content' => $m->content,
                'type' => $m->memory_type,
                'category' => $m->category,
                'confidence' => $m->confidence,
                'stored_at' => $m->created_at->diffForHumans(),
            ])->toArray(),
        ];
    }
}
