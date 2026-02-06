<?php

namespace App\Models;

use App\Enums\AgentActionStatus;
use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AgentAction extends Model
{
    use BelongsToStore, HasFactory;

    protected $fillable = [
        'agent_run_id',
        'store_id',
        'action_type',
        'actionable_type',
        'actionable_id',
        'status',
        'requires_approval',
        'payload',
        'approved_by',
        'approved_at',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AgentActionStatus::class,
            'requires_approval' => 'boolean',
            'payload' => 'array',
            'approved_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }

    public function actionable(): MorphTo
    {
        return $this->morphTo();
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function approve(User $user): void
    {
        $this->update([
            'status' => AgentActionStatus::Approved,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);
    }

    public function reject(User $user): void
    {
        $this->update([
            'status' => AgentActionStatus::Rejected,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);
    }

    public function markAsExecuted(): void
    {
        $this->update([
            'status' => AgentActionStatus::Executed,
            'executed_at' => now(),
        ]);
    }

    public function markAsFailed(): void
    {
        $this->update([
            'status' => AgentActionStatus::Failed,
        ]);
    }

    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    public function canBeExecuted(): bool
    {
        if ($this->requires_approval) {
            return $this->status === AgentActionStatus::Approved;
        }

        return $this->status === AgentActionStatus::Pending;
    }

    /**
     * Get the before value from payload.
     */
    public function getBeforeValue(): mixed
    {
        return $this->payload['before'] ?? null;
    }

    /**
     * Get the after value from payload.
     */
    public function getAfterValue(): mixed
    {
        return $this->payload['after'] ?? null;
    }

    /**
     * Get the AI reasoning from payload.
     */
    public function getReasoning(): ?string
    {
        return $this->payload['reasoning'] ?? null;
    }

    public function scopePending($query)
    {
        return $query->where('status', AgentActionStatus::Pending);
    }

    public function scopeRequiringApproval($query)
    {
        return $query->where('requires_approval', true)
            ->where('status', AgentActionStatus::Pending);
    }

    public function scopeExecuted($query)
    {
        return $query->where('status', AgentActionStatus::Executed);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }
}
