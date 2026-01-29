<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatusAutomation extends Model
{
    use HasFactory;

    public const TRIGGER_ON_ENTER = 'on_enter';

    public const TRIGGER_ON_EXIT = 'on_exit';

    public const ACTION_NOTIFICATION = 'notification';

    public const ACTION_WEBHOOK = 'webhook';

    public const ACTION_CUSTOM = 'custom';

    protected $fillable = [
        'status_id',
        'trigger',
        'action_type',
        'action_config',
        'sort_order',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'action_config' => 'array',
            'sort_order' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * Get the status this automation belongs to.
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    /**
     * Check if this automation runs on enter.
     */
    public function runsOnEnter(): bool
    {
        return $this->trigger === self::TRIGGER_ON_ENTER;
    }

    /**
     * Check if this automation runs on exit.
     */
    public function runsOnExit(): bool
    {
        return $this->trigger === self::TRIGGER_ON_EXIT;
    }

    /**
     * Check if this is a notification automation.
     */
    public function isNotification(): bool
    {
        return $this->action_type === self::ACTION_NOTIFICATION;
    }

    /**
     * Check if this is a webhook automation.
     */
    public function isWebhook(): bool
    {
        return $this->action_type === self::ACTION_WEBHOOK;
    }

    /**
     * Check if this is a custom action automation.
     */
    public function isCustomAction(): bool
    {
        return $this->action_type === self::ACTION_CUSTOM;
    }

    /**
     * Get the notification template ID if this is a notification automation.
     */
    public function getNotificationTemplateId(): ?int
    {
        if (! $this->isNotification()) {
            return null;
        }

        return $this->action_config['template_id'] ?? null;
    }

    /**
     * Get the recipients for a notification automation.
     *
     * @return array<string>
     */
    public function getNotificationRecipients(): array
    {
        if (! $this->isNotification()) {
            return [];
        }

        return $this->action_config['recipients'] ?? [];
    }

    /**
     * Get the webhook URL if this is a webhook automation.
     */
    public function getWebhookUrl(): ?string
    {
        if (! $this->isWebhook()) {
            return null;
        }

        return $this->action_config['url'] ?? null;
    }

    /**
     * Get the webhook method (default: POST).
     */
    public function getWebhookMethod(): string
    {
        return $this->action_config['method'] ?? 'POST';
    }

    /**
     * Get webhook headers.
     *
     * @return array<string, string>
     */
    public function getWebhookHeaders(): array
    {
        return $this->action_config['headers'] ?? [];
    }

    /**
     * Get the custom action name if this is a custom action automation.
     */
    public function getCustomAction(): ?string
    {
        if (! $this->isCustomAction()) {
            return null;
        }

        return $this->action_config['action'] ?? null;
    }

    /**
     * Get custom action parameters.
     *
     * @return array<string, mixed>
     */
    public function getCustomActionParams(): array
    {
        return $this->action_config['params'] ?? [];
    }

    /**
     * Scope to get enabled automations.
     */
    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Scope to get automations for a specific trigger.
     */
    public function scopeForTrigger($query, string $trigger)
    {
        return $query->where('trigger', $trigger);
    }

    /**
     * Get a human-readable description of this automation.
     */
    public function getDescription(): string
    {
        $triggerText = $this->trigger === self::TRIGGER_ON_ENTER ? 'When entering' : 'When leaving';
        $statusName = $this->status?->name ?? 'this status';

        $actionText = match ($this->action_type) {
            self::ACTION_NOTIFICATION => 'send a notification',
            self::ACTION_WEBHOOK => 'call webhook',
            self::ACTION_CUSTOM => "run '{$this->getCustomAction()}'",
            default => 'perform action',
        };

        return "{$triggerText} {$statusName}: {$actionText}";
    }
}
