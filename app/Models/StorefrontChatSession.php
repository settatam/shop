<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorefrontChatSession extends Model
{
    use BelongsToStore, HasFactory, HasUuids;

    protected $fillable = [
        'store_id',
        'store_marketplace_id',
        'visitor_id',
        'title',
        'last_message_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function marketplace(): BelongsTo
    {
        return $this->belongsTo(StoreMarketplace::class, 'store_marketplace_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(StorefrontChatMessage::class)->orderBy('created_at');
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
     * Touch the last_message_at timestamp and extend expiry.
     */
    public function touchLastMessage(): void
    {
        $this->update([
            'last_message_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    /**
     * Check if the session has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
}
