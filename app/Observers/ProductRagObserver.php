<?php

namespace App\Observers;

use App\Jobs\IndexStoreContentJob;
use App\Jobs\RemoveStoreContentJob;
use App\Models\Product;

class ProductRagObserver
{
    public function created(Product $product): void
    {
        if ($product->status === Product::STATUS_ACTIVE && $product->is_published) {
            IndexStoreContentJob::dispatch('product', $product->id);
        }
    }

    public function updated(Product $product): void
    {
        if ($product->status === Product::STATUS_ACTIVE && $product->is_published) {
            IndexStoreContentJob::dispatch('product', $product->id);
        } else {
            RemoveStoreContentJob::dispatch("product_{$product->id}");
        }
    }

    public function deleted(Product $product): void
    {
        RemoveStoreContentJob::dispatch("product_{$product->id}");
    }
}
