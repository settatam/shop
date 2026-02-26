<?php

namespace App\Services\Returns;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\ProductReturn;
use App\Models\ProductVariant;
use App\Models\ReturnItem;
use App\Models\ReturnPolicy;
use App\Models\Store;
use App\Models\User;
use App\Services\Orders\OrderCreationService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReturnService
{
    protected ProductReturn $return;

    protected Store $store;

    public function __construct(
        protected OrderCreationService $orderCreationService
    ) {}

    public function createReturn(Order $order, array $items, array $data = []): ProductReturn
    {
        if (empty($items)) {
            throw new InvalidArgumentException('Return must have at least one item.');
        }

        $this->store = $order->store;

        return DB::transaction(function () use ($order, $items, $data) {
            $this->return = ProductReturn::create([
                'store_id' => $this->store->id,
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'return_policy_id' => $data['return_policy_id'] ?? $this->getDefaultPolicyId(),
                'type' => $data['type'] ?? ProductReturn::TYPE_RETURN,
                'reason' => $data['reason'] ?? null,
                'customer_notes' => $data['customer_notes'] ?? null,
                'source_platform' => $order->source_platform,
            ]);

            foreach ($items as $itemData) {
                $this->addItem($itemData, $order);
            }

            $this->return->calculateTotals();

            return $this->return->fresh(['items', 'order', 'customer', 'returnPolicy']);
        });
    }

    public function createInStoreReturn(Customer $customer, array $items, array $data = []): ProductReturn
    {
        if (empty($items)) {
            throw new InvalidArgumentException('Return must have at least one item.');
        }

        $this->store = $customer->store;

        return DB::transaction(function () use ($customer, $items, $data) {
            $this->return = ProductReturn::create([
                'store_id' => $this->store->id,
                'order_id' => $data['order_id'] ?? null,
                'customer_id' => $customer->id,
                'return_policy_id' => $data['return_policy_id'] ?? $this->getDefaultPolicyId(),
                'type' => $data['type'] ?? ProductReturn::TYPE_RETURN,
                'reason' => $data['reason'] ?? null,
                'customer_notes' => $data['customer_notes'] ?? null,
            ]);

            foreach ($items as $itemData) {
                $this->addItemDirect($itemData);
            }

            $this->return->calculateTotals();

            return $this->return->fresh(['items', 'order', 'customer', 'returnPolicy']);
        });
    }

    protected function addItem(array $itemData, Order $order): ReturnItem
    {
        $orderItem = null;

        if (isset($itemData['order_item_id'])) {
            $orderItem = $order->items()->find($itemData['order_item_id']);
        }

        return $this->return->items()->create([
            'order_item_id' => $orderItem?->id,
            'product_variant_id' => $orderItem?->product_variant_id ?? $itemData['product_variant_id'] ?? null,
            'quantity' => $itemData['quantity'],
            'unit_price' => $itemData['unit_price'] ?? $orderItem?->price ?? 0,
            'condition' => $itemData['condition'] ?? null,
            'reason' => $itemData['reason'] ?? null,
            'notes' => $itemData['notes'] ?? null,
            'restock' => $itemData['restock'] ?? true,
            'exchange_variant_id' => $itemData['exchange_variant_id'] ?? null,
            'exchange_quantity' => $itemData['exchange_quantity'] ?? null,
        ]);
    }

    protected function addItemDirect(array $itemData): ReturnItem
    {
        return $this->return->items()->create([
            'order_item_id' => $itemData['order_item_id'] ?? null,
            'product_variant_id' => $itemData['product_variant_id'] ?? null,
            'quantity' => $itemData['quantity'],
            'unit_price' => $itemData['unit_price'],
            'condition' => $itemData['condition'] ?? null,
            'reason' => $itemData['reason'] ?? null,
            'notes' => $itemData['notes'] ?? null,
            'restock' => $itemData['restock'] ?? true,
            'exchange_variant_id' => $itemData['exchange_variant_id'] ?? null,
            'exchange_quantity' => $itemData['exchange_quantity'] ?? null,
        ]);
    }

    public function approveReturn(ProductReturn $return, ?User $approver = null): ProductReturn
    {
        if (! $return->canBeApproved()) {
            throw new InvalidArgumentException('Return cannot be approved in its current state.');
        }

        $return->approve($approver?->id);

        return $return->fresh();
    }

    public function rejectReturn(ProductReturn $return, string $reason, ?User $user = null): ProductReturn
    {
        if (! $return->canBeRejected()) {
            throw new InvalidArgumentException('Return cannot be rejected in its current state.');
        }

        $return->reject($reason, $user?->id);

        return $return->fresh();
    }

    public function processReturn(ProductReturn $return, string $refundMethod): ProductReturn
    {
        if (! $return->canBeProcessed()) {
            throw new InvalidArgumentException('Return cannot be processed in its current state.');
        }

        return DB::transaction(function () use ($return, $refundMethod) {
            $return->markAsProcessing();

            $this->restockItems($return);

            $return->calculateTotals();

            $this->processRefund($return, $refundMethod);

            $return->complete($refundMethod, $return->refund_amount);

            return $return->fresh(['items']);
        });
    }

    public function cancelReturn(ProductReturn $return): ProductReturn
    {
        if (! $return->canBeCancelled()) {
            throw new InvalidArgumentException('Return cannot be cancelled in its current state.');
        }

        $return->cancel();

        return $return->fresh();
    }

    public function restockItems(ProductReturn $return): void
    {
        foreach ($return->items as $item) {
            if ($item->shouldRestock() && $item->product_variant_id) {
                $this->restoreStock($item->product_variant_id, $item->quantity);
                $item->markAsRestocked();
            }
        }
    }

    protected function restoreStock(int $variantId, int $quantity): void
    {
        $inventory = Inventory::where('product_variant_id', $variantId)->first();

        if ($inventory) {
            $inventory->increment('quantity', $quantity);

            // Sync variant and product quantity caches
            Inventory::syncVariantQuantity($variantId);
            $variant = ProductVariant::find($variantId);
            if ($variant) {
                Inventory::syncProductQuantity($variant->product_id);
            }
        }
    }

    public function processRefund(ProductReturn $return, string $refundMethod): void
    {
        switch ($refundMethod) {
            case ProductReturn::REFUND_STORE_CREDIT:
                $this->issueStoreCredit($return);
                break;

            case ProductReturn::REFUND_ORIGINAL:
                $this->refundToOriginalPayment($return);
                break;

            case ProductReturn::REFUND_CASH:
            case ProductReturn::REFUND_CARD:
                break;

            default:
                throw new InvalidArgumentException("Unknown refund method: {$refundMethod}");
        }
    }

    protected function issueStoreCredit(ProductReturn $return): void
    {
        // Store credit implementation would go here
        // This would create a store credit record and link it to the return
    }

    protected function refundToOriginalPayment(ProductReturn $return): void
    {
        // Original payment refund implementation would go here
        // This would interact with payment gateways to process refunds
    }

    public function createExchange(ProductReturn $return, array $exchangeItems): Order
    {
        if (! $return->isApproved() && ! $return->isProcessing()) {
            throw new InvalidArgumentException('Return must be approved before creating exchange.');
        }

        $return->update(['type' => ProductReturn::TYPE_EXCHANGE]);

        $orderData = [
            'customer' => ['id' => $return->customer_id],
            'items' => $exchangeItems,
            'notes' => "Exchange for return #{$return->return_number}",
        ];

        $order = $this->orderCreationService->create($orderData, $return->store);

        return $order;
    }

    public function calculateRefundAmount(ProductReturn $return): array
    {
        $subtotal = $return->items->sum('line_total');
        $restockingFee = 0;

        if ($return->returnPolicy) {
            $restockingFee = $return->returnPolicy->calculateRestockingFee($subtotal);
        }

        $refundAmount = max(0, $subtotal - $restockingFee);

        return [
            'subtotal' => $subtotal,
            'restocking_fee' => $restockingFee,
            'refund_amount' => $refundAmount,
        ];
    }

    protected function getDefaultPolicyId(): ?int
    {
        $policy = ReturnPolicy::where('store_id', $this->store->id)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();

        return $policy?->id;
    }

    public function markAsReceived(ProductReturn $return): ProductReturn
    {
        $return->markAsReceived();

        return $return->fresh();
    }
}
