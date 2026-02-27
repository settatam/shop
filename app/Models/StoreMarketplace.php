<?php

namespace App\Models;

use App\Enums\Platform;
use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StoreMarketplace extends Model
{
    use BelongsToStore, HasFactory, SoftDeletes;

    protected $fillable = [
        'store_id',
        'platform',
        'name',
        'shop_domain',
        'external_store_id',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'credentials',
        'settings',
        'status',
        'is_app',
        'connected_successfully',
        'last_error',
        'last_sync_at',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
        'credentials',
    ];

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'token_expires_at' => 'datetime',
            'last_sync_at' => 'datetime',
            'is_app' => 'boolean',
            'connected_successfully' => 'boolean',
        ];
    }

    /**
     * Scope to only selling platforms (not apps).
     */
    public function scopeSellingPlatforms($query)
    {
        return $query->where('is_app', false);
    }

    /**
     * Scope to only apps (not selling platforms).
     */
    public function scopeApps($query)
    {
        return $query->where('is_app', true);
    }

    /**
     * Scope to only successfully connected marketplaces.
     */
    public function scopeConnected($query)
    {
        return $query->where('connected_successfully', true);
    }

    /**
     * Scope for active selling platforms that are connected.
     */
    public function scopeActiveSalesChannels($query)
    {
        return $query->sellingPlatforms()
            ->connected()
            ->where('status', 'active');
    }

    public function policies(): HasMany
    {
        return $this->hasMany(MarketplacePolicy::class);
    }

    public function metafieldDefinitions(): HasMany
    {
        return $this->hasMany(ShopifyMetafieldDefinition::class);
    }

    public function storefrontApiTokens(): HasMany
    {
        return $this->hasMany(StorefrontApiToken::class);
    }

    public function storefrontChatSessions(): HasMany
    {
        return $this->hasMany(StorefrontChatSession::class);
    }

    public function salesChannels(): HasMany
    {
        return $this->hasMany(SalesChannel::class);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(PlatformListing::class, 'store_marketplace_id');
    }

    public function platformOrders(): HasMany
    {
        return $this->hasMany(PlatformOrder::class, 'store_marketplace_id');
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class, 'store_marketplace_id');
    }

    public function webhookLogs(): HasMany
    {
        return $this->hasMany(WebhookLog::class, 'store_marketplace_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isTokenExpired(): bool
    {
        if (! $this->token_expires_at) {
            return false;
        }

        return $this->token_expires_at->isPast();
    }

    public function markAsError(string $error): void
    {
        $this->update([
            'status' => 'error',
            'last_error' => $error,
        ]);
    }

    public function markAsActive(): void
    {
        $this->update([
            'status' => 'active',
            'last_error' => null,
        ]);
    }

    public function recordSync(): void
    {
        $this->update(['last_sync_at' => now()]);
    }
}
