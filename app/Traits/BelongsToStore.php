<?php

namespace App\Traits;

use App\Models\Store;
use App\Scopes\StoreScope;
use App\Services\StoreContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToStore
{
    public static function bootBelongsToStore(): void
    {
        static::addGlobalScope(new StoreScope);

        static::creating(function ($model) {
            if (empty($model->store_id)) {
                $storeId = app(StoreContext::class)->getCurrentStoreId();
                if ($storeId) {
                    $model->store_id = $storeId;
                }
            }
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function scopeForStore($query, int $storeId)
    {
        return $query->withoutGlobalScope(StoreScope::class)->where('store_id', $storeId);
    }
}
