<?php

namespace App\Services\Marketplace\Contracts;

use App\Enums\Platform;
use App\Models\StoreMarketplace;
use App\Services\Marketplace\DTOs\InventoryUpdate;
use App\Services\Marketplace\DTOs\PlatformOrder;
use App\Services\Marketplace\DTOs\PlatformProduct;

interface PlatformConnectorInterface
{
    /**
     * Get the platform this connector supports.
     */
    public function getPlatform(): Platform;

    /**
     * Initialize the connector with a store marketplace connection.
     */
    public function initialize(StoreMarketplace $marketplace): self;

    /**
     * Test the connection to the platform.
     */
    public function testConnection(): bool;

    /**
     * Refresh OAuth tokens if needed.
     */
    public function refreshTokensIfNeeded(): bool;

    // ========================================
    // Product Operations
    // ========================================

    /**
     * Get all products from the platform.
     *
     * @return PlatformProduct[]
     */
    public function getProducts(int $limit = 250, ?string $cursor = null): array;

    /**
     * Get a single product by external ID.
     */
    public function getProduct(string $externalId): ?PlatformProduct;

    /**
     * Create a product on the platform.
     */
    public function createProduct(PlatformProduct $product): ?string;

    /**
     * Update a product on the platform.
     */
    public function updateProduct(string $externalId, PlatformProduct $product): bool;

    /**
     * Delete a product from the platform.
     */
    public function deleteProduct(string $externalId): bool;

    // ========================================
    // Order Operations
    // ========================================

    /**
     * Get orders from the platform.
     *
     * @return PlatformOrder[]
     */
    public function getOrders(?\DateTimeInterface $since = null, int $limit = 250): array;

    /**
     * Get a single order by external ID.
     */
    public function getOrder(string $externalId): ?PlatformOrder;

    /**
     * Update order fulfillment status.
     */
    public function fulfillOrder(string $externalId, array $fulfillmentData): bool;

    // ========================================
    // Inventory Operations
    // ========================================

    /**
     * Update inventory for a product/variant.
     */
    public function updateInventory(InventoryUpdate $update): bool;

    /**
     * Bulk update inventory.
     *
     * @param  InventoryUpdate[]  $updates
     */
    public function bulkUpdateInventory(array $updates): array;

    // ========================================
    // Category Operations
    // ========================================

    /**
     * Get available categories from the platform.
     */
    public function getCategories(): array;

    /**
     * Get category-specific attributes/fields.
     */
    public function getCategoryAttributes(string $categoryId): array;

    // ========================================
    // Utility Methods
    // ========================================

    /**
     * Get rate limit status.
     *
     * @return array{remaining: int, limit: int, reset_at: ?\DateTimeInterface}
     */
    public function getRateLimitStatus(): array;

    /**
     * Get the last error message.
     */
    public function getLastError(): ?string;
}
