<?php

namespace App\Models;

use App\Enums\StatusableType;
use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Status extends Model
{
    use BelongsToStore, HasFactory, SoftDeletes;

    protected $fillable = [
        'store_id',
        'entity_type',
        'name',
        'slug',
        'color',
        'icon',
        'description',
        'is_default',
        'is_final',
        'is_system',
        'sort_order',
        'behavior',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_final' => 'boolean',
            'is_system' => 'boolean',
            'sort_order' => 'integer',
            'behavior' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Status $status) {
            // Ensure only one default per store/entity_type
            if ($status->is_default) {
                static::query()
                    ->where('store_id', $status->store_id)
                    ->where('entity_type', $status->entity_type)
                    ->where('id', '!=', $status->id ?? 0)
                    ->update(['is_default' => false]);
            }
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Get transitions where this status is the source.
     *
     * @return HasMany<StatusTransition, Status>
     */
    public function outgoingTransitions(): HasMany
    {
        return $this->hasMany(StatusTransition::class, 'from_status_id');
    }

    /**
     * Get transitions where this status is the target.
     *
     * @return HasMany<StatusTransition, Status>
     */
    public function incomingTransitions(): HasMany
    {
        return $this->hasMany(StatusTransition::class, 'to_status_id');
    }

    /**
     * Get automations for this status.
     *
     * @return HasMany<StatusAutomation, Status>
     */
    public function automations(): HasMany
    {
        return $this->hasMany(StatusAutomation::class)->orderBy('sort_order');
    }

    /**
     * Get actions for this status.
     *
     * @return HasMany<StatusAction, Status>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(StatusAction::class)->orderBy('sort_order');
    }

    /**
     * Get enabled bulk actions for this status.
     */
    public function getEnabledBulkActions(): Collection
    {
        return $this->actions()
            ->where('is_enabled', true)
            ->where('is_bulk', true)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Get enabled automations for a specific trigger.
     */
    public function getEnabledAutomations(string $trigger): Collection
    {
        return $this->automations()
            ->where('trigger', $trigger)
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Check if a behavior flag is enabled.
     */
    public function allows(string $action): bool
    {
        $behavior = $this->behavior ?? [];

        return $behavior[$action] ?? false;
    }

    /**
     * Check if this status can transition to the target status.
     */
    public function canTransitionTo(Status $target): bool
    {
        return $this->outgoingTransitions()
            ->where('to_status_id', $target->id)
            ->where('is_enabled', true)
            ->exists();
    }

    /**
     * Get all statuses this status can transition to.
     */
    public function getAvailableTargetStatuses(): Collection
    {
        $targetIds = $this->outgoingTransitions()
            ->where('is_enabled', true)
            ->pluck('to_status_id');

        return static::whereIn('id', $targetIds)->orderBy('sort_order')->get();
    }

    /**
     * Scope to filter by entity type.
     */
    public function scopeForEntity($query, StatusableType|string $entityType)
    {
        $type = $entityType instanceof StatusableType ? $entityType->value : $entityType;

        return $query->where('entity_type', $type);
    }

    /**
     * Scope to get the default status for an entity type.
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Scope to get non-final statuses.
     */
    public function scopeActive($query)
    {
        return $query->where('is_final', false);
    }

    /**
     * Get the default status for a store and entity type.
     */
    public static function getDefault(int $storeId, StatusableType|string $entityType): ?self
    {
        $type = $entityType instanceof StatusableType ? $entityType->value : $entityType;

        return static::query()
            ->where('store_id', $storeId)
            ->where('entity_type', $type)
            ->where('is_default', true)
            ->first();
    }

    /**
     * Find a status by slug for a store and entity type.
     */
    public static function findBySlug(int $storeId, StatusableType|string $entityType, string $slug): ?self
    {
        $type = $entityType instanceof StatusableType ? $entityType->value : $entityType;

        return static::query()
            ->where('store_id', $storeId)
            ->where('entity_type', $type)
            ->where('slug', $slug)
            ->first();
    }
}
