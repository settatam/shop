<?php

namespace App\Console\Commands;

use App\Models\Status;
use App\Models\StatusHistory;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateTransactionStatuses extends Command
{
    protected $signature = 'transactions:update-statuses
                            {--store-id=63 : Legacy store ID}
                            {--new-store-id= : New store ID (if different)}
                            {--dry-run : Show what would be updated without making changes}';

    protected $description = 'Update transaction statuses from legacy status_updates table and import status history';

    protected array $userMap = [];

    protected ?Store $newStore = null;

    public function handle(): int
    {
        $legacyStoreId = (int) $this->option('store-id');
        $newStoreId = $this->option('new-store-id') ? (int) $this->option('new-store-id') : null;
        $isDryRun = $this->option('dry-run');

        $this->info("Updating transaction statuses from legacy store ID: {$legacyStoreId}");

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        // Get legacy store
        $legacyStore = DB::connection('legacy')
            ->table('stores')
            ->where('id', $legacyStoreId)
            ->first();

        if (! $legacyStore) {
            $this->error("Legacy store with ID {$legacyStoreId} not found");

            return 1;
        }

        // Find new store
        if ($newStoreId) {
            $this->newStore = Store::find($newStoreId);
        } else {
            $this->newStore = Store::where('name', $legacyStore->name)->first();
        }

        if (! $this->newStore) {
            $this->error('New store not found');

            return 1;
        }

        $this->info("Updating transactions in store: {$this->newStore->name} (ID: {$this->newStore->id})");

        // Build user mapping
        $this->buildUserMapping($legacyStoreId);

        try {
            DB::beginTransaction();

            // Update transaction statuses
            $this->updateTransactionStatuses($legacyStoreId, $isDryRun);

            // Import status history
            $this->importStatusHistory($legacyStoreId, $isDryRun);

            if ($isDryRun) {
                DB::rollBack();
                $this->info('Dry run complete - no changes made');
            } else {
                DB::commit();
                $this->info('Status update complete!');
            }

            return 0;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Update failed: {$e->getMessage()}");
            $this->error($e->getTraceAsString());

            return 1;
        }
    }

    protected function buildUserMapping(int $legacyStoreId): void
    {
        $this->info('Building user mapping...');

        $legacyStoreUsers = DB::connection('legacy')
            ->table('store_users')
            ->where('store_id', $legacyStoreId)
            ->get();

        foreach ($legacyStoreUsers as $legacyStoreUser) {
            $legacyUser = DB::connection('legacy')
                ->table('users')
                ->where('id', $legacyStoreUser->user_id)
                ->first();

            if ($legacyUser) {
                $newUser = User::where('email', $legacyUser->email)->first();
                if ($newUser) {
                    $this->userMap[$legacyStoreUser->user_id] = $newUser->id;
                }
            }
        }

        $this->line('  Mapped '.count($this->userMap).' users');
    }

    protected function updateTransactionStatuses(int $legacyStoreId, bool $isDryRun): void
    {
        $this->info('Updating transaction statuses...');

        // Get all transactions in the new store
        $transactions = Transaction::where('store_id', $this->newStore->id)->get();

        // Get default status
        $defaultStatus = Status::where('store_id', $this->newStore->id)
            ->where('entity_type', 'transaction')
            ->where('is_default', true)
            ->first();

        $updated = 0;
        foreach ($transactions as $transaction) {
            // Get the most recent status from legacy status_updates
            $currentStatusUpdate = DB::connection('legacy')
                ->table('status_updates')
                ->where('store_id', $legacyStoreId)
                ->where('updateable_type', 'App\\Models\\Transaction')
                ->where('updateable_id', $transaction->id) // IDs are preserved
                ->orderBy('created_at', 'desc')
                ->first();

            if (! $currentStatusUpdate) {
                continue;
            }

            // Map status name to new status_id
            $newStatusId = $this->mapStatusNameToId($currentStatusUpdate->current_status, $defaultStatus?->id);
            $statusSlug = $this->mapStatusNameToSlug($currentStatusUpdate->current_status);

            if ($isDryRun) {
                $this->line("  Would update #{$transaction->id}: {$transaction->status} => {$statusSlug}");
                $updated++;

                continue;
            }

            // Update the transaction
            $transaction->update([
                'status_id' => $newStatusId,
                'status' => $statusSlug,
            ]);
            $updated++;
        }

        $this->line("  Updated {$updated} transaction statuses");
    }

    protected function importStatusHistory(int $legacyStoreId, bool $isDryRun): void
    {
        $this->info('Importing status history...');

        // Clear existing status history for this store's transactions
        if (! $isDryRun) {
            $transactionIds = Transaction::where('store_id', $this->newStore->id)->pluck('id');
            StatusHistory::where('trackable_type', Transaction::class)
                ->whereIn('trackable_id', $transactionIds)
                ->delete();
        }

        // Get status updates from legacy
        $legacyUpdates = DB::connection('legacy')
            ->table('status_updates')
            ->where('store_id', $legacyStoreId)
            ->where('updateable_type', 'App\\Models\\Transaction')
            ->orderBy('updateable_id')
            ->orderBy('created_at')
            ->get();

        if ($isDryRun) {
            $this->line("  Would import {$legacyUpdates->count()} status history entries");

            return;
        }

        $count = 0;
        foreach ($legacyUpdates as $legacyUpdate) {
            // Check if transaction exists
            if (! Transaction::where('id', $legacyUpdate->updateable_id)
                ->where('store_id', $this->newStore->id)
                ->exists()) {
                continue;
            }

            // Map user
            $userId = null;
            if ($legacyUpdate->user_id && isset($this->userMap[$legacyUpdate->user_id])) {
                $userId = $this->userMap[$legacyUpdate->user_id];
            }

            // Skip if current_status is empty
            $toStatus = $this->mapStatusNameToSlug($legacyUpdate->current_status);
            if (! $toStatus || empty($legacyUpdate->current_status)) {
                continue;
            }

            StatusHistory::create([
                'trackable_type' => Transaction::class,
                'trackable_id' => $legacyUpdate->updateable_id,
                'user_id' => $userId,
                'from_status' => $this->mapStatusNameToSlug($legacyUpdate->previous_status) ?? 'pending',
                'to_status' => $toStatus,
                'notes' => null,
                'created_at' => $legacyUpdate->created_at,
                'updated_at' => $legacyUpdate->updated_at ?? $legacyUpdate->created_at,
            ]);
            $count++;
        }

        $this->line("  Imported {$count} status history entries");
    }

    protected function mapStatusNameToId(?string $statusName, ?int $defaultStatusId): ?int
    {
        if (! $statusName) {
            return $defaultStatusId;
        }

        $slug = $this->mapStatusNameToSlug($statusName);

        if (! $slug) {
            return $defaultStatusId;
        }

        $status = Status::where('store_id', $this->newStore->id)
            ->where('entity_type', 'transaction')
            ->where('slug', $slug)
            ->first();

        return $status?->id ?? $defaultStatusId;
    }

    protected function mapStatusNameToSlug(?string $statusName): ?string
    {
        if (! $statusName) {
            return null;
        }

        $statusName = strtolower($statusName);

        $statusMappings = [
            'payment processed' => 'payment_processed',
            'offer accepted' => 'offer_accepted',
            'offer declined' => 'offer_declined',
            'pending offer' => 'pending',
            'reviewed' => 'items_reviewed',
            'items received' => 'items_received',
            'kit sent' => 'kit_sent',
            'kit delivered' => 'kit_delivered',
            'return requested' => 'return_requested',
            'items returned' => 'items_returned',
            'cancelled' => 'cancelled',
            'pending' => 'pending',
        ];

        foreach ($statusMappings as $pattern => $slug) {
            if (str_contains($statusName, $pattern)) {
                return $slug;
            }
        }

        return 'pending';
    }
}
