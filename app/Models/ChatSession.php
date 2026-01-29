<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatSession extends Model
{
    use BelongsToStore, HasFactory, HasUuids;

    protected $fillable = [
        'store_id',
        'user_id',
        'title',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class)->orderBy('created_at');
    }

    public function latestMessages(int $limit = 10): HasMany
    {
        return $this->hasMany(ChatMessage::class)
            ->orderByDesc('created_at')
            ->limit($limit);
    }

    /**
     * Generate a title from the first user message.
     */
    public function generateTitle(): void
    {
        if ($this->title) {
            return;
        }

        $firstMessage = $this->messages()->where('role', 'user')->first();

        if ($firstMessage) {
            $this->update([
                'title' => str($firstMessage->content)->limit(50)->toString(),
            ]);
        }
    }

    /**
     * Touch the last_message_at timestamp.
     */
    public function touchLastMessage(): void
    {
        $this->update(['last_message_at' => now()]);
    }
}
