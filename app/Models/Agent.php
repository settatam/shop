<?php

namespace App\Models;

use App\Enums\AgentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'type',
        'default_enabled',
        'default_config',
    ];

    protected function casts(): array
    {
        return [
            'type' => AgentType::class,
            'default_enabled' => 'boolean',
            'default_config' => 'array',
        ];
    }

    public function storeAgents(): HasMany
    {
        return $this->hasMany(StoreAgent::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }

    public function goals(): HasMany
    {
        return $this->hasMany(AgentGoal::class);
    }

    public function learnings(): HasMany
    {
        return $this->hasMany(AgentLearning::class);
    }

    public static function findBySlug(string $slug): ?self
    {
        return static::where('slug', $slug)->first();
    }

    public function isBackgroundAgent(): bool
    {
        return $this->type === AgentType::Background;
    }

    public function isEventTriggered(): bool
    {
        return $this->type === AgentType::EventTriggered;
    }

    public function isGoalOriented(): bool
    {
        return $this->type === AgentType::GoalOriented;
    }

    /**
     * Get the merged config for a store (agent defaults + store overrides).
     */
    public function getConfigForStore(Store $store): array
    {
        $storeAgent = $this->storeAgents()->where('store_id', $store->id)->first();

        $defaultConfig = $this->default_config ?? [];
        $storeConfig = $storeAgent?->config ?? [];

        return array_merge($defaultConfig, $storeConfig);
    }
}
