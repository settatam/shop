<?php

namespace App\Models;

use App\Enums\Platform;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookLog extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'store_marketplace_id',
        'store_id',
        'platform',
        'event_type',
        'external_id',
        'status',
        'error_message',
        'retry_count',
        'headers',
        'payload',
        'response',
        'ip_address',
        'signature',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'headers' => 'array',
            'payload' => 'array',
            'response' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function marketplace(): BelongsTo
    {
        return $this->belongsTo(StoreMarketplace::class, 'store_marketplace_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function markAsProcessing(): self
    {
        $this->update(['status' => self::STATUS_PROCESSING]);

        return $this;
    }

    public function markAsCompleted(array $response = []): self
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'response' => $response,
            'processed_at' => now(),
        ]);

        return $this;
    }

    public function markAsFailed(string $error): self
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $error,
            'retry_count' => $this->retry_count + 1,
        ]);

        return $this;
    }

    public function markAsSkipped(string $reason): self
    {
        $this->update([
            'status' => self::STATUS_SKIPPED,
            'error_message' => $reason,
            'processed_at' => now(),
        ]);

        return $this;
    }

    public function canRetry(): bool
    {
        return $this->retry_count < 3 && $this->isFailed();
    }
}
