<?php

namespace App\Models;

use App\Enums\AgentPermissionLevel;
use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreAgent extends Model
{
    use BelongsToStore, HasFactory;

    protected $fillable = [
        'store_id',
        'agent_id',
        'is_enabled',
        'config',
        'permission_level',
        'last_run_at',
        'next_run_at',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'config' => 'array',
            'permission_level' => AgentPermissionLevel::class,
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }

    /**
     * Get merged config (agent defaults + store overrides).
     */
    public function getMergedConfig(): array
    {
        $defaultConfig = $this->agent->default_config ?? [];
        $storeConfig = $this->config ?? [];

        return array_merge($defaultConfig, $storeConfig);
    }

    public function canRun(): bool
    {
        return $this->is_enabled && ! $this->permission_level->isBlocked();
    }

    public function requiresApproval(): bool
    {
        return $this->permission_level->requiresApproval();
    }

    public function isAutoExecute(): bool
    {
        return $this->permission_level === AgentPermissionLevel::Auto;
    }

    public function markAsRun(): void
    {
        $this->update([
            'last_run_at' => now(),
        ]);
    }

    public function scheduleNextRun(?string $frequency = null): void
    {
        $config = $this->getMergedConfig();
        $frequency = $frequency ?? ($config['run_frequency'] ?? 'daily');

        $nextRun = match ($frequency) {
            'hourly' => now()->addHour(),
            'every_six_hours' => now()->addHours(6),
            'every_twelve_hours' => now()->addHours(12),
            'daily' => now()->addDay(),
            'weekly' => now()->addWeek(),
            'monthly' => now()->addMonth(),
            default => now()->addDay(),
        };

        $this->update(['next_run_at' => $nextRun]);
    }

    public function isDueForRun(): bool
    {
        if (! $this->canRun()) {
            return false;
        }

        if (! $this->next_run_at) {
            return true;
        }

        return $this->next_run_at->isPast();
    }

    /**
     * Get or create store agent configuration.
     */
    public static function getOrCreateForStore(Store $store, Agent $agent): self
    {
        return static::firstOrCreate(
            [
                'store_id' => $store->id,
                'agent_id' => $agent->id,
            ],
            [
                'is_enabled' => $agent->default_enabled,
                'permission_level' => AgentPermissionLevel::Approve,
            ]
        );
    }
}
