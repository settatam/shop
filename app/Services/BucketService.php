<?php

namespace App\Services;

use App\Models\Bucket;
use App\Models\BucketItem;
use App\Models\OrderItem;
use App\Models\TransactionItem;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BucketService
{
    /**
     * Add an item to a bucket.
     *
     * @param  array{title: string, description?: string|null, value: float}  $data
     */
    public function addItem(Bucket $bucket, array $data, ?TransactionItem $transactionItem = null): BucketItem
    {
        return DB::transaction(function () use ($bucket, $data, $transactionItem) {
            $bucketItem = BucketItem::create([
                'bucket_id' => $bucket->id,
                'transaction_item_id' => $transactionItem?->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'value' => $data['value'],
            ]);

            if ($transactionItem) {
                $transactionItem->update([
                    'bucket_id' => $bucket->id,
                    'is_added_to_bucket' => true,
                ]);
            }

            $bucket->recalculateTotal();

            return $bucketItem;
        });
    }

    /**
     * Mark a bucket item as sold when it's part of an order.
     */
    public function sellItem(BucketItem $bucketItem, OrderItem $orderItem): void
    {
        if ($bucketItem->isSold()) {
            throw new InvalidArgumentException('This bucket item has already been sold.');
        }

        DB::transaction(function () use ($bucketItem, $orderItem) {
            $bucketItem->update([
                'sold_at' => now(),
                'order_item_id' => $orderItem->id,
            ]);

            $bucketItem->bucket->recalculateTotal();
        });
    }

    /**
     * Remove an unsold item from a bucket.
     */
    public function removeItem(BucketItem $bucketItem): void
    {
        if ($bucketItem->isSold()) {
            throw new InvalidArgumentException('Cannot remove a sold bucket item.');
        }

        DB::transaction(function () use ($bucketItem) {
            $bucket = $bucketItem->bucket;

            // If this was created from a transaction item, reset its bucket status
            if ($bucketItem->transaction_item_id) {
                TransactionItem::where('id', $bucketItem->transaction_item_id)
                    ->update([
                        'bucket_id' => null,
                        'is_added_to_bucket' => false,
                    ]);
            }

            $bucketItem->delete();
            $bucket->recalculateTotal();
        });
    }
}
