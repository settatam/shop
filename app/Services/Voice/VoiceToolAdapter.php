<?php

namespace App\Services\Voice;

use App\Services\Chat\ChatToolExecutor;

class VoiceToolAdapter
{
    public function __construct(
        protected ChatToolExecutor $toolExecutor,
        protected VoiceMemoryService $memoryService
    ) {}

    /**
     * Get all tool definitions including voice-specific tools.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDefinitions(): array
    {
        return $this->toolExecutor->getDefinitions();
    }

    /**
     * Execute a tool with voice-specific enhancements.
     *
     * @return array<string, mixed>
     */
    public function execute(string $toolName, array $params, int $storeId, ?int $userId = null): array
    {
        // Execute the base tool
        $result = $this->toolExecutor->execute($toolName, $params, $storeId);

        // Enhance certain tools with memory context
        if ($userId && in_array($toolName, ['get_morning_briefing', 'get_end_of_day_report'])) {
            $result = $this->enhanceWithCommitments($result, $storeId, $userId);
        }

        return $result;
    }

    /**
     * Check if a tool exists.
     */
    public function has(string $name): bool
    {
        return $this->toolExecutor->has($name);
    }

    /**
     * Enhance briefing tools with pending commitments.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    protected function enhanceWithCommitments(array $result, int $storeId, int $userId): array
    {
        $overdue = $this->memoryService->getOverdueCommitments($storeId, $userId);
        $dueToday = $this->memoryService->getCommitmentsDueToday($storeId, $userId);

        $commitments = [];

        foreach ($overdue as $commitment) {
            $commitments[] = [
                'id' => $commitment->id,
                'type' => $commitment->commitment_type,
                'description' => $commitment->description,
                'status' => 'overdue',
                'days_overdue' => $commitment->due_at ? now()->diffInDays($commitment->due_at) : null,
            ];
        }

        foreach ($dueToday as $commitment) {
            if (! in_array($commitment->id, array_column($commitments, 'id'))) {
                $commitments[] = [
                    'id' => $commitment->id,
                    'type' => $commitment->commitment_type,
                    'description' => $commitment->description,
                    'status' => 'due_today',
                    'due_at' => $commitment->due_at?->format('g:i A'),
                ];
            }
        }

        if (! empty($commitments)) {
            $result['voice_commitments'] = $commitments;
            $result['voice_commitments_summary'] = $this->buildCommitmentsSummary($commitments);
        }

        return $result;
    }

    /**
     * Build a human-readable summary of commitments.
     *
     * @param  array<int, array<string, mixed>>  $commitments
     */
    protected function buildCommitmentsSummary(array $commitments): string
    {
        $overdue = array_filter($commitments, fn ($c) => $c['status'] === 'overdue');
        $dueToday = array_filter($commitments, fn ($c) => $c['status'] === 'due_today');

        $parts = [];

        if (count($overdue) > 0) {
            $parts[] = count($overdue).' overdue follow-up'.($overdue > 1 ? 's' : '');
        }

        if (count($dueToday) > 0) {
            $parts[] = count($dueToday).' commitment'.($dueToday > 1 ? 's' : '').' due today';
        }

        return ! empty($parts) ? 'You have '.implode(' and ', $parts).'.' : '';
    }

    /**
     * Get relevant memories to include in system prompt.
     *
     * @return array{facts: array<string>, preferences: array<string>}
     */
    public function getContextMemories(int $storeId, ?string $query = null): array
    {
        return $this->memoryService->getContextMemories($storeId, $query);
    }

    /**
     * Format tool result for voice output.
     * Simplifies complex data structures for TTS.
     *
     * @param  array<string, mixed>  $result
     */
    public function formatForVoice(string $toolName, array $result): string
    {
        // If there's already a summary field, use it
        if (isset($result['summary'])) {
            $summary = $result['summary'];

            // Append commitments summary if present
            if (isset($result['voice_commitments_summary']) && $result['voice_commitments_summary']) {
                $summary .= ' '.$result['voice_commitments_summary'];
            }

            return $summary;
        }

        // Tool-specific formatting
        return match ($toolName) {
            'get_sales_report' => $this->formatSalesReport($result),
            'calculate_metal_value' => $this->formatMetalCalculation($result),
            'get_customer_intelligence' => $this->formatCustomerIntelligence($result),
            default => json_encode($result),
        };
    }

    /**
     * Format sales report for voice.
     *
     * @param  array<string, mixed>  $result
     */
    protected function formatSalesReport(array $result): string
    {
        $revenue = $result['revenue_formatted'] ?? '$'.number_format($result['revenue'] ?? 0, 0);
        $transactions = $result['transactions'] ?? 0;

        return "Revenue is {$revenue} across {$transactions} transactions.";
    }

    /**
     * Format metal calculation for voice.
     *
     * @param  array<string, mixed>  $result
     */
    protected function formatMetalCalculation(array $result): string
    {
        if (isset($result['error'])) {
            return $result['error'];
        }

        $spotValue = $result['spot_value_formatted'] ?? '$'.number_format($result['spot_value'] ?? 0, 0);
        $offerRange = '';

        if (isset($result['offer_ranges'])) {
            $low = $result['offer_ranges']['60%'] ?? null;
            $high = $result['offer_ranges']['70%'] ?? null;
            if ($low && $high) {
                $offerRange = " Offer between {$low} and {$high}.";
            }
        }

        return "Spot value is {$spotValue}.{$offerRange}";
    }

    /**
     * Format customer intelligence for voice.
     *
     * @param  array<string, mixed>  $result
     */
    protected function formatCustomerIntelligence(array $result): string
    {
        if (isset($result['error'])) {
            return $result['error'];
        }

        $name = $result['name'] ?? 'Customer';
        $lifetime = $result['lifetime_value_formatted'] ?? '$'.number_format($result['lifetime_value'] ?? 0, 0);
        $visits = $result['total_visits'] ?? 0;

        return "{$name} has spent {$lifetime} over {$visits} visits.";
    }
}
