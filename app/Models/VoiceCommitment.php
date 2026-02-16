<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;

class VoiceCommitment extends Model
{
    /** @use HasFactory<\Database\Factories\VoiceCommitmentFactory> */
    use BelongsToStore, HasFactory;

    protected $fillable = [
        'store_id',
        'user_id',
        'voice_session_id',
        'commitment_type',
        'description',
        'due_at',
        'status',
        'related_entity_type',
        'related_entity_id',
        'completed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function voiceSession(): BelongsTo
    {
        return $this->belongsTo(VoiceSession::class);
    }

    public function relatedEntity(): MorphTo
    {
        return $this->morphTo('related_entity');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', 'pending')
            ->whereNotNull('due_at')
            ->where('due_at', '<', now());
    }

    public function scopeDueToday(Builder $query): Builder
    {
        return $query->where('status', 'pending')
            ->whereDate('due_at', today());
    }

    public function scopeDueSoon(Builder $query, int $days = 3): Builder
    {
        return $query->where('status', 'pending')
            ->whereNotNull('due_at')
            ->whereBetween('due_at', [now(), now()->addDays($days)]);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('commitment_type', $type);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public static function createReminder(
        int $storeId,
        int $userId,
        string $description,
        ?\DateTimeInterface $dueAt = null,
        ?string $sessionId = null,
        ?array $metadata = null
    ): self {
        return static::create([
            'store_id' => $storeId,
            'user_id' => $userId,
            'voice_session_id' => $sessionId,
            'commitment_type' => 'reminder',
            'description' => $description,
            'due_at' => $dueAt,
            'metadata' => $metadata,
        ]);
    }

    public static function createFollowUp(
        int $storeId,
        int $userId,
        string $description,
        ?string $entityType = null,
        ?int $entityId = null,
        ?\DateTimeInterface $dueAt = null,
        ?string $sessionId = null
    ): self {
        return static::create([
            'store_id' => $storeId,
            'user_id' => $userId,
            'voice_session_id' => $sessionId,
            'commitment_type' => 'follow_up',
            'description' => $description,
            'due_at' => $dueAt,
            'related_entity_type' => $entityType,
            'related_entity_id' => $entityId,
        ]);
    }

    public static function getActionItems(int $storeId, int $userId): Collection
    {
        return static::forStore($storeId)
            ->forUser($userId)
            ->pending()
            ->orderBy('due_at')
            ->orderByDesc('created_at')
            ->get();
    }

    public static function getOverdueItems(int $storeId, int $userId): Collection
    {
        return static::forStore($storeId)
            ->forUser($userId)
            ->overdue()
            ->orderBy('due_at')
            ->get();
    }

    public function markCompleted(): self
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        return $this;
    }

    public function markCancelled(): self
    {
        $this->update(['status' => 'cancelled']);

        return $this;
    }

    public function markOverdue(): self
    {
        $this->update(['status' => 'overdue']);

        return $this;
    }

    public function snooze(\DateTimeInterface $newDueAt): self
    {
        $this->update(['due_at' => $newDueAt]);

        return $this;
    }

    public function isOverdue(): bool
    {
        return $this->status === 'pending'
            && $this->due_at
            && $this->due_at->isPast();
    }
}
