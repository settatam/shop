<?php

namespace App\Scopes;

use App\Services\StoreContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class StoreScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $storeId = app(StoreContext::class)->getCurrentStoreId();

        if ($storeId) {
            $builder->where($model->getTable().'.store_id', $storeId);
        }
    }

    public function extend(Builder $builder): void
    {
        $builder->macro('withoutStoreScope', function (Builder $builder) {
            return $builder->withoutGlobalScope($this);
        });

        $builder->macro('forStore', function (Builder $builder, int $storeId) {
            return $builder->withoutGlobalScope($this)->where('store_id', $storeId);
        });
    }
}
