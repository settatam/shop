<?php

namespace App\Services;

use App\Models\Store;
use App\Models\Warehouse;

class TaxService
{
    /**
     * Get the applicable tax rate with the following priority:
     * 1. Warehouse tax_rate (if set and warehouse provided)
     * 2. Store default_tax_rate
     * 3. Zero (if nothing configured)
     */
    public function getTaxRate(?Warehouse $warehouse, Store $store): float
    {
        // Warehouse override takes priority
        if ($warehouse !== null && $warehouse->tax_rate !== null) {
            return (float) $warehouse->tax_rate;
        }

        // Fall back to store default
        return (float) ($store->default_tax_rate ?? 0);
    }

    /**
     * Resolve tax rate for a given context, allowing explicit override.
     *
     * @param  float|null  $explicitTaxRate  Explicitly provided tax rate (takes highest priority)
     * @param  Warehouse|null  $warehouse  Warehouse for location-based tax
     * @param  Store  $store  Store for default tax
     */
    public function resolveTaxRate(?float $explicitTaxRate, ?Warehouse $warehouse, Store $store): float
    {
        // Explicit value takes highest priority
        if ($explicitTaxRate !== null) {
            return $explicitTaxRate;
        }

        return $this->getTaxRate($warehouse, $store);
    }
}
