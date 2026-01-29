<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_marketplace_id',
        'sync_type',
        'direction',
        'status',
        'total_items',
        'processed_items',
        'success_count',
        'error_count',
        'errors',
        'summary',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'summary' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function marketplace(): BelongsTo
    {
        return $this->belongsTo(StoreMarketplace::class, 'store_marketplace_id');
    }

    public static function start(int $marketplaceId, string $syncType, string $direction): self
    {
        return self::create([
            'store_marketplace_id' => $marketplaceId,
            'sync_type' => $syncType,
            'direction' => $direction,
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    public function markCompleted(array $summary = []): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'summary' => $summary,
        ]);
    }

    public function markFailed(array $errors = []): void
    {
        $this->update([
            'status' => 'failed',
            'completed_at' => now(),
            'errors' => $errors,
        ]);
    }

    public function incrementProcessed(): void
    {
        $this->increment('processed_items');
    }

    public function incrementSuccess(): void
    {
        $this->increment('success_count');
    }

    public function incrementError(): void
    {
        $this->increment('error_count');
    }

    public function addError(string $error): void
    {
        $errors = $this->errors ?? [];
        $errors[] = $error;
        $this->update(['errors' => $errors]);
        $this->incrementError();
    }
}
