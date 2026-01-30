<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StoreIntegration extends Model
{
    use BelongsToStore, HasFactory, SoftDeletes;

    public const PROVIDER_PAYPAL = 'paypal';

    public const PROVIDER_FEDEX = 'fedex';

    public const PROVIDER_QUICKBOOKS = 'quickbooks';

    public const PROVIDER_TWILIO = 'twilio';

    public const PROVIDER_GIA = 'gia';

    public const PROVIDER_SHIPSTATION = 'shipstation';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_ERROR = 'error';

    public const ENV_SANDBOX = 'sandbox';

    public const ENV_PRODUCTION = 'production';

    protected $fillable = [
        'store_id',
        'provider',
        'name',
        'environment',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'credentials',
        'settings',
        'status',
        'last_error',
        'last_used_at',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
        'credentials',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'token_expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(TransactionPayout::class, 'store_id', 'store_id')
            ->where('provider', $this->provider);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isSandbox(): bool
    {
        return $this->environment === self::ENV_SANDBOX;
    }

    public function isProduction(): bool
    {
        return $this->environment === self::ENV_PRODUCTION;
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
            'status' => self::STATUS_ERROR,
            'last_error' => $error,
        ]);
    }

    public function markAsActive(): void
    {
        $this->update([
            'status' => self::STATUS_ACTIVE,
            'last_error' => null,
        ]);
    }

    public function recordUsage(): void
    {
        $this->update(['last_used_at' => now()]);
    }

    public function getClientId(): ?string
    {
        return $this->credentials['client_id'] ?? null;
    }

    public function getClientSecret(): ?string
    {
        return $this->credentials['client_secret'] ?? null;
    }

    public function getAccountNumber(): ?string
    {
        return $this->credentials['account_number'] ?? null;
    }

    public function getApiBaseUrl(): string
    {
        if ($this->provider === self::PROVIDER_PAYPAL) {
            return $this->isSandbox()
                ? 'https://api-m.sandbox.paypal.com'
                : 'https://api-m.paypal.com';
        }

        if ($this->provider === self::PROVIDER_FEDEX) {
            return $this->isSandbox()
                ? 'https://apis-sandbox.fedex.com'
                : 'https://apis.fedex.com';
        }

        return '';
    }

    /**
     * Get Twilio Account SID.
     */
    public function getAccountSid(): ?string
    {
        return $this->credentials['account_sid'] ?? null;
    }

    /**
     * Get Twilio Auth Token.
     */
    public function getAuthToken(): ?string
    {
        return $this->credentials['auth_token'] ?? null;
    }

    /**
     * Get Twilio Phone Number.
     */
    public function getPhoneNumber(): ?string
    {
        return $this->credentials['phone_number'] ?? null;
    }

    /**
     * Get Twilio Messaging Service SID (optional).
     */
    public function getMessagingServiceSid(): ?string
    {
        return $this->credentials['messaging_service_sid'] ?? null;
    }

    /**
     * Find active integration for a store by provider.
     */
    public static function findActiveForStore(int $storeId, string $provider): ?self
    {
        return static::where('store_id', $storeId)
            ->where('provider', $provider)
            ->where('status', self::STATUS_ACTIVE)
            ->first();
    }

    /**
     * Get GIA API Key.
     */
    public function getGiaApiKey(): ?string
    {
        return $this->credentials['api_key'] ?? null;
    }

    /**
     * Get GIA API URL (defaults to production).
     */
    public function getGiaApiUrl(): string
    {
        $default = 'https://api.gia.edu/graphql';

        return $this->credentials['api_url'] ?? $default;
    }

    /**
     * Get ShipStation API Key.
     */
    public function getShipStationApiKey(): ?string
    {
        return $this->credentials['api_key'] ?? null;
    }

    /**
     * Get ShipStation API Secret.
     */
    public function getShipStationApiSecret(): ?string
    {
        return $this->credentials['api_secret'] ?? null;
    }

    /**
     * Get ShipStation Store ID (optional, for multi-store accounts).
     */
    public function getShipStationStoreId(): ?int
    {
        $storeId = $this->credentials['store_id'] ?? null;

        return $storeId ? (int) $storeId : null;
    }

    /**
     * Check if auto-sync to ShipStation is enabled.
     */
    public function isShipStationAutoSyncEnabled(): bool
    {
        return (bool) ($this->settings['auto_sync_orders'] ?? true);
    }

    /**
     * Get ShipStation API base URL.
     */
    public function getShipStationApiUrl(): string
    {
        return 'https://ssapi.shipstation.com';
    }
}
