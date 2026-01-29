<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class QueuedNotification extends Model
{
    use BelongsToStore, HasFactory;

    public const MAX_ATTEMPTS = 3;

    protected $fillable = [
        'store_id',
        'notification_subscription_id',
        'notifiable_type',
        'notifiable_id',
        'data',
        'scheduled_at',
        'sent_at',
        'is_sent',
        'has_error',
        'error_message',
        'attempts',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'is_sent' => 'boolean',
            'has_error' => 'boolean',
            'attempts' => 'integer',
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

    /**
     * The entity this notification is about.
     */
    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get notifications ready to be sent.
     */
    public static function getReadyToSend(): \Illuminate\Database\Eloquent\Collection
    {
        return self::with(['subscription.template', 'notifiable'])
            ->where('is_sent', false)
            ->where('has_error', false)
            ->where('scheduled_at', '<=', now())
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->orderBy('scheduled_at')
            ->get();
    }

    /**
     * Mark as sent.
     */
    public function markAsSent(): self
    {
        $this->update([
            'is_sent' => true,
            'sent_at' => now(),
            'has_error' => false,
            'error_message' => null,
        ]);

        return $this;
    }

    /**
     * Mark as failed.
     */
    public function markAsFailed(string $errorMessage): self
    {
        $this->increment('attempts');
        $this->update([
            'has_error' => $this->attempts >= self::MAX_ATTEMPTS,
            'error_message' => $errorMessage,
        ]);

        return $this;
    }

    /**
     * Check if this notification can be retried.
     */
    public function canRetry(): bool
    {
        return ! $this->is_sent && $this->attempts < self::MAX_ATTEMPTS;
    }

    /**
     * Scope to get pending notifications.
     */
    public function scopePending($query)
    {
        return $query->where('is_sent', false)->where('has_error', false);
    }

    /**
     * Scope to get failed notifications.
     */
    public function scopeFailed($query)
    {
        return $query->where('has_error', true);
    }

    /**
     * Scope to get notifications ready to send.
     */
    public function scopeReadyToSend($query)
    {
        return $query->where('is_sent', false)
            ->where('has_error', false)
            ->where('scheduled_at', '<=', now())
            ->where('attempts', '<', self::MAX_ATTEMPTS);
    }
}
