<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationChannel extends Model
{
    use BelongsToStore, HasFactory;

    public const TYPE_EMAIL = 'email';

    public const TYPE_SMS = 'sms';

    public const TYPE_PUSH = 'push';

    public const TYPE_SLACK = 'slack';

    public const TYPE_WEBHOOK = 'webhook';

    public const TYPES = [
        self::TYPE_EMAIL,
        self::TYPE_SMS,
        self::TYPE_PUSH,
        self::TYPE_SLACK,
        self::TYPE_WEBHOOK,
    ];

    protected $fillable = [
        'store_id',
        'type',
        'name',
        'settings',
        'is_enabled',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_enabled' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Get the setting value for a specific key.
     */
    public function getSetting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    /**
     * Set a specific setting value.
     */
    public function setSetting(string $key, mixed $value): self
    {
        $settings = $this->settings ?? [];
        data_set($settings, $key, $value);
        $this->settings = $settings;

        return $this;
    }

    /**
     * Scope to get enabled channels.
     */
    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Scope to get channels by type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Check if channel is configured properly.
     */
    public function isConfigured(): bool
    {
        return match ($this->type) {
            self::TYPE_EMAIL => true, // Uses default Laravel mail
            self::TYPE_SMS => ! empty($this->getSetting('provider')),
            self::TYPE_PUSH => ! empty($this->getSetting('provider')),
            self::TYPE_SLACK => ! empty($this->getSetting('webhook_url')),
            self::TYPE_WEBHOOK => ! empty($this->getSetting('url')),
            default => false,
        };
    }
}
