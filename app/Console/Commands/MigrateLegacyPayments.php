<?php

namespace App\Console\Commands;

use App\Models\Memo;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Repair;
use App\Models\Store;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateLegacyPayments extends Command
{
    protected $signature = 'migrate:legacy-payments
                            {--store-id=63 : Legacy store ID to migrate}
                            {--new-store-id= : New store ID (if different from legacy)}
                            {--limit=0 : Number of payments to migrate (0 for all)}
                            {--dry-run : Show what would be migrated without making changes}
                            {--fresh : Delete existing payments and start fresh}';

    protected $description = 'Migrate payments from the legacy database';

    protected array $paymentMap = [];

    protected array $orderMap = [];

    protected array $repairMap = [];

    protected array $memoMap = [];

    protected array $userMap = [];

    protected array $customerMap = [];

    public function handle(): int
    {
        $legacyStoreId = (int) $this->option('store-id');
        $newStoreId = $this->option('new-store-id') ? (int) $this->option('new-store-id') : null;
        $limit = (int) $this->option('limit');
        $isDryRun = $this->option('dry-run');

        $this->info("Starting payment migration from legacy store ID: {$legacyStoreId}");

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        // Get legacy store info
        $legacyStore = DB::connection('legacy')
            ->table('stores')
            ->where('id', $legacyStoreId)
            ->first();

        if (! $legacyStore) {
            $this->error("Legacy store with ID {$legacyStoreId} not found");

            return 1;
        }

        // Find the new store
        $newStore = null;
        if ($newStoreId) {
            $newStore = Store::find($newStoreId);
        } else {
            $newStore = Store::where('name', $legacyStore->name)->first();
        }

        if (! $newStore) {
            $this->error('New store not found. Run migrate:legacy first to create the store.');

            return 1;
        }

        $this->info("Migrating payments to store: {$newStore->name} (ID: {$newStore->id})");

        // Load mapping files from previous migrations
        $this->loadMappingFiles($legacyStoreId);

        if ($this->option('fresh') && ! $isDryRun) {
            if ($this->confirm('This will delete all existing payments for this store. Continue?')) {
                $this->cleanupExistingPayments($newStore);
            }
        }

        try {
            DB::beginTransaction();

            // Build user mapping
            $this->buildUserMapping($legacyStoreId, $newStore);

            // Migrate payments for orders
            $this->migrateOrderPayments($legacyStoreId, $newStore, $isDryRun, $limit);

            // Migrate payments for repairs
            $this->migrateRepairPayments($legacyStoreId, $newStore, $isDryRun, $limit);

            if ($isDryRun) {
                DB::rollBack();
                $this->info('Dry run complete - no changes made');
            } else {
                DB::commit();
                $this->info('Payment migration complete!');
            }

            $this->displaySummary($newStore);

            // Save mapping files
            if (! $isDryRun && count($this->paymentMap) > 0) {
                $this->saveMappingFiles($legacyStoreId);
            }

            return 0;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Migration failed: {$e->getMessage()}");
            $this->error($e->getTraceAsString());

            return 1;
        }
    }

    protected function loadMappingFiles(int $legacyStoreId): void
    {
        $basePath = storage_path('app/migration_maps');

        // Load order map
        $orderMapFile = "{$basePath}/order_map_{$legacyStoreId}.json";
        if (file_exists($orderMapFile)) {
            $this->orderMap = json_decode(file_get_contents($orderMapFile), true) ?? [];
            $this->line('  Loaded '.count($this->orderMap).' order mappings');
        }

        // Load repair map
        $repairMapFile = "{$basePath}/repair_map_{$legacyStoreId}.json";
        if (file_exists($repairMapFile)) {
            $this->repairMap = json_decode(file_get_contents($repairMapFile), true) ?? [];
            $this->line('  Loaded '.count($this->repairMap).' repair mappings');
        }

        // Load memo map
        $memoMapFile = "{$basePath}/memo_map_{$legacyStoreId}.json";
        if (file_exists($memoMapFile)) {
            $this->memoMap = json_decode(file_get_contents($memoMapFile), true) ?? [];
            $this->line('  Loaded '.count($this->memoMap).' memo mappings');
        }

        // Load user map
        $userMapFile = "{$basePath}/user_map_{$legacyStoreId}.json";
        if (file_exists($userMapFile)) {
            $this->userMap = json_decode(file_get_contents($userMapFile), true) ?? [];
            $this->line('  Loaded '.count($this->userMap).' user mappings');
        }

        // Load customer map
        $customerMapFile = "{$basePath}/customer_map_{$legacyStoreId}.json";
        if (file_exists($customerMapFile)) {
            $this->customerMap = json_decode(file_get_contents($customerMapFile), true) ?? [];
            $this->line('  Loaded '.count($this->customerMap).' customer mappings');
        }
    }

    protected function saveMappingFiles(int $legacyStoreId): void
    {
        $basePath = storage_path('app/migration_maps');
        if (! is_dir($basePath)) {
            mkdir($basePath, 0755, true);
        }

        // Save payment map
        $paymentMapFile = "{$basePath}/payment_map_{$legacyStoreId}.json";
        file_put_contents($paymentMapFile, json_encode($this->paymentMap, JSON_PRETTY_PRINT));
        $this->line("  Payment map saved to: {$paymentMapFile}");
    }

    protected function buildUserMapping(int $legacyStoreId, Store $newStore): void
    {
        if (! empty($this->userMap)) {
            return;
        }

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

    protected function migrateOrderPayments(int $legacyStoreId, Store $newStore, bool $isDryRun, int $limit): void
    {
        $this->info('Migrating order payments...');

        $query = DB::connection('legacy')
            ->table('payments as p')
            ->join('orders as o', 'p.order_id', '=', 'o.id')
            ->where('o.store_id', $legacyStoreId)

            ->select('p.*')
            ->orderBy('p.id', 'asc');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $legacyPayments = $query->get();
        $paymentCount = 0;
        $skipped = 0;

        foreach ($legacyPayments as $legacyPayment) {
            // Check if payment already migrated
            $existingPayment = Payment::where('store_id', $newStore->id)
                ->where('reference', "legacy_{$legacyPayment->id}")
                ->first();

            if ($existingPayment) {
                $this->paymentMap[$legacyPayment->id] = $existingPayment->id;
                $skipped++;

                continue;
            }

            // Map order
            $orderId = null;
            $payableType = null;
            $payableId = null;

            if ($legacyPayment->order_id && isset($this->orderMap[$legacyPayment->order_id])) {
                $orderId = $this->orderMap[$legacyPayment->order_id];
                $payableType = Order::class;
                $payableId = $orderId;
            }

            if ($isDryRun) {
                $this->line("  Would create payment: \${$legacyPayment->amount} for order #{$legacyPayment->order_id}");
                $paymentCount++;

                continue;
            }

            if (! $orderId) {
                $skipped++;

                continue;
            }

            // Map user
            $userId = null;
            if ($legacyPayment->user_id && isset($this->userMap[$legacyPayment->user_id])) {
                $userId = $this->userMap[$legacyPayment->user_id];
            }

            // Get customer from order
            $order = Order::find($orderId);
            $customerId = $order?->customer_id;

            $newPayment = Payment::create([
                'store_id' => $newStore->id,
                'payable_type' => $payableType,
                'payable_id' => $payableId,
                'order_id' => $orderId,
                'customer_id' => $customerId,
                'user_id' => $userId,
                'payment_method' => $this->mapPaymentMethod($legacyPayment->type),
                'status' => $this->mapPaymentStatus($legacyPayment->status),
                'amount' => $legacyPayment->amount ?? 0,
                'service_fee_value' => ! empty($legacyPayment->service_fee_value) ? $legacyPayment->service_fee_value : 0,
                'service_fee_unit' => ! empty($legacyPayment->service_fee_unit) ? $this->mapServiceFeeUnit($legacyPayment->service_fee_unit) : 'fixed',
                'service_fee_amount' => $legacyPayment->service_fee ?? 0,
                'currency' => $legacyPayment->currency ?? 'USD',
                'reference' => "legacy_{$legacyPayment->id}",
                'transaction_id' => $legacyPayment->payment_gateway_transaction_id,
                'gateway' => $this->mapGateway($legacyPayment),
                'gateway_payment_id' => $legacyPayment->reference_id,
                'metadata' => [
                    'legacy_id' => $legacyPayment->id,
                    'card_type' => $legacyPayment->card_type,
                    'last_4' => $legacyPayment->last_4,
                    'entry_type' => $legacyPayment->entry_type,
                ],
                'paid_at' => $this->mapPaymentStatus($legacyPayment->status) === Payment::STATUS_COMPLETED
                    ? $legacyPayment->updated_at
                    : null,
                'created_at' => $legacyPayment->created_at,
                'updated_at' => $legacyPayment->updated_at,
            ]);

            $this->paymentMap[$legacyPayment->id] = $newPayment->id;
            $paymentCount++;

            if ($paymentCount % 50 === 0) {
                $this->line("  Processed {$paymentCount} order payments...");
            }
        }

        $this->line("  Created {$paymentCount} order payments, skipped {$skipped}");
    }

    protected function migrateRepairPayments(int $legacyStoreId, Store $newStore, bool $isDryRun, int $limit): void
    {
        $this->info('Migrating repair payments...');

        $query = DB::connection('legacy')
            ->table('payments as p')
            ->where('p.paymentable_type', 'App\\Models\\Repair')
            ->whereIn('p.paymentable_id', function ($q) use ($legacyStoreId) {
                $q->select('id')
                    ->from('repairs')
                    ->where('store_id', $legacyStoreId);
            })

            ->select('p.*')
            ->orderBy('p.id', 'asc');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $legacyPayments = $query->get();
        $paymentCount = 0;
        $skipped = 0;

        foreach ($legacyPayments as $legacyPayment) {
            // Check if payment already migrated
            $existingPayment = Payment::where('store_id', $newStore->id)
                ->where('reference', "legacy_{$legacyPayment->id}")
                ->first();

            if ($existingPayment) {
                $this->paymentMap[$legacyPayment->id] = $existingPayment->id;
                $skipped++;

                continue;
            }

            // Map repair
            $repairId = null;
            if ($legacyPayment->paymentable_id && isset($this->repairMap[$legacyPayment->paymentable_id])) {
                $repairId = $this->repairMap[$legacyPayment->paymentable_id];
            }

            if ($isDryRun) {
                $this->line("  Would create payment: \${$legacyPayment->amount} for repair #{$legacyPayment->paymentable_id}");
                $paymentCount++;

                continue;
            }

            if (! $repairId) {
                $skipped++;

                continue;
            }

            // Map user
            $userId = null;
            if ($legacyPayment->user_id && isset($this->userMap[$legacyPayment->user_id])) {
                $userId = $this->userMap[$legacyPayment->user_id];
            }

            // Get customer from repair
            $repair = Repair::find($repairId);
            $customerId = $repair?->customer_id;

            $newPayment = Payment::create([
                'store_id' => $newStore->id,
                'payable_type' => Repair::class,
                'payable_id' => $repairId,
                'customer_id' => $customerId,
                'user_id' => $userId,
                'payment_method' => $this->mapPaymentMethod($legacyPayment->type),
                'status' => $this->mapPaymentStatus($legacyPayment->status),
                'amount' => $legacyPayment->amount ?? 0,
                'service_fee_value' => ! empty($legacyPayment->service_fee_value) ? $legacyPayment->service_fee_value : 0,
                'service_fee_unit' => ! empty($legacyPayment->service_fee_unit) ? $this->mapServiceFeeUnit($legacyPayment->service_fee_unit) : 'fixed',
                'service_fee_amount' => $legacyPayment->service_fee ?? 0,
                'currency' => $legacyPayment->currency ?? 'USD',
                'reference' => "legacy_{$legacyPayment->id}",
                'transaction_id' => $legacyPayment->payment_gateway_transaction_id,
                'gateway' => $this->mapGateway($legacyPayment),
                'gateway_payment_id' => $legacyPayment->reference_id,
                'metadata' => [
                    'legacy_id' => $legacyPayment->id,
                    'card_type' => $legacyPayment->card_type,
                    'last_4' => $legacyPayment->last_4,
                    'entry_type' => $legacyPayment->entry_type,
                ],
                'paid_at' => $this->mapPaymentStatus($legacyPayment->status) === Payment::STATUS_COMPLETED
                    ? $legacyPayment->updated_at
                    : null,
                'created_at' => $legacyPayment->created_at,
                'updated_at' => $legacyPayment->updated_at,
            ]);

            $this->paymentMap[$legacyPayment->id] = $newPayment->id;
            $paymentCount++;

            if ($paymentCount % 50 === 0) {
                $this->line("  Processed {$paymentCount} repair payments...");
            }
        }

        $this->line("  Created {$paymentCount} repair payments, skipped {$skipped}");
    }

    protected function mapPaymentMethod(?string $legacyType): string
    {
        if (! $legacyType) {
            return Payment::METHOD_EXTERNAL;
        }

        return match (strtolower($legacyType)) {
            'cash', 'cash on delivery (cod)' => Payment::METHOD_CASH,
            'credit', 'debit', 'visa', 'credit card', 'card' => Payment::METHOD_CARD,
            'check' => Payment::METHOD_CHECK,
            'wire transfer', 'bank transfer' => Payment::METHOD_BANK_TRANSFER,
            'store credit' => Payment::METHOD_STORE_CREDIT,
            'authorize_net', 'shopify_payments' => Payment::METHOD_EXTERNAL,
            default => Payment::METHOD_EXTERNAL,
        };
    }

    protected function mapPaymentStatus(?string $legacyStatus): string
    {
        if (! $legacyStatus) {
            return Payment::STATUS_PENDING;
        }

        return match (strtolower($legacyStatus)) {
            'completed' => Payment::STATUS_COMPLETED,
            'pending', 'initiated' => Payment::STATUS_PENDING,
            'failed', 'declined' => Payment::STATUS_FAILED,
            'refunded' => Payment::STATUS_REFUNDED,
            'canceled', 'cancelled', 'cancel_requested' => Payment::STATUS_FAILED,
            default => Payment::STATUS_PENDING,
        };
    }

    protected function mapServiceFeeUnit(?string $legacyUnit): string
    {
        if (! $legacyUnit) {
            return 'fixed';
        }

        return match (strtolower($legacyUnit)) {
            'percent', 'percentage', '%' => 'percent',
            default => 'fixed',
        };
    }

    protected function mapGateway(object $legacyPayment): ?string
    {
        if ($legacyPayment->square_terminal_id) {
            return 'square';
        }

        if (strtolower($legacyPayment->type ?? '') === 'authorize_net') {
            return 'authorize_net';
        }

        if (strtolower($legacyPayment->type ?? '') === 'shopify_payments') {
            return 'shopify';
        }

        return null;
    }

    protected function cleanupExistingPayments(Store $newStore): void
    {
        $this->warn('Cleaning up existing payments...');

        Payment::where('store_id', $newStore->id)
            ->where('reference', 'like', 'legacy_%')
            ->forceDelete();

        $this->line('  Cleanup complete');
    }

    protected function displaySummary(Store $newStore): void
    {
        $this->newLine();
        $this->info('=== Payment Migration Summary ===');
        $this->line('Store: '.$newStore->name.' (ID: '.$newStore->id.')');
        $this->line('Payments mapped: '.count($this->paymentMap));

        $paymentCount = Payment::where('store_id', $newStore->id)->count();
        $totalAmount = Payment::where('store_id', $newStore->id)->sum('amount');
        $this->line("Total payments in store: {$paymentCount}");
        $this->line('Total payment amount: $'.number_format($totalAmount, 2));
    }
}
