<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class NotificationSubscription extends Model
{
    use BelongsToStore, HasFactory, SoftDeletes;

    public const SCHEDULE_IMMEDIATE = 'immediate';

    public const SCHEDULE_DELAYED = 'delayed';

    public const SCHEDULE_SCHEDULED = 'scheduled';

    public const RECIPIENT_OWNER = 'owner';

    public const RECIPIENT_CUSTOMER = 'customer';

    public const RECIPIENT_STAFF = 'staff';

    public const RECIPIENT_CUSTOM = 'custom';

    protected $fillable = [
        'store_id',
        'notification_template_id',
        'activity',
        'name',
        'description',
        'conditions',
        'recipients',
        'schedule_type',
        'delay_minutes',
        'delay_unit',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'recipients' => 'array',
            'delay_minutes' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'notification_template_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }

    public function queuedNotifications(): HasMany
    {
        return $this->hasMany(QueuedNotification::class);
    }

    /**
     * Get subscriptions for a specific activity.
     */
    public static function forActivity(string $activity, ?int $storeId = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = self::with('template')
            ->where('activity', $activity)
            ->where('is_enabled', true)
            ->whereHas('template', fn ($q) => $q->where('is_enabled', true));

        if ($storeId) {
            $query->where('store_id', $storeId);
        }

        return $query->get();
    }

    /**
     * Check if conditions are met for the given data.
     */
    public function conditionsMet(array $data): bool
    {
        if (empty($this->conditions)) {
            return true;
        }

        foreach ($this->conditions as $condition) {
            $field = data_get($condition, 'field');
            $operator = data_get($condition, 'operator', '==');
            $value = data_get($condition, 'value');
            $actualValue = data_get($data, $field);

            $met = match ($operator) {
                '==' => $actualValue == $value,
                '!=' => $actualValue != $value,
                '>' => $actualValue > $value,
                '>=' => $actualValue >= $value,
                '<' => $actualValue < $value,
                '<=' => $actualValue <= $value,
                'in' => in_array($actualValue, (array) $value),
                'not_in' => ! in_array($actualValue, (array) $value),
                'contains' => str_contains((string) $actualValue, (string) $value),
                'empty' => empty($actualValue),
                'not_empty' => ! empty($actualValue),
                default => true,
            };

            if (! $met) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the recipients for this subscription.
     */
    public function getRecipientEmails(array $data): array
    {
        $emails = [];
        $recipients = $this->recipients ?? [self::RECIPIENT_OWNER];

        foreach ($recipients as $recipient) {
            if (is_array($recipient)) {
                $type = $recipient['type'] ?? self::RECIPIENT_CUSTOM;
                $value = $recipient['value'] ?? null;
            } else {
                $type = $recipient;
                $value = null;
            }

            switch ($type) {
                case self::RECIPIENT_OWNER:
                    if ($owner = data_get($data, 'store.owner.email')) {
                        $emails[] = $owner;
                    }
                    break;

                case self::RECIPIENT_CUSTOMER:
                    if ($customerEmail = data_get($data, 'customer.email')) {
                        $emails[] = $customerEmail;
                    }
                    break;

                case self::RECIPIENT_STAFF:
                    // Get all staff emails for the store
                    if ($storeId = data_get($data, 'store.id')) {
                        $staffEmails = StoreUser::where('store_id', $storeId)
                            ->with('user')
                            ->get()
                            ->pluck('user.email')
                            ->filter()
                            ->toArray();
                        $emails = array_merge($emails, $staffEmails);
                    }
                    break;

                case self::RECIPIENT_CUSTOM:
                    if ($value) {
                        $customEmails = array_map('trim', explode(',', $value));
                        $emails = array_merge($emails, $customEmails);
                    }
                    break;
            }
        }

        return array_unique(array_filter($emails));
    }

    /**
     * Calculate the scheduled send time based on delay settings.
     */
    public function getScheduledTime(): \DateTimeInterface
    {
        if ($this->schedule_type !== self::SCHEDULE_DELAYED || ! $this->delay_minutes) {
            return now();
        }

        $minutes = match ($this->delay_unit) {
            'hours' => $this->delay_minutes * 60,
            'days' => $this->delay_minutes * 60 * 24,
            default => $this->delay_minutes,
        };

        return now()->addMinutes($minutes);
    }

    /**
     * Check if this subscription should be sent immediately.
     */
    public function isImmediate(): bool
    {
        return $this->schedule_type === self::SCHEDULE_IMMEDIATE;
    }

    /**
     * Scope to get enabled subscriptions.
     */
    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Scope to get subscriptions for a specific activity.
     */
    public function scopeForActivity($query, string $activity)
    {
        return $query->where('activity', $activity);
    }
}
