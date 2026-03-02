<?php

namespace App\Models;

use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
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
        'customer_id',
        'visitor_id',
        'title',
        'status',
        'channel',
        'assigned_agent_id',
        'assigned_at',
        'closed_at',
        'external_thread_id',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'channel' => ConversationChannel::class,
            'assigned_at' => 'datetime',
            'closed_at' => 'datetime',
            'last_message_at' => 'datetime',
        ];
    }

    public function marketplace(): BelongsTo
    {
        return $this->belongsTo(StoreMarketplace::class, 'store_marketplace_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function linkCustomer(int $customerId): void
    {
        $this->update(['customer_id' => $customerId]);
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
     * Touch the last_message_at timestamp.
     */
    public function touchLastMessage(): void
    {
        $this->update([
            'last_message_at' => now(),
        ]);
    }

    public function assign(User $agent): void
    {
        $this->update([
            'status' => ConversationStatus::Assigned,
            'assigned_agent_id' => $agent->id,
            'assigned_at' => now(),
        ]);
    }

    public function release(): void
    {
        $this->update([
            'status' => ConversationStatus::Open,
            'assigned_agent_id' => null,
            'assigned_at' => null,
        ]);
    }

    public function close(): void
    {
        $this->update([
            'status' => ConversationStatus::Closed,
            'assigned_agent_id' => null,
            'assigned_at' => null,
            'closed_at' => now(),
        ]);
    }

    public function requestAgent(): void
    {
        $this->update([
            'status' => ConversationStatus::WaitingForAgent,
        ]);
    }

    public function isAssigned(): bool
    {
        return $this->status === ConversationStatus::Assigned;
    }

    public function isOpen(): bool
    {
        return $this->status === ConversationStatus::Open;
    }
}
