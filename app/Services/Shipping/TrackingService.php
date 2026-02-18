<?php

namespace App\Services\Shipping;

use App\Models\Store;
use App\Models\Transaction;
use App\Services\Shipping\Contracts\TrackingProviderInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class TrackingService
{
    /**
     * Update tracking for all active shipments across all stores.
     *
     * @return array{outbound_updated: int, return_updated: int, errors: int}
     */
    public function updateAllActiveShipments(): array
    {
        $stats = ['outbound_updated' => 0, 'return_updated' => 0, 'errors' => 0];

        // Get all stores with active shipments
        $storeIds = Transaction::query()
            ->where(function ($q) {
                $q->where('type', Transaction::TYPE_MAIL_IN)
                    ->orWhere('source', Transaction::SOURCE_ONLINE);
            })
            ->where(function ($q) {
                // Outbound shipments awaiting delivery
                $q->where(function ($sub) {
                    $sub->whereNotNull('outbound_tracking_number')
                        ->whereNotNull('kit_sent_at')
                        ->whereNull('kit_delivered_at');
                })
                // Or return shipments in transit
                    ->orWhere(function ($sub) {
                        $sub->whereNotNull('return_tracking_number')
                            ->whereNotNull('return_shipped_at')
                            ->whereNull('return_delivered_at');
                    });
            })
            ->whereNotIn('status', [
                Transaction::STATUS_CANCELLED,
                Transaction::STATUS_ITEMS_RETURNED,
            ])
            ->distinct()
            ->pluck('store_id');

        foreach ($storeIds as $storeId) {
            $store = Store::find($storeId);
            if (! $store) {
                continue;
            }

            try {
                $result = $this->updateStoreShipments($store);
                $stats['outbound_updated'] += $result['outbound_updated'];
                $stats['return_updated'] += $result['return_updated'];
                $stats['errors'] += $result['errors'];
            } catch (\Exception $e) {
                Log::error('Failed to update tracking for store', [
                    'store_id' => $storeId,
                    'error' => $e->getMessage(),
                ]);
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Update tracking for all active shipments for a specific store.
     *
     * @return array{outbound_updated: int, return_updated: int, errors: int}
     */
    public function updateStoreShipments(Store $store): array
    {
        $stats = ['outbound_updated' => 0, 'return_updated' => 0, 'errors' => 0];

        // Get configured providers for this store
        $providers = TrackingProviderFactory::getConfiguredProviders($store);

        if (empty($providers)) {
            Log::info('No tracking providers configured for store', ['store_id' => $store->id]);

            return $stats;
        }

        // Update outbound kits
        $outboundResult = $this->updateOutboundKits($store, $providers);
        $stats['outbound_updated'] = $outboundResult['updated'];
        $stats['errors'] += $outboundResult['errors'];

        // Update return shipments
        $returnResult = $this->updateReturnShipments($store, $providers);
        $stats['return_updated'] = $returnResult['updated'];
        $stats['errors'] += $returnResult['errors'];

        return $stats;
    }

    /**
     * Update tracking for outbound kits (sent to customers, awaiting delivery).
     *
     * @param  array<string, TrackingProviderInterface>  $providers
     * @return array{updated: int, errors: int}
     */
    public function updateOutboundKits(Store $store, array $providers): array
    {
        $updated = 0;
        $errors = 0;

        $transactions = Transaction::query()
            ->where('store_id', $store->id)
            ->where(function ($q) {
                $q->where('type', Transaction::TYPE_MAIL_IN)
                    ->orWhere('source', Transaction::SOURCE_ONLINE);
            })
            ->whereNotNull('outbound_tracking_number')
            ->whereNotNull('kit_sent_at')
            ->whereNull('kit_delivered_at')
            ->whereNotIn('status', [
                Transaction::STATUS_CANCELLED,
                Transaction::STATUS_ITEMS_RETURNED,
            ])
            ->get();

        // Group by carrier
        $byCarrier = $transactions->groupBy(fn ($t) => strtolower($t->outbound_carrier ?? 'fedex'));

        foreach ($byCarrier as $carrier => $carrierTransactions) {
            $provider = $providers[$carrier] ?? null;

            if (! $provider) {
                // Try to auto-detect from tracking number
                $firstTransaction = $carrierTransactions->first();
                $provider = TrackingProviderFactory::detectFromTrackingNumber(
                    $firstTransaction->outbound_tracking_number,
                    $store
                );
            }

            if (! $provider || ! $provider->isConfigured()) {
                Log::warning("No provider configured for carrier: {$carrier}", ['store_id' => $store->id]);
                $errors += $carrierTransactions->count();

                continue;
            }

            foreach ($carrierTransactions as $transaction) {
                try {
                    $result = $this->trackAndUpdate(
                        $transaction,
                        $transaction->outbound_tracking_number,
                        'outbound',
                        $provider
                    );

                    if ($result) {
                        $updated++;
                    }
                } catch (\Exception $e) {
                    Log::error('Failed to update outbound tracking', [
                        'transaction_id' => $transaction->id,
                        'tracking_number' => $transaction->outbound_tracking_number,
                        'carrier' => $carrier,
                        'error' => $e->getMessage(),
                    ]);
                    $errors++;
                }
            }
        }

        return ['updated' => $updated, 'errors' => $errors];
    }

    /**
     * Update tracking for return shipments (coming back from customers).
     *
     * @param  array<string, TrackingProviderInterface>  $providers
     * @return array{updated: int, errors: int}
     */
    public function updateReturnShipments(Store $store, array $providers): array
    {
        $updated = 0;
        $errors = 0;

        $transactions = Transaction::query()
            ->where('store_id', $store->id)
            ->where(function ($q) {
                $q->where('type', Transaction::TYPE_MAIL_IN)
                    ->orWhere('source', Transaction::SOURCE_ONLINE);
            })
            ->whereNotNull('return_tracking_number')
            ->whereNotNull('return_shipped_at')
            ->whereNull('return_delivered_at')
            ->whereNotIn('status', [
                Transaction::STATUS_CANCELLED,
                Transaction::STATUS_ITEMS_RETURNED,
                Transaction::STATUS_ITEMS_RECEIVED,
            ])
            ->get();

        // Group by carrier
        $byCarrier = $transactions->groupBy(fn ($t) => strtolower($t->return_carrier ?? 'fedex'));

        foreach ($byCarrier as $carrier => $carrierTransactions) {
            $provider = $providers[$carrier] ?? null;

            if (! $provider) {
                $firstTransaction = $carrierTransactions->first();
                $provider = TrackingProviderFactory::detectFromTrackingNumber(
                    $firstTransaction->return_tracking_number,
                    $store
                );
            }

            if (! $provider || ! $provider->isConfigured()) {
                Log::warning("No provider configured for carrier: {$carrier}", ['store_id' => $store->id]);
                $errors += $carrierTransactions->count();

                continue;
            }

            foreach ($carrierTransactions as $transaction) {
                try {
                    $result = $this->trackAndUpdate(
                        $transaction,
                        $transaction->return_tracking_number,
                        'return',
                        $provider
                    );

                    if ($result) {
                        $updated++;
                    }
                } catch (\Exception $e) {
                    Log::error('Failed to update return tracking', [
                        'transaction_id' => $transaction->id,
                        'tracking_number' => $transaction->return_tracking_number,
                        'carrier' => $carrier,
                        'error' => $e->getMessage(),
                    ]);
                    $errors++;
                }
            }
        }

        return ['updated' => $updated, 'errors' => $errors];
    }

    /**
     * Track a single shipment and update the transaction.
     */
    public function trackAndUpdate(
        Transaction $transaction,
        string $trackingNumber,
        string $type,
        TrackingProviderInterface $provider
    ): bool {
        $result = $provider->track($trackingNumber);

        if (! $result) {
            return false;
        }

        return $this->applyTrackingResult($transaction, $result, $type);
    }

    /**
     * Apply a tracking result to a transaction.
     */
    protected function applyTrackingResult(
        Transaction $transaction,
        TrackingResult $result,
        string $type
    ): bool {
        $updated = false;

        if ($type === 'outbound') {
            // Update outbound kit tracking
            if ($result->isDelivered() && ! $transaction->kit_delivered_at) {
                $transaction->update([
                    'kit_delivered_at' => $result->actualDelivery ?? now(),
                    'status' => Transaction::STATUS_KIT_DELIVERED,
                ]);
                $updated = true;

                Log::info('Kit delivered to customer', [
                    'transaction_id' => $transaction->id,
                    'tracking_number' => $result->trackingNumber,
                    'delivered_at' => $result->actualDelivery,
                ]);
            }

            // Store tracking status in metadata
            $this->updateTrackingMetadata($transaction, $result, 'outbound_tracking_status');
        } elseif ($type === 'return') {
            // Update return shipment tracking
            if ($result->isDelivered() && ! $transaction->return_delivered_at) {
                $transaction->update([
                    'return_delivered_at' => $result->actualDelivery ?? now(),
                ]);
                $updated = true;

                Log::info('Return shipment delivered', [
                    'transaction_id' => $transaction->id,
                    'tracking_number' => $result->trackingNumber,
                    'delivered_at' => $result->actualDelivery,
                ]);
            }

            // Store tracking status in metadata
            $this->updateTrackingMetadata($transaction, $result, 'return_tracking_status');
        }

        return $updated;
    }

    /**
     * Update tracking metadata on the transaction.
     */
    protected function updateTrackingMetadata(
        Transaction $transaction,
        TrackingResult $result,
        string $key
    ): void {
        $metadata = $transaction->metadata ?? [];
        $metadata[$key] = [
            'status' => $result->status,
            'status_label' => $result->getStatusLabel(),
            'description' => $result->statusDescription,
            'location' => $result->currentLocation,
            'estimated_delivery' => $result->estimatedDelivery?->format('Y-m-d'),
            'updated_at' => now()->format('Y-m-d H:i:s'),
        ];

        // Store last few events
        $metadata[$key]['recent_events'] = array_slice($result->events, 0, 5);

        $transaction->update(['metadata' => $metadata]);
    }

    /**
     * Get tracking info for a specific tracking number.
     */
    public function getTrackingInfo(
        string $trackingNumber,
        ?string $carrier = null,
        ?Store $store = null
    ): ?TrackingResult {
        // If carrier is specified, use that provider
        if ($carrier && TrackingProviderFactory::supports($carrier)) {
            $provider = TrackingProviderFactory::make($carrier, $store);
            if ($provider->isConfigured()) {
                return $provider->track($trackingNumber);
            }
        }

        // Try to auto-detect from tracking number
        $provider = TrackingProviderFactory::detectFromTrackingNumber($trackingNumber, $store);

        if ($provider && $provider->isConfigured()) {
            return $provider->track($trackingNumber);
        }

        // Try all configured providers
        $providers = TrackingProviderFactory::getConfiguredProviders($store);

        foreach ($providers as $provider) {
            $result = $provider->track($trackingNumber);
            if ($result && $result->status !== TrackingResult::STATUS_UNKNOWN) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Get all transactions with active outbound shipments for a store.
     */
    public function getActiveOutboundShipments(Store $store): Collection
    {
        return Transaction::query()
            ->where('store_id', $store->id)
            ->where(function ($q) {
                $q->where('type', Transaction::TYPE_MAIL_IN)
                    ->orWhere('source', Transaction::SOURCE_ONLINE);
            })
            ->whereNotNull('outbound_tracking_number')
            ->whereNotNull('kit_sent_at')
            ->whereNull('kit_delivered_at')
            ->whereNotIn('status', [
                Transaction::STATUS_CANCELLED,
            ])
            ->with('customer')
            ->orderBy('kit_sent_at', 'desc')
            ->get();
    }

    /**
     * Get all transactions with active return shipments for a store.
     */
    public function getActiveReturnShipments(Store $store): Collection
    {
        return Transaction::query()
            ->where('store_id', $store->id)
            ->where(function ($q) {
                $q->where('type', Transaction::TYPE_MAIL_IN)
                    ->orWhere('source', Transaction::SOURCE_ONLINE);
            })
            ->whereNotNull('return_tracking_number')
            ->whereNotNull('return_shipped_at')
            ->whereNull('return_delivered_at')
            ->whereNotIn('status', [
                Transaction::STATUS_CANCELLED,
                Transaction::STATUS_ITEMS_RECEIVED,
            ])
            ->with('customer')
            ->orderBy('return_shipped_at', 'desc')
            ->get();
    }
}
