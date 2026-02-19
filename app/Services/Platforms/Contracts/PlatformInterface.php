<?php

namespace App\Services\Platforms\Contracts;

use App\Models\PlatformListing;
use App\Models\PlatformOrder;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreMarketplace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

interface PlatformInterface
{
    /**
     * Get the platform identifier.
     */
    public function getPlatform(): string;

    /**
     * Initiate OAuth connection flow.
     */
    public function connect(Store $store, array $params = []): RedirectResponse;

    /**
     * Handle OAuth callback and create connection.
     */
    public function handleCallback(Request $request, Store $store): StoreMarketplace;

    /**
     * Disconnect and revoke access.
     */
    public function disconnect(StoreMarketplace $connection): void;

    /**
     * Refresh access token if expired.
     */
    public function refreshToken(StoreMarketplace $connection): StoreMarketplace;

    /**
     * Validate connection credentials are still valid.
     */
    public function validateCredentials(StoreMarketplace $connection): bool;

    /**
     * Pull products from platform into local inventory.
     */
    public function pullProducts(StoreMarketplace $connection): Collection;

    /**
     * Push a product to the platform.
     */
    public function pushProduct(Product $product, StoreMarketplace $connection): PlatformListing;

    /**
     * Update an existing listing on the platform.
     */
    public function updateListing(PlatformListing $listing): PlatformListing;

    /**
     * Delete/unpublish a listing from the platform.
     */
    public function deleteListing(PlatformListing $listing): void;

    /**
     * Unlist a product from the platform (set to inactive/draft but keep the listing).
     * This allows the listing to be relisted later without recreating it.
     */
    public function unlistListing(PlatformListing $listing): PlatformListing;

    /**
     * Relist a previously unlisted product on the platform.
     */
    public function relistListing(PlatformListing $listing): PlatformListing;

    /**
     * Sync inventory quantities to platform.
     */
    public function syncInventory(StoreMarketplace $connection): void;

    /**
     * Pull orders from platform.
     */
    public function pullOrders(StoreMarketplace $connection, ?string $since = null): Collection;

    /**
     * Update order fulfillment status on platform.
     */
    public function updateOrderFulfillment(PlatformOrder $order, array $fulfillmentData): void;

    /**
     * Get platform-specific categories for mapping.
     */
    public function getCategories(StoreMarketplace $connection): Collection;

    /**
     * Get webhook URL for this platform.
     */
    public function getWebhookUrl(StoreMarketplace $connection): string;

    /**
     * Register webhooks with the platform.
     */
    public function registerWebhooks(StoreMarketplace $connection): void;

    /**
     * Handle incoming webhook from platform.
     */
    public function handleWebhook(Request $request, StoreMarketplace $connection): void;
}
