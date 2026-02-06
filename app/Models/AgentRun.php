<?php

namespace App\Models;

use App\Enums\AgentRunStatus;
use App\Enums\AgentTriggerType;
use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentRun extends Model
{
    use BelongsToStore, HasFactory;

    protected $fillable = [
        'store_id',
        'agent_id',
        'store_agent_id',
        'status',
        'started_at',
        'completed_at',
        'trigger_type',
        'trigger_data',
        'summary',
        'error_message',
        'ai_usage_log_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => AgentRunStatus::class,
            'trigger_type' => AgentTriggerType::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'trigger_data' => 'array',
            'summary' => 'array',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function storeAgent(): BelongsTo
    {
        return $this->belongsTo(StoreAgent::class);
    }

    public function aiUsageLog(): BelongsTo
    {
        return $this->belongsTo(AiUsageLog::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(AgentAction::class);
    }

    public function start(): void
    {
        $this->update([
            'status' => AgentRunStatus::Running,
            'started_at' => now(),
        ]);
    }

    public function complete(array $summary = []): void
    {
        $this->update([
            'status' => AgentRunStatus::Completed,
            'completed_at' => now(),
            'summary' => $summary,
        ]);
    }

    public function fail(string $errorMessage): void
    {
        $this->update([
            'status' => AgentRunStatus::Failed,
            'completed_at' => now(),
            'error_message' => $errorMessage,
        ]);
    }

    public function cancel(): void
    {
        $this->update([
            'status' => AgentRunStatus::Cancelled,
            'completed_at' => now(),
        ]);
    }

    public function getDurationInSeconds(): ?int
    {
        if (! $this->started_at || ! $this->completed_at) {
            return null;
        }

        return $this->started_at->diffInSeconds($this->completed_at);
    }

    public function getPendingActionsCount(): int
    {
        return $this->actions()->where('status', 'pending')->count();
    }

    public function getExecutedActionsCount(): int
    {
        return $this->actions()->where('status', 'executed')->count();
    }

    public function scopeRecent($query, int $limit = 10)
    {
        return $query->orderBy('created_at', 'desc')->limit($limit);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }

    public function scopeSuccessful($query)
    {
        return $query->where('status', AgentRunStatus::Completed);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', AgentRunStatus::Failed);
    }
}
