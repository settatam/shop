<?php

namespace App\Traits;

use App\Models\Activity;
use App\Models\ActivityLog;
use App\Services\StoreContext;

trait LogsActivity
{
    /**
     * Boot the trait.
     */
    public static function bootLogsActivity(): void
    {
        static::created(function ($model) {
            $model->logActivity('create', $model->getActivityProperties('created'));
        });

        static::updated(function ($model) {
            if ($model->wasChanged()) {
                $model->logActivity('update', [
                    'old' => $model->getOriginal(),
                    'new' => $model->getChanges(),
                ]);
            }
        });

        static::deleted(function ($model) {
            $model->logActivity('delete', $model->getActivityProperties('deleted'));
        });
    }

    /**
     * Log an activity for this model.
     */
    public function logActivity(string $action, ?array $properties = null, ?string $description = null): ?ActivityLog
    {
        $activitySlug = $this->getActivitySlug($action);

        if (! $activitySlug) {
            return null;
        }

        // Skip logging if no store context is available
        $storeId = app(StoreContext::class)->getCurrentStoreId();
        if (! $storeId) {
            return null;
        }

        return ActivityLog::log(
            $activitySlug,
            $this,
            null,
            $properties,
            $description ?? $this->getActivityDescription($action)
        );
    }

    /**
     * Get the activity slug for this model and action.
     */
    protected function getActivitySlug(string $action): ?string
    {
        $map = $this->getActivityMap();

        return $map[$action] ?? null;
    }

    /**
     * Get the mapping of actions to activity slugs.
     * Override this in your model to customize.
     */
    protected function getActivityMap(): array
    {
        // Default mapping based on model name
        $prefix = $this->getActivityPrefix();

        return [
            'create' => "{$prefix}.create",
            'update' => "{$prefix}.update",
            'delete' => "{$prefix}.delete",
            'view' => "{$prefix}.view",
        ];
    }

    /**
     * Get the activity prefix for this model.
     * Override this in your model to customize.
     */
    protected function getActivityPrefix(): string
    {
        // Convert model class name to snake_case plural
        $className = class_basename($this);

        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $className)).'s';
    }

    /**
     * Get the properties to log for the given event.
     * Override this in your model to customize.
     */
    protected function getActivityProperties(string $event): array
    {
        $loggableAttributes = $this->getLoggableAttributes();

        if (empty($loggableAttributes)) {
            return $this->attributesToArray();
        }

        return collect($this->attributesToArray())
            ->only($loggableAttributes)
            ->toArray();
    }

    /**
     * Get attributes that should be logged.
     * Override this in your model to specify which attributes to log.
     * Return empty array to log all attributes.
     */
    protected function getLoggableAttributes(): array
    {
        return [];
    }

    /**
     * Get a human-readable description for the activity.
     * Override this in your model to customize.
     */
    protected function getActivityDescription(string $action): string
    {
        $modelName = class_basename($this);
        $identifier = $this->getActivityIdentifier();

        return match ($action) {
            'create' => "{$modelName} {$identifier} was created",
            'update' => "{$modelName} {$identifier} was updated",
            'delete' => "{$modelName} {$identifier} was deleted",
            default => "{$action} performed on {$modelName} {$identifier}",
        };
    }

    /**
     * Get the identifier for this model in activity descriptions.
     */
    protected function getActivityIdentifier(): string
    {
        return $this->name ?? $this->title ?? "#{$this->id}";
    }

    /**
     * Get activity logs for this model.
     */
    public function activityLogs()
    {
        return $this->morphMany(ActivityLog::class, 'subject');
    }
}
