<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'chat_session_id',
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
        return $this->belongsTo(ChatSession::class, 'chat_session_id');
    }

    public function isFromUser(): bool
    {
        return $this->role === 'user';
    }

    public function isFromAssistant(): bool
    {
        return $this->role === 'assistant';
    }

    public function hasToolCalls(): bool
    {
        return ! empty($this->tool_calls);
    }

    /**
     * Format message for Claude API.
     */
    public function toClaudeFormat(): array
    {
        $message = [
            'role' => $this->role,
            'content' => $this->content,
        ];

        // Include tool results if this is an assistant message with tool calls
        if ($this->hasToolCalls() && $this->tool_results) {
            $message['content'] = [
                ['type' => 'text', 'text' => $this->content],
            ];
        }

        return $message;
    }
}
