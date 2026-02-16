<?php

namespace App\Services\Voice\Tools;

use App\Models\VoiceCommitment;
use App\Services\Chat\Tools\ChatToolInterface;
use App\Services\Voice\VoiceMemoryService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class VoiceCommitmentTool implements ChatToolInterface
{
    public function __construct(
        protected VoiceMemoryService $memoryService
    ) {}

    public function name(): string
    {
        return 'voice_commitment';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Create reminders, follow-ups, and track commitments. Use when the user says things like "remind me to...", "follow up with...", "don\'t let me forget to...", "I need to...", or when tracking promises made to customers.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'enum' => ['create', 'list', 'complete', 'snooze'],
                        'description' => 'The action: create new commitment, list pending, mark complete, or snooze.',
                    ],
                    'commitment_type' => [
                        'type' => 'string',
                        'enum' => ['reminder', 'follow_up', 'action', 'promise'],
                        'description' => 'Type: reminder (personal reminder), follow_up (customer/vendor follow-up), action (task to do), promise (commitment made to someone).',
                    ],
                    'description' => [
                        'type' => 'string',
                        'description' => 'Description of the commitment or reminder.',
                    ],
                    'due_time' => [
                        'type' => 'string',
                        'description' => 'When it\'s due. Natural language like "tomorrow", "next week", "in 2 hours", "Friday at 3pm", or ISO date.',
                    ],
                    'commitment_id' => [
                        'type' => 'integer',
                        'description' => 'ID of commitment for complete/snooze actions.',
                    ],
                    'customer_name' => [
                        'type' => 'string',
                        'description' => 'Customer name if this is a customer-related follow-up.',
                    ],
                ],
                'required' => ['action'],
            ],
        ];
    }

    public function execute(array $params, int $storeId): array
    {
        $action = $params['action'] ?? 'list';
        $userId = Auth::id() ?? 0;

        return match ($action) {
            'create' => $this->createCommitment($params, $storeId, $userId),
            'list' => $this->listCommitments($storeId, $userId),
            'complete' => $this->completeCommitment($params),
            'snooze' => $this->snoozeCommitment($params),
            default => ['success' => false, 'error' => 'Unknown action: '.$action],
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function createCommitment(array $params, int $storeId, int $userId): array
    {
        $description = $params['description'] ?? null;

        if (! $description || strlen($description) < 5) {
            return [
                'success' => false,
                'error' => 'Description is required.',
            ];
        }

        $dueAt = null;
        if (isset($params['due_time'])) {
            $dueAt = $this->parseDueTime($params['due_time']);
        }

        // Add customer name to description if provided
        if (isset($params['customer_name']) && ! str_contains(strtolower($description), strtolower($params['customer_name']))) {
            $description = $description.' (Customer: '.$params['customer_name'].')';
        }

        $commitment = $this->memoryService->createCommitment(
            storeId: $storeId,
            userId: $userId,
            type: $params['commitment_type'] ?? 'reminder',
            description: $description,
            dueAt: $dueAt,
            metadata: array_filter([
                'customer_name' => $params['customer_name'] ?? null,
            ])
        );

        $dueText = $dueAt ? ' for '.$dueAt->format('l').' at '.$dueAt->format('g:i A') : '';

        return [
            'success' => true,
            'message' => "Got it, I'll remind you{$dueText}.",
            'commitment_id' => $commitment->id,
            'created' => [
                'description' => $commitment->description,
                'type' => $commitment->commitment_type,
                'due_at' => $commitment->due_at?->format('Y-m-d H:i'),
                'due_formatted' => $commitment->due_at?->diffForHumans(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function listCommitments(int $storeId, int $userId): array
    {
        $pending = $this->memoryService->getPendingCommitments($storeId, $userId);
        $overdue = $this->memoryService->getOverdueCommitments($storeId, $userId);

        if ($pending->isEmpty() && $overdue->isEmpty()) {
            return [
                'success' => true,
                'has_commitments' => false,
                'message' => 'You have no pending commitments or reminders.',
                'overdue' => [],
                'pending' => [],
            ];
        }

        $overdueFormatted = $overdue->map(fn (VoiceCommitment $c) => [
            'id' => $c->id,
            'description' => $c->description,
            'type' => $c->commitment_type,
            'due_at' => $c->due_at?->format('Y-m-d H:i'),
            'days_overdue' => $c->due_at ? now()->diffInDays($c->due_at) : null,
        ])->toArray();

        $pendingFormatted = $pending->map(fn (VoiceCommitment $c) => [
            'id' => $c->id,
            'description' => $c->description,
            'type' => $c->commitment_type,
            'due_at' => $c->due_at?->format('Y-m-d H:i'),
            'due_formatted' => $c->due_at?->diffForHumans() ?? 'No due date',
        ])->toArray();

        $summary = [];
        if ($overdue->isNotEmpty()) {
            $summary[] = $overdue->count().' overdue';
        }
        if ($pending->isNotEmpty()) {
            $summary[] = $pending->count().' pending';
        }

        return [
            'success' => true,
            'has_commitments' => true,
            'summary' => 'You have '.implode(' and ', $summary).' items.',
            'overdue_count' => $overdue->count(),
            'pending_count' => $pending->count(),
            'overdue' => $overdueFormatted,
            'pending' => $pendingFormatted,
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function completeCommitment(array $params): array
    {
        $commitmentId = $params['commitment_id'] ?? null;

        if (! $commitmentId) {
            return [
                'success' => false,
                'error' => 'Commitment ID is required.',
            ];
        }

        $success = $this->memoryService->completeCommitment($commitmentId);

        if (! $success) {
            return [
                'success' => false,
                'error' => 'Commitment not found.',
            ];
        }

        return [
            'success' => true,
            'message' => 'Done! I\'ve marked that as completed.',
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function snoozeCommitment(array $params): array
    {
        $commitmentId = $params['commitment_id'] ?? null;
        $dueTime = $params['due_time'] ?? 'tomorrow';

        if (! $commitmentId) {
            return [
                'success' => false,
                'error' => 'Commitment ID is required.',
            ];
        }

        $commitment = VoiceCommitment::find($commitmentId);

        if (! $commitment) {
            return [
                'success' => false,
                'error' => 'Commitment not found.',
            ];
        }

        $newDueAt = $this->parseDueTime($dueTime);
        $commitment->snooze($newDueAt);

        return [
            'success' => true,
            'message' => 'Snoozed until '.$newDueAt->format('l').' at '.$newDueAt->format('g:i A').'.',
            'new_due_at' => $newDueAt->format('Y-m-d H:i'),
        ];
    }

    /**
     * Parse natural language time expressions into Carbon date.
     */
    protected function parseDueTime(string $dueTime): Carbon
    {
        $dueTime = strtolower(trim($dueTime));

        // Handle common patterns
        $patterns = [
            'tomorrow' => fn () => now()->addDay()->setHour(9)->setMinute(0),
            'next week' => fn () => now()->addWeek()->startOfWeek()->setHour(9),
            'in an hour' => fn () => now()->addHour(),
            'in 2 hours' => fn () => now()->addHours(2),
            'in 3 hours' => fn () => now()->addHours(3),
            'later today' => fn () => now()->addHours(4),
            'this afternoon' => fn () => now()->setHour(14)->setMinute(0),
            'this evening' => fn () => now()->setHour(18)->setMinute(0),
            'tonight' => fn () => now()->setHour(19)->setMinute(0),
            'end of day' => fn () => now()->setHour(17)->setMinute(0),
            'next month' => fn () => now()->addMonth()->startOfMonth()->setHour(9),
        ];

        foreach ($patterns as $pattern => $callback) {
            if (str_contains($dueTime, $pattern)) {
                return $callback();
            }
        }

        // Handle "in X minutes/hours/days"
        if (preg_match('/in (\d+) (minute|hour|day|week)s?/', $dueTime, $matches)) {
            $amount = (int) $matches[1];
            $unit = $matches[2];

            return match ($unit) {
                'minute' => now()->addMinutes($amount),
                'hour' => now()->addHours($amount),
                'day' => now()->addDays($amount)->setHour(9),
                'week' => now()->addWeeks($amount)->setHour(9),
                default => now()->addDay()->setHour(9),
            };
        }

        // Handle day names
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        foreach ($days as $day) {
            if (str_contains($dueTime, $day)) {
                $date = Carbon::parse("next {$day}")->setHour(9);

                // Check for time in the string
                if (preg_match('/(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/i', $dueTime, $timeMatch)) {
                    $hour = (int) $timeMatch[1];
                    $minute = isset($timeMatch[2]) ? (int) $timeMatch[2] : 0;
                    $ampm = $timeMatch[3] ?? null;

                    if ($ampm && strtolower($ampm) === 'pm' && $hour < 12) {
                        $hour += 12;
                    } elseif ($ampm && strtolower($ampm) === 'am' && $hour === 12) {
                        $hour = 0;
                    }

                    $date->setHour($hour)->setMinute($minute);
                }

                return $date;
            }
        }

        // Try Carbon's parse as fallback
        try {
            return Carbon::parse($dueTime);
        } catch (\Exception) {
            return now()->addDay()->setHour(9)->setMinute(0);
        }
    }
}
