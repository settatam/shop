<?php

namespace App\Models;

use App\Traits\HasAddresses;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Store extends Model
{
    use HasAddresses, HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::created(function (Store $store) {
            // Create default "In Store" sales channel for every new store
            SalesChannel::create([
                'store_id' => $store->id,
                'name' => 'In Store',
                'code' => 'in_store',
                'type' => SalesChannel::TYPE_LOCAL,
                'is_local' => true,
                'is_active' => true,
                'is_default' => true,
                'sort_order' => 0,
            ]);
        });
    }

    protected $fillable = [
        'user_id',
        'slug',
        'url',
        'name',
        'logo',
        'account_email',
        'customer_email',
        'business_name',
        'address',
        'address2',
        'city',
        'state',
        'country_id',
        'timezone_id',
        'unit_id',
        'default_weight_unit_id',
        'currency_id',
        'theme_id',
        'is_active',
        'meta_description',
        'meta_title',
        'phone',
        'zip',
        'store_domain',
        'industry_id',
        'order_id_suffix',
        'order_id_prefix',
        'buy_id_prefix',
        'buy_id_suffix',
        'repair_id_prefix',
        'repair_id_suffix',
        'memo_id_prefix',
        'memo_id_suffix',
        'gift_card_should_expire',
        'gift_card_expire_after',
        'gift_card_expiry_duration',
        'store_plan_id',
        'last_payment_date',
        'next_payment_date',
        'allow_guest_checkout',
        'login_wall',
        'payment_system_id',
        'enable_store_pickup',
        'enable_pay_on_delivery',
        'step',
        'state_id',
        'jewelry_module_enabled',
        'has_custom_product_module',
        'default_tax_rate',
        'tax_id_number',
        'edition',
        'metal_price_settings',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'gift_card_should_expire' => 'boolean',
            'allow_guest_checkout' => 'boolean',
            'login_wall' => 'boolean',
            'enable_store_pickup' => 'boolean',
            'enable_pay_on_delivery' => 'boolean',
            'jewelry_module_enabled' => 'boolean',
            'has_custom_product_module' => 'boolean',
            'last_payment_date' => 'datetime',
            'next_payment_date' => 'datetime',
            'default_tax_rate' => 'decimal:4',
            'metal_price_settings' => 'array',
        ];
    }

    /**
     * Get the DWT multiplier for a specific metal type.
     * These multipliers are used with spot price per oz: buy_price = multiplier * spot_per_oz * dwt
     * Returns null if no multiplier is set (spot price should be used as-is).
     */
    public function getDwtMultiplier(string $metalType): ?float
    {
        $settings = $this->metal_price_settings ?? [];
        $multipliers = $settings['dwt_multipliers'] ?? [];

        // Only return multiplier if explicitly set for this metal type
        if (isset($multipliers[$metalType])) {
            return (float) $multipliers[$metalType];
        }

        // Return null if no multiplier is set - spot price will be used as-is
        return null;
    }

    /**
     * Get all metal price settings with defaults.
     *
     * @return array<string, mixed>
     */
    public function getMetalPriceSettingsWithDefaults(): array
    {
        $defaults = [
            'dwt_multipliers' => MetalPrice::DEFAULT_DWT_MULTIPLIERS,
        ];

        return array_replace_recursive($defaults, $this->metal_price_settings ?? []);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'store_users')
            ->withPivot(['role_id', 'status', 'first_name', 'last_name', 'email'])
            ->withTimestamps();
    }

    public function storeUsers(): HasMany
    {
        return $this->hasMany(StoreUser::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function salesChannels(): HasMany
    {
        return $this->hasMany(SalesChannel::class);
    }

    public function binLocations(): HasMany
    {
        return $this->hasMany(BinLocation::class);
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function printerSettings(): HasMany
    {
        return $this->hasMany(PrinterSetting::class);
    }

    public function labelTemplates(): HasMany
    {
        return $this->hasMany(LabelTemplate::class);
    }

    public function hasJewelryModule(): bool
    {
        return $this->jewelry_module_enabled;
    }

    public function hasCustomProductModule(): bool
    {
        return $this->has_custom_product_module;
    }

    public function ebayCategories(): BelongsToMany
    {
        return $this->belongsToMany(EbayCategory::class, 'store_ebay_categories')
            ->withTimestamps();
    }

    public function needsOnboarding(): bool
    {
        return $this->step < 2;
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(StoreIntegration::class);
    }

    public function paypalIntegration(): ?StoreIntegration
    {
        return $this->integrations()
            ->where('provider', StoreIntegration::PROVIDER_PAYPAL)
            ->where('status', StoreIntegration::STATUS_ACTIVE)
            ->first();
    }

    public function fedexIntegration(): ?StoreIntegration
    {
        return $this->integrations()
            ->where('provider', StoreIntegration::PROVIDER_FEDEX)
            ->where('status', StoreIntegration::STATUS_ACTIVE)
            ->first();
    }

    public function transactionPayouts(): HasMany
    {
        return $this->hasMany(TransactionPayout::class);
    }

    public function payoutExports(): HasMany
    {
        return $this->hasMany(PayoutExport::class);
    }

    public function buckets(): HasMany
    {
        return $this->hasMany(Bucket::class);
    }

    public function storeAgents(): HasMany
    {
        return $this->hasMany(StoreAgent::class);
    }

    public function agentRuns(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }

    public function agentActions(): HasMany
    {
        return $this->hasMany(AgentAction::class);
    }

    public function agentGoals(): HasMany
    {
        return $this->hasMany(AgentGoal::class);
    }

    public function agentLearnings(): HasMany
    {
        return $this->hasMany(AgentLearning::class);
    }

    /**
     * Check if this store has the online buys workflow enabled.
     * Stores 43 and 44 have this by default, or any store with the setting enabled.
     */
    public function hasOnlineBuysWorkflow(): bool
    {
        // Hardcoded stores that have this workflow
        $onlineBuysStores = [43, 44];

        if (in_array($this->id, $onlineBuysStores)) {
            return true;
        }

        // Check for store setting (can be enabled via admin)
        return (bool) $this->getSetting('online_buys_workflow', false);
    }

    /**
     * Get a store setting value.
     */
    public function getSetting(string $key, mixed $default = null): mixed
    {
        // Check if store has a settings relationship or JSON column
        // For now we'll check the metal_price_settings array or a dedicated settings column
        $settings = $this->metal_price_settings ?? [];

        return $settings[$key] ?? $default;
    }

    /**
     * Get the features available for this store based on its edition.
     *
     * @return array<string>
     */
    public function getFeatures(): array
    {
        return app(\App\Services\FeatureManager::class)->getFeaturesForStore($this);
    }

    /**
     * Check if this store has a specific feature.
     */
    public function hasFeature(string $feature): bool
    {
        return app(\App\Services\FeatureManager::class)->storeHasFeature($this, $feature);
    }

    /**
     * Check if this store has all of the specified features.
     *
     * @param  array<string>  $features
     */
    public function hasAllFeatures(array $features): bool
    {
        return app(\App\Services\FeatureManager::class)->storeHasAllFeatures($this, $features);
    }

    /**
     * Check if this store has any of the specified features.
     *
     * @param  array<string>  $features
     */
    public function hasAnyFeature(array $features): bool
    {
        return app(\App\Services\FeatureManager::class)->storeHasAnyFeature($this, $features);
    }

    /**
     * Get the edition info for this store.
     *
     * @return array{name: string, description: string, features: array<string>}|null
     */
    public function getEditionInfo(): ?array
    {
        return app(\App\Services\FeatureManager::class)->getEditionInfo($this);
    }
}
