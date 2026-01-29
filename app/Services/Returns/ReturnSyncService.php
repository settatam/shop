<?php

namespace App\Services\Returns;

use App\Enums\Platform;
use App\Models\Order;
use App\Models\PlatformOrder;
use App\Models\ProductReturn;
use App\Models\StoreMarketplace;
use App\Services\Returns\Contracts\MarketplaceReturnSyncerInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReturnSyncService
{
    public function __construct(
        protected ReturnService $returnService
    ) {}

    public function importFromWebhook(array $payload, StoreMarketplace $marketplace, Platform $platform): ProductReturn
    {
        $syncer = $this->getMarketplaceSyncer($platform);

        if (! $syncer->supportsReturns()) {
            throw new InvalidArgumentException("Platform {$platform->value} does not support returns.");
        }

        $normalizedData = $syncer->normalizePayload($payload);

        return DB::transaction(function () use ($normalizedData, $marketplace, $platform) {
            $existingReturn = $this->findExistingReturn(
                $normalizedData['external_return_id'],
                $marketplace->id
            );

            if ($existingReturn) {
                return $this->updateExistingReturn($existingReturn, $normalizedData);
            }

            return $this->createReturnFromPayload($normalizedData, $marketplace, $platform);
        });
    }

    protected function findExistingReturn(string $externalReturnId, int $marketplaceId): ?ProductReturn
    {
        return ProductReturn::where('external_return_id', $externalReturnId)
            ->where('store_marketplace_id', $marketplaceId)
            ->first();
    }

    protected function updateExistingReturn(ProductReturn $return, array $data): ProductReturn
    {
        $return->update([
            'status' => $data['status'] ?? $return->status,
            'refund_amount' => $data['refund_amount'] ?? $return->refund_amount,
            'synced_at' => now(),
        ]);

        return $return->fresh();
    }

    protected function createReturnFromPayload(array $data, StoreMarketplace $marketplace, Platform $platform): ProductReturn
    {
        $order = $this->findRelatedOrder($data['external_order_id'] ?? null, $marketplace);

        $return = ProductReturn::create([
            'store_id' => $marketplace->store_id,
            'order_id' => $order?->id,
            'customer_id' => $order?->customer_id,
            'return_number' => ProductReturn::generateReturnNumber($marketplace->store_id),
            'status' => $this->mapStatus($data['status'] ?? 'pending'),
            'type' => $data['type'] ?? ProductReturn::TYPE_RETURN,
            'subtotal' => $data['subtotal'] ?? 0,
            'restocking_fee' => $data['restocking_fee'] ?? 0,
            'refund_amount' => $data['refund_amount'] ?? 0,
            'reason' => $data['reason'] ?? null,
            'customer_notes' => $data['customer_notes'] ?? null,
            'external_return_id' => $data['external_return_id'],
            'source_platform' => $platform->value,
            'store_marketplace_id' => $marketplace->id,
            'synced_at' => now(),
            'sync_status' => ProductReturn::SYNC_STATUS_SYNCED,
            'requested_at' => $data['requested_at'] ?? now(),
        ]);

        if (! empty($data['items'])) {
            foreach ($data['items'] as $itemData) {
                $this->createReturnItem($return, $itemData, $order);
            }
        }

        $return->calculateTotals();

        return $return->fresh(['items', 'order', 'customer']);
    }

    protected function findRelatedOrder(?string $externalOrderId, StoreMarketplace $marketplace): ?Order
    {
        if (! $externalOrderId) {
            return null;
        }

        $platformOrder = PlatformOrder::where('external_order_id', $externalOrderId)
            ->where('store_marketplace_id', $marketplace->id)
            ->first();

        return $platformOrder?->order;
    }

    protected function createReturnItem(ProductReturn $return, array $itemData, ?Order $order): void
    {
        $orderItem = null;

        if ($order && isset($itemData['external_line_item_id'])) {
            $orderItem = $order->items()
                ->whereJsonContains('metadata->external_line_item_id', $itemData['external_line_item_id'])
                ->first();
        }

        $return->items()->create([
            'order_item_id' => $orderItem?->id,
            'product_variant_id' => $orderItem?->product_variant_id,
            'quantity' => $itemData['quantity'] ?? 1,
            'unit_price' => $itemData['unit_price'] ?? $orderItem?->price ?? 0,
            'condition' => $itemData['condition'] ?? null,
            'reason' => $itemData['reason'] ?? null,
            'restock' => $itemData['restock'] ?? true,
        ]);
    }

    protected function mapStatus(string $externalStatus): string
    {
        return match (strtolower($externalStatus)) {
            'pending', 'requested', 'awaiting', 'open' => ProductReturn::STATUS_PENDING,
            'approved', 'accepted' => ProductReturn::STATUS_APPROVED,
            'processing', 'in_progress' => ProductReturn::STATUS_PROCESSING,
            'completed', 'closed', 'refunded' => ProductReturn::STATUS_COMPLETED,
            'rejected', 'denied', 'declined' => ProductReturn::STATUS_REJECTED,
            'cancelled', 'canceled' => ProductReturn::STATUS_CANCELLED,
            default => ProductReturn::STATUS_PENDING,
        };
    }

    public function syncToMarketplace(ProductReturn $return): void
    {
        if (! $return->store_marketplace_id) {
            return;
        }

        $marketplace = $return->marketplace;

        if (! $marketplace) {
            return;
        }

        try {
            $platform = Platform::from($marketplace->platform);
            $syncer = $this->getMarketplaceSyncer($platform);

            if ($syncer->supportsReturns()) {
                $syncer->syncReturn($return, $marketplace);
                $return->markAsSynced();
            }
        } catch (\Exception $e) {
            $return->markSyncFailed();
            throw $e;
        }
    }

    public function getMarketplaceSyncer(Platform $platform): MarketplaceReturnSyncerInterface
    {
        return match ($platform) {
            Platform::Shopify => app(\App\Services\Platforms\Shopify\ShopifyReturnSyncer::class),
            Platform::Ebay => app(\App\Services\Platforms\Ebay\EbayReturnSyncer::class),
            Platform::Amazon => app(\App\Services\Platforms\Amazon\AmazonReturnSyncer::class),
            Platform::Etsy => app(\App\Services\Platforms\Etsy\EtsyReturnSyncer::class),
            Platform::Walmart => app(\App\Services\Platforms\Walmart\WalmartReturnSyncer::class),
            Platform::WooCommerce => app(\App\Services\Platforms\WooCommerce\WooCommerceReturnSyncer::class),
        };
    }
}
