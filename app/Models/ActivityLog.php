<?php

namespace App\Models;

use App\Jobs\TriggerActivityNotifications;
use App\Services\StoreContext;
use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLog extends Model
{
    use BelongsToStore, HasFactory;

    protected $fillable = [
        'store_id',
        'user_id',
        'activity_slug',
        'subject_type',
        'subject_id',
        'causer_type',
        'causer_id',
        'properties',
        'description',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function causer(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the activity definition.
     */
    public function getActivityAttribute(): ?array
    {
        return Activity::getDefinitions()[$this->activity_slug] ?? null;
    }

    /**
     * Scope to filter by activity slug.
     */
    public function scopeForActivity(Builder $query, string $activity): Builder
    {
        return $query->where('activity_slug', $activity);
    }

    /**
     * Scope to filter by subject.
     */
    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey());
    }

    /**
     * Scope to filter by causer.
     */
    public function scopeCausedBy(Builder $query, Model $causer): Builder
    {
        return $query->where('causer_type', $causer->getMorphClass())
            ->where('causer_id', $causer->getKey());
    }

    /**
     * Scope to filter by date range.
     */
    public function scopeInDateRange(Builder $query, $from, $to = null): Builder
    {
        return $query->whereBetween('created_at', [$from, $to ?? now()]);
    }

    /**
     * Scope to filter by category.
     */
    public function scopeInCategory(Builder $query, string $category): Builder
    {
        $activities = Activity::getByCategory($category);

        return $query->whereIn('activity_slug', $activities);
    }

    /**
     * Log an activity.
     */
    public static function log(
        string $activity,
        ?Model $subject = null,
        ?Model $causer = null,
        ?array $properties = null,
        ?string $description = null,
        bool $triggerNotifications = true
    ): self {
        $storeId = app(StoreContext::class)->getCurrentStoreId();
        $request = request();

        $log = self::create([
            'store_id' => $storeId,
            'user_id' => auth()->id(),
            'activity_slug' => $activity,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'causer_type' => $causer?->getMorphClass() ?? (auth()->user()?->getMorphClass()),
            'causer_id' => $causer?->getKey() ?? auth()->id(),
            'properties' => $properties,
            'description' => $description,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);

        // Trigger notifications for this activity
        if ($triggerNotifications && $storeId) {
            TriggerActivityNotifications::dispatch($log);
        }

        return $log;
    }

    /**
     * Log with changes (old and new values).
     */
    public static function logWithChanges(
        string $activity,
        Model $subject,
        array $oldValues,
        array $newValues,
        ?string $description = null
    ): self {
        return self::log(
            $activity,
            $subject,
            null,
            [
                'old' => $oldValues,
                'new' => $newValues,
            ],
            $description
        );
    }
}
