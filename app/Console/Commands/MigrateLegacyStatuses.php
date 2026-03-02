<?php

namespace App\Console\Commands;

use App\Models\Status;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MigrateLegacyStatuses extends Command
{
    protected $signature = 'migrate:legacy-statuses
                            {store_id : The legacy store ID to pull statuses from}
                            {--new-store-id= : The new store ID to create statuses for (defaults to same as legacy)}
                            {--entity-type=transaction : The entity type for the statuses}
                            {--fresh : Delete existing statuses for this store/entity_type before importing}
                            {--dry-run : Run without making any changes}';

    protected $description = 'Migrate legacy statuses from shopmata-new database for a specific store';

    protected bool $dryRun = false;

    protected int $legacyStoreId;

    protected int $newStoreId;

    protected string $entityType;

    public function handle(): int
    {
        $this->legacyStoreId = (int) $this->argument('store_id');
        $this->newStoreId = (int) ($this->option('new-store-id') ?? $this->legacyStoreId);
        $this->entityType = $this->option('entity-type');
        $this->dryRun = (bool) $this->option('dry-run');

        if ($this->dryRun) {
            $this->warn('Running in DRY RUN mode - no changes will be made');
        }

        $this->info("Migrating statuses from legacy store {$this->legacyStoreId} -> new store {$this->newStoreId} (entity_type: {$this->entityType})");

        // Test legacy database connection
        try {
            DB::connection('legacy')->getPdo();
            $this->info('Connected to legacy database successfully.');
        } catch (\Exception $e) {
            $this->error('Could not connect to legacy database: '.$e->getMessage());

            return self::FAILURE;
        }

        // Handle fresh option
        if ($this->option('fresh') && ! $this->dryRun) {
            $deleted = Status::where('store_id', $this->newStoreId)
                ->where('entity_type', $this->entityType)
                ->forceDelete();

            $this->info("Deleted {$deleted} existing statuses for store {$this->newStoreId} ({$this->entityType})");
        }

        // Get legacy statuses
        $legacyStatuses = DB::connection('legacy')
            ->table('statuses')
            ->where('store_id', $this->legacyStoreId)
            ->orderBy('sort_order')
            ->get();

        $this->info("Found {$legacyStatuses->count()} legacy statuses to migrate");

        if ($legacyStatuses->isEmpty()) {
            $this->warn('No statuses found for this store');

            return self::SUCCESS;
        }

        $created = 0;
        $skipped = 0;

        foreach ($legacyStatuses as $legacyStatus) {
            $slug = Str::slug($legacyStatus->name, '_');

            // Check if already exists
            $existing = Status::where('store_id', $this->newStoreId)
                ->where('entity_type', $this->entityType)
                ->where('slug', $slug)
                ->first();

            if ($existing) {
                $skipped++;
                $this->line("  Skipped (exists): {$legacyStatus->name}");

                continue;
            }

            if ($this->dryRun) {
                $created++;
                $this->line("  [DRY RUN] Would create: {$legacyStatus->name} (slug: {$slug})");

                continue;
            }

            Status::create([
                'store_id' => $this->newStoreId,
                'entity_type' => $this->entityType,
                'name' => $legacyStatus->name,
                'slug' => $slug,
                'sort_order' => $legacyStatus->sort_order ?? 0,
                'is_default' => false,
                'is_final' => false,
                'is_system' => false,
            ]);

            $created++;
            $this->line("  Created: {$legacyStatus->name} (slug: {$slug})");
        }

        $this->newLine();
        $this->info('Migration complete!');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Created', $created],
                ['Skipped (already exists)', $skipped],
            ]
        );

        return self::SUCCESS;
    }
}
