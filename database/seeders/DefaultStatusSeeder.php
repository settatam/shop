<?php

namespace Database\Seeders;

use App\Enums\StatusableType;
use App\Models\Store;
use App\Services\Statuses\StatusService;
use Illuminate\Database\Seeder;

class DefaultStatusSeeder extends Seeder
{
    public function __construct(
        protected StatusService $statusService
    ) {}

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Store::query()->chunk(100, function ($stores) {
            foreach ($stores as $store) {
                $this->createStatusesForStore($store);
            }
        });
    }

    /**
     * Create default statuses for a store.
     */
    protected function createStatusesForStore(Store $store): void
    {
        foreach (StatusableType::cases() as $type) {
            $existingCount = \App\Models\Status::query()
                ->where('store_id', $store->id)
                ->where('entity_type', $type->value)
                ->count();

            if ($existingCount === 0) {
                $this->statusService->createDefaultStatuses($store->id, $type);
                $this->command?->info("Created {$type->label()} statuses for store: {$store->name}");
            } else {
                $this->command?->info("Skipping {$type->label()} statuses for store: {$store->name} (already exist)");
            }
        }
    }
}
