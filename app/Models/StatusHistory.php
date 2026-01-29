<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StatusHistory extends Model
{
    protected $fillable = [
        'trackable_type',
        'trackable_id',
        'user_id',
        'from_status',
        'to_status',
        'notes',
    ];

    public function trackable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the human-readable to_status label based on the trackable model.
     */
    public function getToStatusLabelAttribute(): string
    {
        return $this->getStatusLabel($this->to_status);
    }

    /**
     * Get the human-readable from_status label based on the trackable model.
     */
    public function getFromStatusLabelAttribute(): ?string
    {
        if (! $this->from_status) {
            return null;
        }

        return $this->getStatusLabel($this->from_status);
    }

    /**
     * Get the status label from the trackable model.
     */
    protected function getStatusLabel(string $status): string
    {
        $trackable = $this->trackable;

        if ($trackable && method_exists($trackable, 'getAvailableStatuses')) {
            $statuses = $trackable::getAvailableStatuses();

            return $statuses[$status] ?? $status;
        }

        return $status;
    }
}
