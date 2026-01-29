<?php

namespace App\Services\TradeIn;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\TransactionPayout;
use Illuminate\Support\Facades\DB;

class TradeInService
{
    /**
     * Create a trade-in transaction from array of items.
     *
     * @param  array<int, array{title: string, description?: string, category_id?: int, buy_price: float, precious_metal?: string, condition?: string, dwt?: float}>  $items
     */
    public function createTradeIn(
        array $items,
        int $customerId,
        Store $store,
        ?int $warehouseId = null,
        ?int $userId = null
    ): Transaction {
        return DB::transaction(function () use ($items, $customerId, $store, $warehouseId, $userId) {
            $transaction = Transaction::create([
                'store_id' => $store->id,
                'warehouse_id' => $warehouseId,
                'customer_id' => $customerId,
                'user_id' => $userId ?? auth()->id(),
                'type' => Transaction::TYPE_IN_HOUSE,
                'source' => Transaction::SOURCE_TRADE_IN,
                'status' => Transaction::STATUS_PAYMENT_PROCESSED,
                'final_offer' => $this->calculateTradeInCredit($items),
                'payment_processed_at' => now(),
            ]);

            foreach ($items as $itemData) {
                TransactionItem::create([
                    'transaction_id' => $transaction->id,
                    'category_id' => $itemData['category_id'] ?? null,
                    'title' => $itemData['title'],
                    'description' => $itemData['description'] ?? null,
                    'buy_price' => $itemData['buy_price'],
                    'precious_metal' => $itemData['precious_metal'] ?? null,
                    'condition' => $itemData['condition'] ?? null,
                    'dwt' => $itemData['dwt'] ?? null,
                ]);
            }

            return $transaction->load('items');
        });
    }

    /**
     * Calculate total trade-in credit from items.
     *
     * @param  array<int, array{buy_price: float}>  $items
     */
    public function calculateTradeInCredit(array $items): float
    {
        return array_reduce($items, function (float $carry, array $item) {
            return $carry + (float) ($item['buy_price'] ?? 0);
        }, 0.0);
    }

    /**
     * Link a trade-in transaction to an order.
     */
    public function linkToOrder(Transaction $transaction, Order $order): void
    {
        DB::transaction(function () use ($transaction, $order) {
            $transaction->update(['order_id' => $order->id]);
            $order->update(['trade_in_transaction_id' => $transaction->id]);
        });
    }

    /**
     * Apply trade-in credit to an order and create the store credit payment.
     */
    public function applyTradeInToOrder(Order $order, Transaction $transaction): void
    {
        $tradeInCredit = $transaction->final_offer ?? $transaction->total_buy_price;

        DB::transaction(function () use ($order, $transaction, $tradeInCredit) {
            // Update order with trade-in credit
            $order->update([
                'trade_in_transaction_id' => $transaction->id,
                'trade_in_credit' => $tradeInCredit,
            ]);

            // Link transaction to order
            $transaction->update(['order_id' => $order->id]);

            // Create a store credit payment record for the trade-in amount
            Payment::create([
                'store_id' => $order->store_id,
                'payable_type' => Order::class,
                'payable_id' => $order->id,
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'user_id' => auth()->id(),
                'payment_method' => Payment::METHOD_STORE_CREDIT,
                'status' => Payment::STATUS_COMPLETED,
                'amount' => min($tradeInCredit, $order->total + $tradeInCredit), // Credit can't exceed what was needed before trade-in
                'currency' => 'USD',
                'reference' => "Trade-In: {$transaction->transaction_number}",
                'notes' => "Trade-in credit from transaction {$transaction->transaction_number}",
                'paid_at' => now(),
            ]);
        });
    }

    /**
     * Handle excess trade-in credit (when trade-in value exceeds purchase total).
     * Creates a payout record for the customer to receive the difference.
     */
    public function handleExcessCredit(Order $order, float $excessAmount, string $payoutMethod = 'cash'): ?TransactionPayout
    {
        if ($excessAmount <= 0 || ! $order->tradeInTransaction) {
            return null;
        }

        return TransactionPayout::create([
            'store_id' => $order->store_id,
            'transaction_id' => $order->trade_in_transaction_id,
            'amount' => $excessAmount,
            'currency' => 'USD',
            'status' => TransactionPayout::STATUS_PENDING,
            'provider' => $payoutMethod,
            'recipient_type' => TransactionPayout::RECIPIENT_TYPE_EMAIL,
            'recipient_value' => $order->customer?->email ?? 'unknown',
            'notes' => "Excess trade-in credit refund for order {$order->invoice_number}",
        ]);
    }

    /**
     * Cancel a trade-in along with its order.
     *
     * @param  bool  $cancelTransaction  If true, also cancels the trade-in transaction
     */
    public function cancelTradeInWithOrder(Order $order, bool $cancelTransaction = true): void
    {
        if (! $order->hasTradeIn()) {
            return;
        }

        DB::transaction(function () use ($order, $cancelTransaction) {
            $transaction = $order->tradeInTransaction;

            // Void the store credit payment
            Payment::where('order_id', $order->id)
                ->where('payment_method', Payment::METHOD_STORE_CREDIT)
                ->where('reference', 'like', "Trade-In: {$transaction->transaction_number}%")
                ->update(['status' => Payment::STATUS_REFUNDED]);

            if ($cancelTransaction && $transaction) {
                // Unlink and cancel the transaction
                $transaction->update([
                    'order_id' => null,
                    'status' => Transaction::STATUS_CANCELLED,
                ]);
            } elseif ($transaction) {
                // Just unlink, keep transaction valid
                $transaction->update(['order_id' => null]);
            }

            // Clear trade-in from order
            $order->update([
                'trade_in_transaction_id' => null,
                'trade_in_credit' => 0,
            ]);
        });
    }

    /**
     * Unlink a trade-in from an order without cancelling the transaction.
     * The trade-in remains valid and can be applied to another order.
     */
    public function unlinkFromOrder(Order $order): void
    {
        if (! $order->hasTradeIn()) {
            return;
        }

        DB::transaction(function () use ($order) {
            $transaction = $order->tradeInTransaction;

            if ($transaction) {
                $transaction->update(['order_id' => null]);
            }

            $order->update([
                'trade_in_transaction_id' => null,
                'trade_in_credit' => 0,
            ]);
        });
    }

    /**
     * Check if a transaction can be used as a trade-in.
     */
    public function canBeUsedAsTradeIn(Transaction $transaction): bool
    {
        // Trade-in must be processed and not already linked to an order
        return $transaction->isTradeIn()
            && $transaction->order_id === null
            && in_array($transaction->status, [
                Transaction::STATUS_PAYMENT_PROCESSED,
                Transaction::STATUS_OFFER_ACCEPTED,
            ]);
    }
}
