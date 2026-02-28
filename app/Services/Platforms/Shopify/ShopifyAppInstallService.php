<?php

namespace App\Services\Platforms\Shopify;

use App\Enums\Platform;
use App\Jobs\ImportShopifyProductsJob;
use App\Models\Role;
use App\Models\Store;
use App\Models\StorefrontApiToken;
use App\Models\StoreMarketplace;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ShopifyAppInstallService
{
    /**
     * Provision a Shopmata store from a Shopify app install.
     *
     * Idempotent: if the shop already has an active marketplace, the token is updated and the existing marketplace is returned.
     *
     * @param  array<string, mixed>  $tokenData
     */
    public function provision(string $shopDomain, string $accessToken, array $tokenData = []): StoreMarketplace
    {
        $shopDomain = $this->normalizeShopDomain($shopDomain);

        // Idempotency: check for existing active connection
        $existing = StoreMarketplace::where('platform', Platform::Shopify)
            ->where('shop_domain', $shopDomain)
            ->first();

        if ($existing) {
            return $this->handleReinstall($existing, $accessToken, $tokenData);
        }

        return DB::transaction(function () use ($shopDomain, $accessToken, $tokenData) {
            $user = $this->createHeadlessUser($shopDomain);
            $store = $this->createStore($user, $shopDomain);
            $this->createOwnerRoleAndStoreUser($store, $user);

            $marketplace = $this->createMarketplace($store, $shopDomain, $accessToken, $tokenData);
            $this->createStorefrontApiToken($marketplace);

            ImportShopifyProductsJob::dispatch($marketplace);

            Log::info('Shopify app provisioned new store', [
                'shop_domain' => $shopDomain,
                'store_id' => $store->id,
                'marketplace_id' => $marketplace->id,
            ]);

            return $marketplace;
        });
    }

    protected function handleReinstall(StoreMarketplace $marketplace, string $accessToken, array $tokenData): StoreMarketplace
    {
        $marketplace->update([
            'access_token' => $accessToken,
            'credentials' => array_merge($marketplace->credentials ?? [], [
                'scope' => $tokenData['scope'] ?? null,
            ]),
            'status' => 'active',
        ]);

        // Re-enable storefront tokens
        StorefrontApiToken::where('store_marketplace_id', $marketplace->id)
            ->update(['is_active' => true]);

        Log::info('Shopify app reinstalled — marketplace reactivated', [
            'shop_domain' => $marketplace->shop_domain,
            'marketplace_id' => $marketplace->id,
        ]);

        return $marketplace->fresh();
    }

    protected function createHeadlessUser(string $shopDomain): User
    {
        $slug = Str::slug(str_replace('.myshopify.com', '', $shopDomain));

        return User::create([
            'name' => $slug,
            'email' => "{$slug}@shopify-app.shopmata.internal",
            'password' => Str::random(32),
        ]);
    }

    protected function createStore(User $user, string $shopDomain): Store
    {
        $shopName = str_replace('.myshopify.com', '', $shopDomain);

        return Store::create([
            'user_id' => $user->id,
            'name' => $shopName,
            'slug' => Str::slug($shopName).'-'.Str::random(6),
            'business_name' => $shopName,
            'account_email' => $user->email,
            'customer_email' => $user->email,
            'step' => 2,
            'edition' => 'shopify-app',
            'is_active' => true,
        ]);
    }

    protected function createOwnerRoleAndStoreUser(Store $store, User $user): void
    {
        Role::createDefaultRoles($store->id);

        $ownerRole = Role::where('store_id', $store->id)
            ->where('slug', Role::OWNER)
            ->first();

        StoreUser::create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $ownerRole->id,
            'is_owner' => true,
            'status' => 'active',
            'first_name' => 'Shopify',
            'last_name' => 'App',
            'email' => $user->email,
        ]);
    }

    protected function createMarketplace(Store $store, string $shopDomain, string $accessToken, array $tokenData): StoreMarketplace
    {
        return StoreMarketplace::create([
            'store_id' => $store->id,
            'platform' => Platform::Shopify,
            'name' => $shopDomain,
            'shop_domain' => $shopDomain,
            'access_token' => $accessToken,
            'credentials' => [
                'scope' => $tokenData['scope'] ?? null,
                'webhook_secret' => config('services.shopify.webhook_secret'),
            ],
            'settings' => [],
            'status' => 'active',
            'is_app' => true,
        ]);
    }

    protected function createStorefrontApiToken(StoreMarketplace $marketplace): StorefrontApiToken
    {
        return StorefrontApiToken::firstOrCreate(
            [
                'store_id' => $marketplace->store_id,
                'store_marketplace_id' => $marketplace->id,
            ],
            [
                'token' => StorefrontApiToken::generateToken(),
                'name' => 'Default',
                'is_active' => true,
            ]
        );
    }

    protected function normalizeShopDomain(string $domain): string
    {
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim($domain, '/');

        if (! str_contains($domain, '.myshopify.com')) {
            $domain .= '.myshopify.com';
        }

        return $domain;
    }
}
