<?php

namespace App\Traits;

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
            if ($model->status) {
                $model->recordStatusChange(null, $model->status);
            }
        });

        static::updating(function ($model) {
            if ($model->isDirty('status')) {
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
