<?php

namespace App\Services\Voice;

use App\Models\VoiceCommitment;
use App\Models\VoiceMemory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class VoiceMemoryService
{
    /**
     * Store a new memory from a voice conversation.
     */
    public function remember(
        int $storeId,
        string $content,
        string $type = 'fact',
        ?string $category = null,
        float $confidence = 1.0,
        ?string $sourceId = null
    ): VoiceMemory {
        return VoiceMemory::remember(
            storeId: $storeId,
            content: $content,
            type: $type,
            category: $category,
            confidence: $confidence,
            source: 'voice_conversation',
            sourceId: $sourceId
        );
    }

    /**
     * Search for relevant memories based on a query.
     */
    public function search(int $storeId, string $query, int $limit = 5): Collection
    {
        try {
            return VoiceMemory::findRelevant($storeId, $query, $limit);
        } catch (\Exception $e) {
            // Fallback to basic search if full-text search fails
            Log::warning('Full-text memory search failed, using fallback', [
                'error' => $e->getMessage(),
            ]);

            return VoiceMemory::forStore($storeId)
                ->active()
                ->where('content', 'like', '%'.trim($query).'%')
                ->orderByDesc('confidence')
                ->limit($limit)
                ->get();
        }
    }

    /**
     * Recall memories by category.
     */
    public function recall(int $storeId, ?string $category = null, int $limit = 10): Collection
    {
        return VoiceMemory::recall($storeId, $category, $limit);
    }

    /**
     * Get memories to inject into conversation context.
     *
     * @return array{facts: array, preferences: array}
     */
    public function getContextMemories(int $storeId, ?string $relevantQuery = null): array
    {
        $memories = [
            'facts' => [],
            'preferences' => [],
        ];

        // Get high-confidence preferences (always include)
        $preferences = VoiceMemory::forStore($storeId)
            ->active()
            ->ofType('preference')
            ->highConfidence(0.8)
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get();

        $memories['preferences'] = $preferences->pluck('content')->toArray();

        // Get relevant facts based on query
        if ($relevantQuery) {
            $relevantFacts = $this->search($storeId, $relevantQuery, 5);
            $memories['facts'] = $relevantFacts->pluck('content')->toArray();
        } else {
            // Get recent high-confidence facts
            $recentFacts = VoiceMemory::forStore($storeId)
                ->active()
                ->ofType('fact')
                ->highConfidence(0.8)
                ->orderByDesc('updated_at')
                ->limit(5)
                ->get();

            $memories['facts'] = $recentFacts->pluck('content')->toArray();
        }

        return $memories;
    }

    /**
     * Create a commitment/follow-up from a voice conversation.
     */
    public function createCommitment(
        int $storeId,
        int $userId,
        string $type,
        string $description,
        ?\DateTimeInterface $dueAt = null,
        ?string $sessionId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $metadata = null
    ): VoiceCommitment {
        return VoiceCommitment::create([
            'store_id' => $storeId,
            'user_id' => $userId,
            'voice_session_id' => $sessionId,
            'commitment_type' => $type,
            'description' => $description,
            'due_at' => $dueAt,
            'related_entity_type' => $entityType,
            'related_entity_id' => $entityId,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Get pending commitments for a user.
     */
    public function getPendingCommitments(int $storeId, int $userId): Collection
    {
        return VoiceCommitment::getActionItems($storeId, $userId);
    }

    /**
     * Get overdue commitments for a user.
     */
    public function getOverdueCommitments(int $storeId, int $userId): Collection
    {
        return VoiceCommitment::getOverdueItems($storeId, $userId);
    }

    /**
     * Get commitments due today for inclusion in morning briefing.
     */
    public function getCommitmentsDueToday(int $storeId, int $userId): Collection
    {
        return VoiceCommitment::forStore($storeId)
            ->forUser($userId)
            ->dueToday()
            ->get();
    }

    /**
     * Mark a commitment as completed.
     */
    public function completeCommitment(int $commitmentId): bool
    {
        $commitment = VoiceCommitment::find($commitmentId);

        if (! $commitment) {
            return false;
        }

        $commitment->markCompleted();

        return true;
    }

    /**
     * Deactivate a memory.
     */
    public function forgetMemory(int $memoryId): bool
    {
        $memory = VoiceMemory::find($memoryId);

        if (! $memory) {
            return false;
        }

        $memory->deactivate();

        return true;
    }

    /**
     * Update the confidence score of a memory.
     */
    public function reinforceMemory(int $memoryId, float $confidenceBoost = 0.1): bool
    {
        $memory = VoiceMemory::find($memoryId);

        if (! $memory) {
            return false;
        }

        $newConfidence = min(1.0, $memory->confidence + $confidenceBoost);
        $memory->refreshConfidence($newConfidence);

        return true;
    }

    /**
     * Extract potential facts from a conversation turn.
     * Returns an array of potential memories to store.
     *
     * @return array<int, array{content: string, type: string, category: string|null, confidence: float}>
     */
    public function extractMemoriesFromText(string $text): array
    {
        // Look for patterns that indicate stored facts
        $patterns = [
            // "Remember that X" or "Note that X"
            '/(?:remember|note|keep in mind) that (.+)/i',
            // "X prefers Y" or "X likes Y"
            '/(\w+(?:\s+\w+)?)\s+(?:prefers?|likes?|wants?)\s+(.+)/i',
            // "The policy is X" or "Our policy on X is Y"
            '/(?:the|our)\s+policy\s+(?:is|on\s+\w+\s+is)\s+(.+)/i',
            // "Always X" or "Never X"
            '/(?:always|never)\s+(.+)/i',
        ];

        $memories = [];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $content = trim($matches[count($matches) - 1]);

                if (strlen($content) > 10 && strlen($content) < 500) {
                    $memories[] = [
                        'content' => $content,
                        'type' => $this->classifyMemoryType($text),
                        'category' => $this->detectCategory($text),
                        'confidence' => 0.8,
                    ];
                }
            }
        }

        return $memories;
    }

    /**
     * Classify the memory type based on content.
     */
    protected function classifyMemoryType(string $text): string
    {
        $textLower = strtolower($text);

        if (preg_match('/\b(prefer|like|want|always|never)\b/', $textLower)) {
            return 'preference';
        }

        if (preg_match('/\b(remind|follow.?up|call|contact|check)\b/', $textLower)) {
            return 'commitment';
        }

        return 'fact';
    }

    /**
     * Detect the category of a memory based on content.
     */
    protected function detectCategory(string $text): ?string
    {
        $textLower = strtolower($text);

        $categories = [
            'pricing' => ['price', 'cost', 'offer', 'margin', 'percentage', 'discount'],
            'customers' => ['customer', 'client', 'buyer', 'seller'],
            'inventory' => ['inventory', 'stock', 'product', 'item'],
            'operations' => ['policy', 'procedure', 'process', 'rule'],
            'gold' => ['gold', 'karat', '14k', '18k', '24k'],
            'silver' => ['silver', 'sterling'],
        ];

        foreach ($categories as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($textLower, $keyword)) {
                    return $category;
                }
            }
        }

        return null;
    }
}
