<?php

namespace App\Traits;

use App\Models\Status;
use App\Models\StatusHistory;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait TracksStatusChanges
{
    /**
     * Boot the trait.
     */
    public static function bootTracksStatusChanges(): void
    {
        static::created(function ($model) {
            // Track status_id (modern) or status (legacy)
            if ($model->status_id) {
                $status = Status::find($model->status_id);
                $model->recordStatusChange(null, $status?->slug ?? (string) $model->status_id);
            } elseif ($model->status) {
                $model->recordStatusChange(null, $model->status);
            }
        });

        static::updating(function ($model) {
            // Track status_id changes (modern Status model)
            if ($model->isDirty('status_id')) {
                $fromStatus = null;
                $toStatus = null;

                if ($model->getOriginal('status_id')) {
                    $from = Status::find($model->getOriginal('status_id'));
                    $fromStatus = $from?->slug ?? (string) $model->getOriginal('status_id');
                }

                if ($model->status_id) {
                    $to = Status::find($model->status_id);
                    $toStatus = $to?->slug ?? (string) $model->status_id;
                }

                if ($toStatus) {
                    $model->recordStatusChange($fromStatus, $toStatus);
                }
            }
            // Also track legacy status field changes
            elseif ($model->isDirty('status')) {
                $model->recordStatusChange(
                    $model->getOriginal('status'),
                    $model->status
                );
            }
        });
    }

    /**
     * Get the status history for this model.
     */
    public function statusHistories(): MorphMany
    {
        return $this->morphMany(StatusHistory::class, 'trackable')->orderBy('created_at', 'desc');
    }

    /**
     * Record a status change in the history.
     */
    public function recordStatusChange(?string $fromStatus, string $toStatus, ?string $notes = null): StatusHistory
    {
        return $this->statusHistories()->create([
            'user_id' => auth()->id(),
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'notes' => $notes,
        ]);
    }

    /**
     * Get the latest status history entry.
     */
    public function latestStatusHistory(): ?StatusHistory
    {
        return $this->statusHistories()->first();
    }
}
