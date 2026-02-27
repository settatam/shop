<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorefrontApiToken extends Model
{
    use BelongsToStore, HasFactory;

    protected $fillable = [
        'store_id',
        'store_marketplace_id',
        'token',
        'name',
        'is_active',
        'settings',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'array',
            'last_used_at' => 'datetime',
        ];
    }

    public function marketplace(): BelongsTo
    {
        return $this->belongsTo(StoreMarketplace::class, 'store_marketplace_id');
    }

    /**
     * Generate a secure random token.
     */
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Touch the last_used_at timestamp.
     */
    public function touchLastUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }

    /**
     * Get a widget setting with optional default.
     */
    public function getSetting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }
}
