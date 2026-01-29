<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AiSuggestion extends Model
{
    use BelongsToStore, HasFactory;

    protected $fillable = [
        'store_id',
        'suggestable_type',
        'suggestable_id',
        'type',
        'platform',
        'original_content',
        'suggested_content',
        'metadata',
        'status',
        'accepted_by',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'accepted_at' => 'datetime',
        ];
    }

    public function suggestable(): MorphTo
    {
        return $this->morphTo();
    }

    public function acceptedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function accept(User $user): void
    {
        $this->update([
            'status' => 'accepted',
            'accepted_by' => $user->id,
            'accepted_at' => now(),
        ]);
    }

    public function reject(): void
    {
        $this->update(['status' => 'rejected']);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isAccepted(): bool
    {
        return $this->status === 'accepted';
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeForType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeForPlatform($query, string $platform)
    {
        return $query->where('platform', $platform);
    }
}
