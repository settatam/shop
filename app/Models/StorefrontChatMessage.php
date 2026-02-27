<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorefrontChatMessage extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'storefront_chat_session_id',
        'role',
        'content',
        'tool_calls',
        'tool_results',
        'tokens_used',
    ];

    protected function casts(): array
    {
        return [
            'tool_calls' => 'array',
            'tool_results' => 'array',
            'tokens_used' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(StorefrontChatSession::class, 'storefront_chat_session_id');
    }

    public function isFromUser(): bool
    {
        return $this->role === 'user';
    }

    public function isFromAssistant(): bool
    {
        return $this->role === 'assistant';
    }
}
