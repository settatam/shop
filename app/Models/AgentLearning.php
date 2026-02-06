<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentLearning extends Model
{
    use BelongsToStore, HasFactory;

    protected $fillable = [
        'store_id',
        'agent_id',
        'learning_type',
        'context',
        'outcome',
        'success_score',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'outcome' => 'array',
            'success_score' => 'decimal:2',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * Record a learning outcome from an agent action.
     */
    public static function record(
        int $storeId,
        int $agentId,
        string $learningType,
        array $context,
        array $outcome,
        ?float $successScore = null
    ): self {
        return static::create([
            'store_id' => $storeId,
            'agent_id' => $agentId,
            'learning_type' => $learningType,
            'context' => $context,
            'outcome' => $outcome,
            'success_score' => $successScore,
        ]);
    }

    /**
     * Get average success score for a specific learning type.
     */
    public static function getAverageScore(int $storeId, int $agentId, string $learningType): ?float
    {
        return static::forStore($storeId)
            ->where('agent_id', $agentId)
            ->where('learning_type', $learningType)
            ->whereNotNull('success_score')
            ->avg('success_score');
    }

    public function scopeSuccessful($query, float $threshold = 0.7)
    {
        return $query->where('success_score', '>=', $threshold);
    }

    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}
