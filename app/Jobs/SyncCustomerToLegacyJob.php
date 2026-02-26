<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\Legacy\LegacyTransactionSyncService;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

class SyncCustomerToLegacyJob
{
    use Dispatchable;

    public function __construct(
        public Customer $customer
    ) {}

    public function handle(LegacyTransactionSyncService $service): void
    {
        try {
            $service->syncCustomer($this->customer);
        } catch (\Throwable $e) {
            Log::error('Legacy customer sync failed', [
                'customer_id' => $this->customer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
