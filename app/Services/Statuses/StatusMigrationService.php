<?php

namespace App\Services\Statuses;

use App\Enums\StatusableType;
use App\Models\Memo;
use App\Models\Order;
use App\Models\Repair;
use App\Models\Status;
use App\Models\Store;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StatusMigrationService
{
    public function __construct(
        protected StatusService $statusService
    ) {}

    /**
     * Migrate all existing stores to use the new status system.
     */
    public function migrateAllStores(): void
    {
        Store::query()->chunk(100, function ($stores) {
            foreach ($stores as $store) {
                $this->migrateStore($store);
            }
        });
    }

    /**
     * Migrate a single store to the new status system.
     */
    public function migrateStore(Store $store): void
    {
        Log::info('Migrating statuses for store', ['store_id' => $store->id, 'store_name' => $store->name]);

        // Create default statuses for each entity type if they don't exist
        foreach (StatusableType::cases() as $type) {
            $existingCount = Status::query()
                ->where('store_id', $store->id)
                ->where('entity_type', $type->value)
                ->count();

            if ($existingCount === 0) {
                $this->statusService->createDefaultStatuses($store->id, $type);
                Log::info('Created default statuses', ['store_id' => $store->id, 'entity_type' => $type->value]);
            }
        }

        // Migrate existing entities
        $this->migrateTransactions($store);
        $this->migrateOrders($store);
        $this->migrateRepairs($store);
        $this->migrateMemos($store);
    }

    /**
     * Migrate transactions for a store.
     */
    protected function migrateTransactions(Store $store): void
    {
        $statusMap = $this->buildStatusMap($store->id, StatusableType::Transaction);

        Transaction::query()
            ->where('store_id', $store->id)
            ->whereNull('status_id')
            ->whereNotNull('status')
            ->chunkById(500, function ($transactions) use ($statusMap) {
                $updates = [];

                foreach ($transactions as $transaction) {
                    $statusId = $statusMap[$transaction->status] ?? null;

                    if ($statusId) {
                        $updates[] = [
                            'id' => $transaction->id,
                            'status_id' => $statusId,
                        ];
                    }
                }

                $this->batchUpdate('transactions', $updates);
            });
    }

    /**
     * Migrate orders for a store.
     */
    protected function migrateOrders(Store $store): void
    {
        $statusMap = $this->buildStatusMap($store->id, StatusableType::Order);

        Order::query()
            ->where('store_id', $store->id)
            ->whereNull('status_id')
            ->whereNotNull('status')
            ->chunkById(500, function ($orders) use ($statusMap) {
                $updates = [];

                foreach ($orders as $order) {
                    $statusId = $statusMap[$order->status] ?? null;

                    if ($statusId) {
                        $updates[] = [
                            'id' => $order->id,
                            'status_id' => $statusId,
                        ];
                    }
                }

                $this->batchUpdate('orders', $updates);
            });
    }

    /**
     * Migrate repairs for a store.
     */
    protected function migrateRepairs(Store $store): void
    {
        $statusMap = $this->buildStatusMap($store->id, StatusableType::Repair);

        Repair::query()
            ->where('store_id', $store->id)
            ->whereNull('status_id')
            ->whereNotNull('status')
            ->chunkById(500, function ($repairs) use ($statusMap) {
                $updates = [];

                foreach ($repairs as $repair) {
                    $statusId = $statusMap[$repair->status] ?? null;

                    if ($statusId) {
                        $updates[] = [
                            'id' => $repair->id,
                            'status_id' => $statusId,
                        ];
                    }
                }

                $this->batchUpdate('repairs', $updates);
            });
    }

    /**
     * Migrate memos for a store.
     */
    protected function migrateMemos(Store $store): void
    {
        $statusMap = $this->buildStatusMap($store->id, StatusableType::Memo);

        Memo::query()
            ->where('store_id', $store->id)
            ->whereNull('status_id')
            ->whereNotNull('status')
            ->chunkById(500, function ($memos) use ($statusMap) {
                $updates = [];

                foreach ($memos as $memo) {
                    $statusId = $statusMap[$memo->status] ?? null;

                    if ($statusId) {
                        $updates[] = [
                            'id' => $memo->id,
                            'status_id' => $statusId,
                        ];
                    }
                }

                $this->batchUpdate('memos', $updates);
            });
    }

    /**
     * Build a status slug to ID map for a store and entity type.
     *
     * @return array<string, int>
     */
    protected function buildStatusMap(int $storeId, StatusableType $type): array
    {
        return Status::query()
            ->where('store_id', $storeId)
            ->where('entity_type', $type->value)
            ->pluck('id', 'slug')
            ->toArray();
    }

    /**
     * Perform a batch update using CASE WHEN.
     *
     * @param  array<array{id: int, status_id: int}>  $updates
     */
    protected function batchUpdate(string $table, array $updates): void
    {
        if (empty($updates)) {
            return;
        }

        $cases = [];
        $ids = [];

        foreach ($updates as $update) {
            $cases[] = "WHEN {$update['id']} THEN {$update['status_id']}";
            $ids[] = $update['id'];
        }

        $caseSql = implode(' ', $cases);
        $idList = implode(',', $ids);

        DB::statement("UPDATE {$table} SET status_id = CASE id {$caseSql} END WHERE id IN ({$idList})");
    }

    /**
     * Verify migration completeness for a store.
     *
     * @return array{transactions: array{total: int, migrated: int}, orders: array{total: int, migrated: int}, repairs: array{total: int, migrated: int}, memos: array{total: int, migrated: int}}
     */
    public function verifyMigration(Store $store): array
    {
        return [
            'transactions' => [
                'total' => Transaction::where('store_id', $store->id)->count(),
                'migrated' => Transaction::where('store_id', $store->id)->whereNotNull('status_id')->count(),
            ],
            'orders' => [
                'total' => Order::where('store_id', $store->id)->count(),
                'migrated' => Order::where('store_id', $store->id)->whereNotNull('status_id')->count(),
            ],
            'repairs' => [
                'total' => Repair::where('store_id', $store->id)->count(),
                'migrated' => Repair::where('store_id', $store->id)->whereNotNull('status_id')->count(),
            ],
            'memos' => [
                'total' => Memo::where('store_id', $store->id)->count(),
                'migrated' => Memo::where('store_id', $store->id)->whereNotNull('status_id')->count(),
            ],
        ];
    }
}
