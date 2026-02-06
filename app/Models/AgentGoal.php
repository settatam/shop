<?php

namespace App\Models;

use App\Enums\AgentGoalStatus;
use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AgentGoal extends Model
{
    use BelongsToStore, HasFactory;

    protected $fillable = [
        'store_id',
        'agent_id',
        'goal_type',
        'target_type',
        'target_id',
        'parameters',
        'status',
        'progress',
        'deadline_at',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'status' => AgentGoalStatus::class,
            'progress' => 'array',
            'deadline_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    public function isActive(): bool
    {
        return $this->status === AgentGoalStatus::Active;
    }

    public function complete(): void
    {
        $this->update([
            'status' => AgentGoalStatus::Completed,
        ]);
    }

    public function cancel(): void
    {
        $this->update([
            'status' => AgentGoalStatus::Cancelled,
        ]);
    }

    public function fail(): void
    {
        $this->update([
            'status' => AgentGoalStatus::Failed,
        ]);
    }

    public function updateProgress(array $progress): void
    {
        $currentProgress = $this->progress ?? [];

        $this->update([
            'progress' => array_merge($currentProgress, $progress),
        ]);
    }

    public function isOverdue(): bool
    {
        return $this->deadline_at && $this->deadline_at->isPast();
    }

    public function scopeActive($query)
    {
        return $query->where('status', AgentGoalStatus::Active);
    }

    public function scopeOverdue($query)
    {
        return $query->active()
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '<', now());
    }
}
