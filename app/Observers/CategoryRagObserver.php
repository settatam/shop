<?php

namespace App\Observers;

use App\Jobs\IndexStoreContentJob;
use App\Jobs\RemoveStoreContentJob;
use App\Models\Category;

class CategoryRagObserver
{
    public function created(Category $category): void
    {
        IndexStoreContentJob::dispatch('category', $category->id);
    }

    public function updated(Category $category): void
    {
        if ($category->trashed()) {
            RemoveStoreContentJob::dispatch("category_{$category->id}");
        } else {
            IndexStoreContentJob::dispatch('category', $category->id);
        }
    }

    public function deleted(Category $category): void
    {
        RemoveStoreContentJob::dispatch("category_{$category->id}");
    }
}
