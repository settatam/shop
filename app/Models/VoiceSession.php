<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VoiceSession extends Model
{
    /** @use HasFactory<\Database\Factories\VoiceSessionFactory> */
    use BelongsToStore, HasFactory, HasUuids;

    protected $fillable = [
        'store_id',
        'user_id',
        'gateway_session_id',
        'status',
        'started_at',
        'ended_at',
        'total_duration_seconds',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function commitments(): HasMany
    {
        return $this->hasMany(VoiceCommitment::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeEnded($query)
    {
        return $query->where('status', 'ended');
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function start(): self
    {
        $this->update([
            'status' => 'active',
            'started_at' => now(),
        ]);

        return $this;
    }

    public function end(): self
    {
        $endedAt = now();
        $duration = $this->started_at
            ? $this->started_at->diffInSeconds($endedAt)
            : 0;

        $this->update([
            'status' => 'ended',
            'ended_at' => $endedAt,
            'total_duration_seconds' => $duration,
        ]);

        return $this;
    }

    public function markError(?string $error = null): self
    {
        $this->update([
            'status' => 'error',
            'ended_at' => now(),
            'metadata' => array_merge($this->metadata ?? [], [
                'error' => $error,
            ]),
        ]);

        return $this;
    }
}
