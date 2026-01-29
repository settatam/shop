<?php

namespace App\Services;

use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Warehouse;

class StoreContext
{
    protected ?Store $currentStore = null;

    protected ?int $currentStoreId = null;

    protected ?StoreUser $currentStoreUser = null;

    public function setCurrentStore(Store $store): void
    {
        $this->currentStore = $store;
        $this->currentStoreId = $store->id;
    }

    public function setCurrentStoreId(int $storeId): void
    {
        $this->currentStoreId = $storeId;
        $this->currentStore = null;
    }

    public function getCurrentStore(): ?Store
    {
        if ($this->currentStore === null && $this->currentStoreId !== null) {
            $this->currentStore = Store::withoutGlobalScopes()->find($this->currentStoreId);
        }

        return $this->currentStore;
    }

    public function getCurrentStoreId(): ?int
    {
        return $this->currentStoreId;
    }

    public function hasStore(): bool
    {
        return $this->currentStoreId !== null;
    }

    public function clear(): void
    {
        $this->currentStore = null;
        $this->currentStoreId = null;
        $this->currentStoreUser = null;
    }

    public function setCurrentStoreUser(StoreUser $storeUser): void
    {
        $this->currentStoreUser = $storeUser;
    }

    public function getCurrentStoreUser(): ?StoreUser
    {
        if ($this->currentStoreUser === null && $this->currentStoreId !== null && auth()->check()) {
            $this->currentStoreUser = StoreUser::where('store_id', $this->currentStoreId)
                ->where('user_id', auth()->id())
                ->first();
        }

        return $this->currentStoreUser;
    }

    public function getDefaultWarehouse(): ?Warehouse
    {
        $storeUser = $this->getCurrentStoreUser();

        if ($storeUser && $storeUser->default_warehouse_id) {
            return $storeUser->defaultWarehouse;
        }

        return null;
    }

    public function getDefaultWarehouseId(): ?int
    {
        $storeUser = $this->getCurrentStoreUser();

        return $storeUser?->default_warehouse_id;
    }
}
