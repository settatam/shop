<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class NotificationLog extends Model
{
    use BelongsToStore, HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    public const STATUS_BOUNCED = 'bounced';

    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    protected $fillable = [
        'store_id',
        'notification_subscription_id',
        'notification_template_id',
        'channel',
        'direction',
        'activity',
        'recipient',
        'recipient_type',
        'notifiable_type',
        'notifiable_id',
        'recipient_model_type',
        'recipient_model_id',
        'subject',
        'content',
        'data',
        'status',
        'error_message',
        'external_id',
        'sent_at',
        'delivered_at',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(NotificationSubscription::class, 'notification_subscription_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'notification_template_id');
    }

    /**
     * The entity this notification is about (Order, Product, etc.).
     */
    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The recipient model (User, Customer, etc.).
     */
    public function recipientModel(): MorphTo
    {
        return $this->morphTo('recipient_model');
    }

    /**
     * Mark notification as sent.
     */
    public function markAsSent(?string $externalId = null): self
    {
        $this->update([
            'status' => self::STATUS_SENT,
            'sent_at' => now(),
            'external_id' => $externalId,
        ]);

        return $this;
    }

    /**
     * Mark notification as delivered.
     */
    public function markAsDelivered(): self
    {
        $this->update([
            'status' => self::STATUS_DELIVERED,
            'delivered_at' => now(),
        ]);

        return $this;
    }

    /**
     * Mark notification as failed.
     */
    public function markAsFailed(string $errorMessage): self
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
        ]);

        return $this;
    }

    /**
     * Mark notification as read.
     */
    public function markAsRead(): self
    {
        $this->update([
            'read_at' => now(),
        ]);

        return $this;
    }

    /**
     * Check if the notification was sent successfully.
     */
    public function wasSent(): bool
    {
        return in_array($this->status, [self::STATUS_SENT, self::STATUS_DELIVERED]);
    }

    /**
     * Check if the notification failed.
     */
    public function hasFailed(): bool
    {
        return in_array($this->status, [self::STATUS_FAILED, self::STATUS_BOUNCED]);
    }

    /**
     * Scope to get logs by status.
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to get logs by channel.
     */
    public function scopeForChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    /**
     * Scope to get logs for a specific activity.
     */
    public function scopeForActivity($query, string $activity)
    {
        return $query->where('activity', $activity);
    }

    /**
     * Scope to get recent logs.
     */
    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Scope to get inbound messages.
     */
    public function scopeInbound($query)
    {
        return $query->where('direction', self::DIRECTION_INBOUND);
    }

    /**
     * Scope to get outbound messages.
     */
    public function scopeOutbound($query)
    {
        return $query->where('direction', self::DIRECTION_OUTBOUND);
    }

    /**
     * Check if this is an inbound message.
     */
    public function isInbound(): bool
    {
        return $this->direction === self::DIRECTION_INBOUND;
    }

    /**
     * Check if this is an outbound message.
     */
    public function isOutbound(): bool
    {
        return $this->direction === self::DIRECTION_OUTBOUND;
    }
}
