<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ActivityLogFormatter
{
    /**
     * Format activity logs for a given subject model.
     *
     * @return array<int, array{date: string, dateTime: string, items: array}>
     */
    public function formatForSubject(Model $subject, int $limit = 50): array
    {
        $logs = ActivityLog::query()
            ->where(function ($query) use ($subject) {
                // Include logs for the subject itself
                $query->where(function ($q) use ($subject) {
                    $q->where('subject_type', $subject->getMorphClass())
                        ->where('subject_id', $subject->getKey());
                });

                // For transactions, include all related activities
                if ($subject instanceof \App\Models\Transaction) {
                    // Item-level activities
                    $itemIds = $subject->items()->pluck('id');
                    if ($itemIds->isNotEmpty()) {
                        $query->orWhere(function ($q) use ($itemIds) {
                            $q->where('subject_type', \App\Models\TransactionItem::class)
                                ->whereIn('subject_id', $itemIds);
                        });
                    }

                    // Payout activities (transactions use payouts, not payments)
                    $payoutIds = $subject->payouts()->pluck('id');
                    if ($payoutIds->isNotEmpty()) {
                        $query->orWhere(function ($q) use ($payoutIds) {
                            $q->where('subject_type', \App\Models\TransactionPayout::class)
                                ->whereIn('subject_id', $payoutIds);
                        });
                    }

                    // Note activities
                    if (method_exists($subject, 'notes')) {
                        $noteIds = $subject->notes()->pluck('id');
                        if ($noteIds->isNotEmpty()) {
                            $query->orWhere(function ($q) use ($noteIds) {
                                $q->where('subject_type', \App\Models\Note::class)
                                    ->whereIn('subject_id', $noteIds);
                            });
                        }
                    }

                    // Also include activities logged with transaction_id in properties
                    $query->orWhere(function ($q) use ($subject) {
                        $q->whereJsonContains('properties->transaction_id', $subject->getKey());
                    });
                }

                // For orders, include related activities
                if ($subject instanceof \App\Models\Order) {
                    $paymentIds = $subject->payments()->pluck('id');
                    if ($paymentIds->isNotEmpty()) {
                        $query->orWhere(function ($q) use ($paymentIds) {
                            $q->where('subject_type', \App\Models\Payment::class)
                                ->whereIn('subject_id', $paymentIds);
                        });
                    }

                    $noteIds = $subject->notes()->pluck('id');
                    if ($noteIds->isNotEmpty()) {
                        $query->orWhere(function ($q) use ($noteIds) {
                            $q->where('subject_type', \App\Models\Note::class)
                                ->whereIn('subject_id', $noteIds);
                        });
                    }
                }
            })
            ->with('user')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return $this->groupByDate($logs);
    }

    /**
     * Group activity logs by date.
     *
     * @return array<int, array{date: string, dateTime: string, items: array}>
     */
    protected function groupByDate(Collection $logs): array
    {
        $grouped = $logs->groupBy(fn ($log) => match (true) {
            $log->created_at->isToday() => 'Today',
            $log->created_at->isYesterday() => 'Yesterday',
            default => $log->created_at->format('F j, Y'),
        });

        $result = [];
        foreach ($grouped as $date => $items) {
            $result[] = [
                'date' => $date,
                'dateTime' => $items->first()->created_at->toDateString(),
                'items' => $items->map(fn ($log) => [
                    'id' => $log->id,
                    'activity' => $log->activity_slug,
                    'description' => $this->formatDescription($log),
                    'user' => $log->user ? ['name' => $log->user->name] : null,
                    'changes' => $this->formatChanges($log->properties),
                    'time' => $log->created_at->format('g:i A'),
                    'created_at' => $log->created_at->toIso8601String(),
                    'icon' => $this->getIconForActivity($log->activity_slug),
                    'color' => $this->getColorForActivity($log->activity_slug),
                ])->toArray(),
            ];
        }

        return $result;
    }

    /**
     * Format the description for an activity log entry.
     */
    protected function formatDescription(ActivityLog $log): string
    {
        if ($log->description) {
            return $log->description;
        }

        $definitions = Activity::getDefinitions();
        $activityDef = $definitions[$log->activity_slug] ?? null;

        if ($activityDef) {
            return $activityDef['name'];
        }

        // Parse the activity slug to create a readable description
        $parts = explode('.', $log->activity_slug);
        $action = end($parts);

        return ucfirst(str_replace('_', ' ', $action));
    }

    /**
     * Format changes for display.
     *
     * @return array<string, array{old: string, new: string}>|null
     */
    protected function formatChanges(?array $properties): ?array
    {
        if (! $properties) {
            return null;
        }

        // Fields to exclude from display
        $excludedFields = ['updated_at', 'created_at'];

        $changes = [];

        // Handle new format: properties['changes'] with labeled fields
        if (isset($properties['changes']) && is_array($properties['changes'])) {
            foreach ($properties['changes'] as $field => $change) {
                if (in_array($field, $excludedFields)) {
                    continue;
                }
                $label = $change['label'] ?? $this->formatFieldName($field);
                $changes[$label] = [
                    'old' => $this->formatValue($change['old'] ?? null),
                    'new' => $this->formatValue($change['new'] ?? null),
                ];
            }

            return empty($changes) ? null : $changes;
        }

        // Handle legacy format: properties['old'] and properties['new']
        if (isset($properties['old'], $properties['new'])) {
            foreach ($properties['new'] as $key => $newValue) {
                if (in_array($key, $excludedFields)) {
                    continue;
                }
                $oldValue = $properties['old'][$key] ?? null;
                if ($oldValue !== $newValue) {
                    $changes[$this->formatFieldName($key)] = [
                        'old' => $this->formatValue($oldValue),
                        'new' => $this->formatValue($newValue),
                    ];
                }
            }

            return empty($changes) ? null : $changes;
        }

        return null;
    }

    /**
     * Format a field name for display.
     */
    protected function formatFieldName(string $key): string
    {
        return ucfirst(str_replace('_', ' ', $key));
    }

    /**
     * Format a value for display.
     */
    protected function formatValue(mixed $value): string
    {
        if (is_null($value)) {
            return 'None';
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_array($value)) {
            return json_encode($value);
        }

        $stringValue = (string) $value;

        // Strip HTML tags and decode entities for clean display
        if (str_contains($stringValue, '<') && str_contains($stringValue, '>')) {
            $stringValue = strip_tags($stringValue);
            $stringValue = html_entity_decode($stringValue, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $stringValue = trim(preg_replace('/\s+/', ' ', $stringValue));
        }

        // Truncate long values
        if (strlen($stringValue) > 100) {
            $stringValue = substr($stringValue, 0, 100).'...';
        }

        return $stringValue;
    }

    /**
     * Get the icon type for an activity.
     */
    protected function getIconForActivity(string $activitySlug): string
    {
        // Notes get a chat bubble icon
        if (str_starts_with($activitySlug, 'notes.') || str_contains($activitySlug, 'note')) {
            return 'chat-bubble';
        }
        if (str_contains($activitySlug, 'sms') || str_contains($activitySlug, 'message')) {
            return 'envelope';
        }
        if (str_contains($activitySlug, 'reviewed') || str_contains($activitySlug, 'review')) {
            return 'check-circle';
        }
        if (str_contains($activitySlug, 'offer') || str_contains($activitySlug, 'quote')) {
            return 'currency-dollar';
        }
        if (str_contains($activitySlug, '.create') || str_contains($activitySlug, 'added')) {
            return 'plus';
        }
        if (str_contains($activitySlug, '.update') || str_contains($activitySlug, 'changed')) {
            return 'pencil';
        }
        if (str_contains($activitySlug, '.delete') || str_contains($activitySlug, 'removed')) {
            return 'trash';
        }
        if (str_contains($activitySlug, '.cancel')) {
            return 'x-circle';
        }
        if (str_contains($activitySlug, 'payment') || str_contains($activitySlug, 'paid')) {
            return 'banknotes';
        }
        if (str_contains($activitySlug, '.complete') || str_contains($activitySlug, 'completed')) {
            return 'check-circle';
        }
        if (str_contains($activitySlug, 'send_to_vendor') || str_contains($activitySlug, 'ship')) {
            return 'truck';
        }
        if (str_contains($activitySlug, 'receive') || str_contains($activitySlug, 'mark_received')) {
            return 'inbox';
        }
        if (str_contains($activitySlug, 'status')) {
            return 'arrow-path';
        }

        return 'document';
    }

    /**
     * Get the color scheme for an activity.
     */
    protected function getColorForActivity(string $activitySlug): string
    {
        if (str_contains($activitySlug, 'reviewed') || str_contains($activitySlug, 'review')) {
            return 'blue';
        }
        if (str_contains($activitySlug, 'sms') || str_contains($activitySlug, 'message')) {
            return 'purple';
        }
        if (str_contains($activitySlug, 'note')) {
            return 'indigo';
        }
        if (str_contains($activitySlug, 'offer') || str_contains($activitySlug, 'quote')) {
            return 'amber';
        }
        if (str_contains($activitySlug, '.create') || str_contains($activitySlug, 'added')) {
            return 'green';
        }
        if (str_contains($activitySlug, '.update') || str_contains($activitySlug, 'changed')) {
            return 'blue';
        }
        if (str_contains($activitySlug, '.delete') || str_contains($activitySlug, 'removed')) {
            return 'red';
        }
        if (str_contains($activitySlug, '.cancel')) {
            return 'yellow';
        }
        if (str_contains($activitySlug, 'payment') || str_contains($activitySlug, 'paid')) {
            return 'green';
        }
        if (str_contains($activitySlug, '.complete') || str_contains($activitySlug, 'completed')) {
            return 'green';
        }
        if (str_contains($activitySlug, 'status')) {
            return 'blue';
        }

        return 'gray';
    }
}
