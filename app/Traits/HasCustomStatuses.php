<?php

namespace App\Traits;

use App\Enums\StatusableType;
use App\Models\Status;
use App\Services\Statuses\StatusService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait HasCustomStatuses
{
    /**
     * Boot the trait.
     */
    public static function bootHasCustomStatuses(): void
    {
        static::creating(function ($model) {
            // Set default status_id if not provided and store_id is available
            if (empty($model->status_id) && ! empty($model->store_id)) {
                $entityType = $model->getStatusableType();
                $defaultStatus = Status::getDefault($model->store_id, $entityType);

                if ($defaultStatus) {
                    $model->status_id = $defaultStatus->id;
                    // Also set the legacy status field for backward compatibility
                    if (empty($model->status)) {
                        $model->status = $defaultStatus->slug;
                    }
                }
            }
        });
    }

    /**
     * Get the status relationship.
     */
    public function statusModel(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'status_id');
    }

    /**
     * Get the entity type for this model.
     */
    public function getStatusableType(): StatusableType
    {
        return StatusableType::fromModel(static::class);
    }

    /**
     * Get available status transitions from the current status.
     */
    public function getAvailableTransitions(): Collection
    {
        if (! $this->statusModel) {
            return new Collection;
        }

        return $this->statusModel->getAvailableTargetStatuses();
    }

    /**
     * Check if the entity can transition to a specific status.
     */
    public function canTransitionTo(Status|int|string $target): bool
    {
        $targetStatus = $this->resolveStatus($target);

        if (! $targetStatus || ! $this->statusModel) {
            return false;
        }

        return $this->statusModel->canTransitionTo($targetStatus);
    }

    /**
     * Transition to a new status.
     *
     * @param  array<string, mixed>  $data  Optional data for the transition
     */
    public function transitionTo(Status|int|string $target, array $data = []): bool
    {
        $targetStatus = $this->resolveStatus($target);

        if (! $targetStatus) {
            return false;
        }

        return app(StatusService::class)->transitionEntity($this, $targetStatus, $data);
    }

    /**
     * Get the current status model.
     */
    public function getCurrentStatus(): ?Status
    {
        return $this->statusModel;
    }

    /**
     * Check if the current status allows a specific action.
     */
    public function statusAllows(string $action): bool
    {
        if (! $this->statusModel) {
            return true; // Default to allowing if no status is set
        }

        return $this->statusModel->allows($action);
    }

    /**
     * Check if the entity is in a final status.
     */
    public function isInFinalStatus(): bool
    {
        return $this->statusModel?->is_final ?? false;
    }

    /**
     * Check if the entity is in a specific status by slug.
     */
    public function isInStatus(string $slug): bool
    {
        return $this->statusModel?->slug === $slug || $this->status === $slug;
    }

    /**
     * Sync the status_id with the legacy status string field.
     */
    public function syncStatusFromLegacy(): void
    {
        if (empty($this->status) || ! empty($this->status_id)) {
            return;
        }

        $status = Status::findBySlug($this->store_id, $this->getStatusableType(), $this->status);

        if ($status) {
            $this->status_id = $status->id;
            $this->saveQuietly();
        }
    }

    /**
     * Resolve a status from various input types.
     */
    protected function resolveStatus(Status|int|string $target): ?Status
    {
        if ($target instanceof Status) {
            return $target;
        }

        if (is_int($target)) {
            return Status::find($target);
        }

        // Treat as slug
        return Status::findBySlug($this->store_id, $this->getStatusableType(), $target);
    }

    /**
     * Scope to filter by custom status.
     */
    public function scopeWithStatus($query, Status|int|string $status)
    {
        if ($status instanceof Status) {
            return $query->where('status_id', $status->id);
        }

        if (is_int($status)) {
            return $query->where('status_id', $status);
        }

        // Filter by slug - need to join
        return $query->whereHas('statusModel', function ($q) use ($status) {
            $q->where('slug', $status);
        });
    }

    /**
     * Scope to filter by multiple status slugs.
     *
     * @param  array<string>  $slugs
     */
    public function scopeWithStatusIn($query, array $slugs)
    {
        return $query->whereHas('statusModel', function ($q) use ($slugs) {
            $q->whereIn('slug', $slugs);
        });
    }

    /**
     * Scope to filter entities that are not in final status.
     */
    public function scopeActive($query)
    {
        return $query->whereHas('statusModel', function ($q) {
            $q->where('is_final', false);
        });
    }

    /**
     * Scope to filter entities in final status.
     */
    public function scopeCompleted($query)
    {
        return $query->whereHas('statusModel', function ($q) {
            $q->where('is_final', true);
        });
    }
}
