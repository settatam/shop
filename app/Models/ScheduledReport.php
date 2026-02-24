<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'report_type',
        'template_id',
        'name',
        'recipients',
        'schedule_time',
        'timezone',
        'schedule_days',
        'is_enabled',
        'last_sent_at',
        'last_failed_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'schedule_days' => 'array',
            'is_enabled' => 'boolean',
            'last_sent_at' => 'datetime',
            'last_failed_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'template_id');
    }

    /**
     * Check if the report should run today.
     */
    public function shouldRunToday(): bool
    {
        if (! $this->is_enabled) {
            return false;
        }

        // If no specific days set, run daily
        if (empty($this->schedule_days)) {
            return true;
        }

        // Check if today's day of week is in the schedule
        // 0 = Sunday, 1 = Monday, etc.
        $today = now($this->timezone)->dayOfWeek;

        return in_array($today, $this->schedule_days);
    }

    /**
     * Get the display name for this scheduled report.
     */
    public function getDisplayNameAttribute(): string
    {
        if ($this->name) {
            return $this->name;
        }

        return match ($this->report_type) {
            'legacy_daily_sales' => 'Daily Sales Report',
            'legacy_daily_buy' => 'Daily Buy Report',
            'daily_sales' => 'Daily Sales Report',
            'daily_buy' => 'Daily Buy Report',
            default => ucwords(str_replace('_', ' ', $this->report_type)),
        };
    }

    /**
     * Get the schedule description.
     */
    public function getScheduleDescriptionAttribute(): string
    {
        $time = \Carbon\Carbon::parse($this->schedule_time)->format('g:i A');

        if (empty($this->schedule_days)) {
            return "Daily at {$time} ({$this->timezone})";
        }

        $dayNames = [
            0 => 'Sun',
            1 => 'Mon',
            2 => 'Tue',
            3 => 'Wed',
            4 => 'Thu',
            5 => 'Fri',
            6 => 'Sat',
        ];

        $days = collect($this->schedule_days)
            ->sort()
            ->map(fn ($day) => $dayNames[$day] ?? $day)
            ->implode(', ');

        return "{$days} at {$time} ({$this->timezone})";
    }

    /**
     * Scope for enabled reports.
     */
    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Scope for reports due to run at a specific time.
     */
    public function scopeDueAt($query, string $time)
    {
        return $query->where('schedule_time', $time);
    }
}
