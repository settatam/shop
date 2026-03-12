<?php

namespace App\Console\Commands;

use App\Models\Address;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Image;
use App\Models\ProductTemplate;
use App\Models\ProductTemplateField;
use App\Models\ProductTemplateFieldOption;
use App\Models\Status;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MigrateLegacyTransactions extends Command
{
    protected $signature = 'migrate:legacy-transactions
                            {store_id : The legacy store ID to migrate}
                            {--new-store-id= : The new store ID to migrate to (defaults to same as legacy)}
                            {--category-mapping-store= : Use category mappings from a different legacy store (e.g., if store 44 uses store 43 categories)}
                            {--limit= : Limit the number of transactions to migrate}
                            {--latest : Get the most recent transactions instead of oldest (use with --limit)}
                            {--dry-run : Run without making any changes}
                            {--fresh : Delete all existing transactions and related data for this store before migrating}
                            {--skip-customers : Skip migrating customers}
                            {--skip-images : Skip migrating images}
                            {--skip-activities : Skip migrating activities}
                            {--skip-category-mapping : Skip using category mappings (set category_id to null)}
                            {--sync-deletes : Soft-delete new records if legacy record is soft-deleted}
                            {--transaction-id= : Re-import a single legacy transaction by ID (updates existing, creates missing elements)}';

    protected $description = 'Migrate legacy transactions from shopmata-new database for a specific store';

    /**
     * Cache for resolved statuses (legacy_status_name => Status model).
     *
     * @var array<string, Status>
     */
    protected array $statusCache = [];

    /**
     * Cache for migrated customers (legacy_id => new_id).
     *
     * @var array<int, int>
     */
    protected array $customerMap = [];

    /**
     * Cache for migrated addresses (legacy_id => new_id).
     *
     * @var array<string, int>
     */
    protected array $addressMap = [];

    /**
     * Map legacy category_id to new category_id.
     *
     * @var array<int, int>
     */
    protected array $categoryMap = [];

    /**
     * Map category_id to template_id.
     *
     * @var array<int, int|null>
     */
    protected array $categoryTemplateMap = [];

    /**
     * Maps legacy html_form_field.name => new product_template_field.id (by template).
     * Structure: [template_id => [field_name => field_id]]
     *
     * @var array<int, array<string, int>>
     */
    protected array $templateFieldNameMap = [];

    /**
     * Maps template_id => [field_name => field_type].
     *
     * @var array<int, array<string, string>>
     */
    protected array $templateFieldTypeMap = [];

    /**
     * Maps template_id => [field_name => select_options].
     *
     * @var array<int, array<string, array<int, array<string, string>>>>
     */
    protected array $templateFieldOptionsMap = [];

    /**
     * Cache of legacy template ID => legacy template fields.
     *
     * @var array<int, \Illuminate\Support\Collection>
     */
    protected array $legacyTemplateFieldsCache = [];

    /**
     * Counter for migrated template attribute values.
     */
    protected int $attributeValueCount = 0;

    protected bool $dryRun = false;

    protected bool $reimport = false;

    protected int $legacyStoreId;

    protected int $newStoreId;

    public function handle(): int
    {
        // Disable Scout search indexing during import to avoid connection errors
        Transaction::disableSearchSyncing();
        Customer::disableSearchSyncing();

        $this->legacyStoreId = (int) $this->argument('store_id');
        $this->newStoreId = (int) ($this->option('new-store-id') ?? $this->legacyStoreId);
        $this->dryRun = (bool) $this->option('dry-run');

        if ($this->dryRun) {
            $this->warn('Running in DRY RUN mode - no changes will be made');
        }

        $this->info("Starting migration for store ID: {$this->legacyStoreId} -> {$this->newStoreId}");

        // Test legacy database connection
        try {
            DB::connection('legacy')->getPdo();
            $this->info('Connected to legacy database successfully.');
        } catch (\Exception $e) {
            $this->error('Could not connect to legacy database: '.$e->getMessage());
            $this->info('Please ensure you have configured the "legacy" database connection in config/database.php');

            return self::FAILURE;
        }

        // Handle single transaction re-import
        if ($this->option('transaction-id')) {
            $this->reimport = true;
            $transactionId = (int) $this->option('transaction-id');

            $legacyTransaction = DB::connection('legacy')
                ->table('transactions')
                ->where('id', $transactionId)
                ->first();

            if (! $legacyTransaction) {
                $this->error("Legacy transaction #{$transactionId} not found.");

                return self::FAILURE;
            }

            $this->info("Re-importing transaction #{$transactionId}...");

            try {
                $result = $this->migrateTransaction($legacyTransaction);
                $this->info("Transaction #{$transactionId} re-imported successfully ({$result}).");
            } catch (\Exception $e) {
                $this->error("Failed to re-import transaction #{$transactionId}: {$e->getMessage()}");
                Log::error('Failed to re-import transaction', [
                    'legacy_id' => $transactionId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        // Handle fresh option - delete all existing transaction data for this store
        if ($this->option('fresh')) {
            if (! $this->dryRun) {
                if (! $this->confirm("This will DELETE all transactions, transaction items, related images, addresses, and activity logs for store {$this->newStoreId}. Are you sure?")) {
                    $this->info('Operation cancelled.');

                    return self::SUCCESS;
                }
                $this->deleteExistingTransactionData();
            } else {
                $this->warn('Fresh mode: Would delete all transaction data for store '.$this->newStoreId);
            }
        }

        // Category lookups are now done at runtime from the legacy store_categories table
        // This ensures accurate mapping even if product_type_id references categories from other stores
        if (! $this->option('skip-category-mapping')) {
            $this->info('Category lookups will be performed at runtime from legacy store_categories table.');
        }

        $syncDeletes = (bool) $this->option('sync-deletes');

        if ($syncDeletes) {
            $this->info('Sync deletes enabled - will soft-delete transactions if legacy is soft-deleted');
        }

        // Get transactions from legacy database
        $order = $this->option('latest') ? 'desc' : 'asc';
        $query = DB::connection('legacy')
            ->table('transactions')
            ->where('store_id', $this->legacyStoreId)
            ->whereNotNull('customer_id') // Skip transactions without customers
            ->orderBy('created_at', $order);

        // Only skip deleted if NOT syncing deletes
        if (! $syncDeletes) {
            $query->whereNull('deleted_at');
        }

        if ($this->option('limit')) {
            $query->limit((int) $this->option('limit'));
        }

        $totalCount = (clone $query)->count();

        $this->info("Found {$totalCount} transactions to migrate");

        if ($totalCount === 0) {
            $this->warn('No transactions found for this store');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($totalCount);
        $bar->start();

        $migrated = 0;
        $skipped = 0;
        $synced = 0;
        $errors = 0;

        $query->chunk(200, function ($legacyTransactions) use ($syncDeletes, &$migrated, &$skipped, &$synced, &$errors, $bar) {
            foreach ($legacyTransactions as $legacyTransaction) {
                try {
                    $result = $this->migrateTransaction($legacyTransaction, $syncDeletes);
                    if ($result === 'skipped') {
                        $skipped++;
                    } elseif ($result === 'synced') {
                        $synced++;
                    } else {
                        $migrated++;
                    }
                } catch (\Exception $e) {
                    $errors++;
                    Log::error('Failed to migrate transaction', [
                        'legacy_id' => $legacyTransaction->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    $this->newLine();
                    $this->error("Failed to migrate transaction #{$legacyTransaction->id}: {$e->getMessage()}");
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info('Migration complete!');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Migrated', $migrated],
                ['Skipped', $skipped],
                ['Soft-deleted (synced)', $synced],
                ['Template attribute values', $this->attributeValueCount],
                ['Errors', $errors],
            ]
        );

        return self::SUCCESS;
    }

    /**
     * Delete all existing transaction data for the store.
     * This includes transactions, transaction items, images, addresses, and activity logs.
     */
    protected function deleteExistingTransactionData(): void
    {
        $this->info('Deleting existing transaction data for store '.$this->newStoreId.'...');

        // Get all transaction IDs for this store (including soft-deleted)
        $transactionIds = Transaction::withTrashed()
            ->where('store_id', $this->newStoreId)
            ->pluck('id')
            ->toArray();

        if (empty($transactionIds)) {
            $this->info('No existing transactions found.');

            return;
        }

        $this->info('Found '.count($transactionIds).' transactions to delete.');

        // Get all transaction item IDs
        $transactionItemIds = TransactionItem::whereIn('transaction_id', $transactionIds)
            ->pluck('id')
            ->toArray();

        $this->info('Found '.count($transactionItemIds).' transaction items to delete.');

        // Delete images for transaction items (polymorphic)
        $deletedItemImages = DB::table('images')
            ->where('imageable_type', TransactionItem::class)
            ->whereIn('imageable_id', $transactionItemIds)
            ->delete();
        $this->info("Deleted {$deletedItemImages} transaction item images.");

        // Delete images for transactions (polymorphic)
        $deletedTransactionImages = DB::table('images')
            ->where('imageable_type', Transaction::class)
            ->whereIn('imageable_id', $transactionIds)
            ->delete();
        $this->info("Deleted {$deletedTransactionImages} transaction images.");

        // Delete addresses for transactions (polymorphic)
        $deletedAddresses = DB::table('addresses')
            ->where('addressable_type', Transaction::class)
            ->whereIn('addressable_id', $transactionIds)
            ->delete();
        $this->info("Deleted {$deletedAddresses} transaction addresses.");

        // Delete activity logs for transactions
        $deletedActivities = DB::table('activity_logs')
            ->where('subject_type', Transaction::class)
            ->whereIn('subject_id', $transactionIds)
            ->delete();
        $this->info("Deleted {$deletedActivities} activity logs.");

        // Delete transaction items (use DB to bypass soft deletes)
        $deletedItems = DB::table('transaction_items')
            ->whereIn('transaction_id', $transactionIds)
            ->delete();
        $this->info("Deleted {$deletedItems} transaction items.");

        // Delete transactions (use DB to bypass soft deletes)
        $deletedTransactions = DB::table('transactions')
            ->where('store_id', $this->newStoreId)
            ->delete();
        $this->info("Deleted {$deletedTransactions} transactions.");

        // Clear the customer map cache since we may need to re-match customers
        $this->customerMap = [];

        $this->info('Existing transaction data deleted successfully.');
        $this->newLine();
    }

    protected function migrateTransaction(object $legacyTransaction, bool $syncDeletes = false): string
    {
        // Check if already migrated by legacy ID for this store
        $existing = Transaction::withTrashed()
            ->where('store_id', $this->newStoreId)
            ->where('transaction_number', (string) $legacyTransaction->id)
            ->first();

        if ($existing && ! $this->reimport) {
            // Sync soft-delete status if enabled
            if ($syncDeletes && $legacyTransaction->deleted_at && ! $existing->deleted_at) {
                if (! $this->dryRun) {
                    $existing->delete();
                }

                return 'synced';
            }

            return 'skipped'; // Already migrated
        }

        // Migrate customer first if not skipped
        $customerId = null;
        if (! $this->option('skip-customers') && $legacyTransaction->customer_id) {
            $customerId = $this->migrateCustomer($legacyTransaction->customer_id);
        }

        // Get legacy status name from legacy database
        $legacyStatus = DB::connection('legacy')
            ->table('statuses')
            ->where('store_id', $this->legacyStoreId)
            ->where('status_id', $legacyTransaction->status_id)
            ->first();

        // Find or create status in new system using the exact legacy name
        $status = $this->findOrCreateStatus($legacyStatus?->name);
        $statusName = $status->slug;

        // Determine type based on is_in_house flag
        $type = $legacyTransaction->is_in_house ? Transaction::TYPE_IN_STORE : Transaction::TYPE_MAIL_IN;

        // Get legacy tracking info
        $legacyOutboundTracking = DB::connection('legacy')
            ->table('shipping_labels')
            ->where('shippable_type', Transaction::class)
            ->where('shippable_id', $legacyTransaction->id)
            ->where('to_customer', true)
            ->first();

        $legacyReturnTracking = DB::connection('legacy')
            ->table('shipping_labels')
            ->where('shippable_type', Transaction::class)
            ->where('shippable_id', $legacyTransaction->id)
            ->where('is_return', true)
            ->first();

        // Get ALL legacy payment addresses for payment methods (a transaction can have multiple)
        $legacyPaymentAddresses = DB::connection('legacy')
            ->table('transaction_payment_addresses')
            ->where('transaction_id', $legacyTransaction->id)
            ->get();

        $paymentMethods = $this->mapPaymentMethods($legacyPaymentAddresses);

        if ($this->dryRun) {
            return 'migrated';
        }

        // Get payment_processed_at from the legacy activity log
        $paymentActivity = DB::connection('legacy')
            ->table('store_activities')
            ->where('activityable_type', 'App\\Models\\Transaction')
            ->where('activityable_id', $legacyTransaction->id)
            ->where('activity', 'payment_added_to_transaction')
            ->orderBy('created_at', 'asc')
            ->first();

        $paymentProcessedAt = $paymentActivity?->created_at;

        // Create the transaction using DB insert to preserve timestamps exactly
        $transactionData = [
            'legacy_id' => $legacyTransaction->id,
            'store_id' => $this->newStoreId,
            'customer_id' => $customerId,
            'user_id' => null, // We don't migrate users
            'transaction_number' => (string) $legacyTransaction->id, // Use legacy ID as transaction number
            'status' => $statusName, // Use exact legacy status name
            'type' => $type,
            'source' => null, // All legacy transactions are in-store
            'preliminary_offer' => $legacyTransaction->preliminary_offer ?? 0,
            'final_offer' => $legacyTransaction->final_offer ?? 0,
            'estimated_value' => $legacyTransaction->estimated_value ?? 0,
            'payment_method' => $paymentMethods,
            'payment_details' => json_encode([
                'legacy_id' => $legacyTransaction->id,
                'legacy_status_id' => $legacyTransaction->status_id,
                'legacy_status_name' => $legacyStatus?->name,
                'legacy_payment_addresses' => $legacyPaymentAddresses->map(fn ($pa) => (array) $pa)->toArray(),
            ]),
            'status_id' => $status->id,
            'bin_location' => $legacyTransaction->bin_location,
            'customer_notes' => $legacyTransaction->pub_note ?? null,
            'internal_notes' => $legacyTransaction->private_note ?? null,
            'customer_description' => $legacyTransaction->customer_description ?? null,
            'created_at' => $legacyTransaction->created_at,
            'updated_at' => $legacyTransaction->updated_at,
            'payment_processed_at' => $paymentProcessedAt,
        ];

        // Only add tracking fields if we have actual tracking data (to let defaults apply)
        if ($legacyOutboundTracking?->tracking_number) {
            $transactionData['outbound_tracking_number'] = $legacyOutboundTracking->tracking_number;
            $transactionData['outbound_carrier'] = 'fedex';
        }
        if ($legacyReturnTracking?->tracking_number) {
            $transactionData['return_tracking_number'] = $legacyReturnTracking->tracking_number;
            $transactionData['return_carrier'] = 'fedex';
        }

        // Preserve original legacy ID as primary key
        $transactionData['id'] = $legacyTransaction->id;

        if ($this->reimport && $existing) {
            // Update existing transaction and restore if soft-deleted
            DB::table('transactions')
                ->where('id', $legacyTransaction->id)
                ->update(array_merge($transactionData, ['deleted_at' => null]));
            $transaction = Transaction::find($legacyTransaction->id);
        } else {
            DB::table('transactions')->insert($transactionData);
            $transaction = Transaction::find($legacyTransaction->id);
        }

        // Migrate items
        $this->migrateTransactionItems($legacyTransaction->id, $transaction);

        // Migrate transaction images
        if (! $this->option('skip-images')) {
            $this->migrateTransactionImages($legacyTransaction->id, $transaction);
        }

        // Migrate activities
        if (! $this->option('skip-activities')) {
            $this->migrateActivities($legacyTransaction->id, $transaction);
        }

        // Migrate shipping address if exists
        $this->migrateShippingAddress($legacyTransaction, $transaction);

        return 'migrated';
    }

    protected function migrateCustomer(int $legacyCustomerId): ?int
    {
        // Check cache first (skip cache when reimporting so customer data gets refreshed)
        if (isset($this->customerMap[$legacyCustomerId]) && ! $this->reimport) {
            return $this->customerMap[$legacyCustomerId];
        }

        $legacyCustomer = DB::connection('legacy')
            ->table('customers')
            ->where('id', $legacyCustomerId)
            ->first();

        if (! $legacyCustomer) {
            return null;
        }

        // Check if customer already exists in new system by email or phone
        $existingCustomer = null;
        if ($legacyCustomer->email) {
            $existingCustomer = Customer::where('store_id', $this->newStoreId)
                ->where('email', $legacyCustomer->email)
                ->first();
        }

        if (! $existingCustomer && $legacyCustomer->phone_number) {
            $existingCustomer = Customer::where('store_id', $this->newStoreId)
                ->where('phone_number', $legacyCustomer->phone_number)
                ->first();
        }

        if ($existingCustomer && ! $this->reimport) {
            $this->customerMap[$legacyCustomerId] = $existingCustomer->id;

            return $existingCustomer->id;
        }

        if ($existingCustomer && $this->reimport) {
            // Update existing customer with legacy data
            $this->updateCustomerFromLegacy($existingCustomer, $legacyCustomer);
            $this->customerMap[$legacyCustomerId] = $existingCustomer->id;

            return $existingCustomer->id;
        }

        // Check if customer with this ID already exists (from another store migration)
        $existingById = Customer::withTrashed()->find($legacyCustomerId);
        if ($existingById && ! $this->reimport) {
            $this->customerMap[$legacyCustomerId] = $existingById->id;

            return $existingById->id;
        }

        if ($existingById && $this->reimport) {
            $this->updateCustomerFromLegacy($existingById, $legacyCustomer);
            $this->customerMap[$legacyCustomerId] = $existingById->id;

            return $existingById->id;
        }

        if ($this->dryRun) {
            return null;
        }

        // Get legacy state
        $legacyState = null;
        if ($legacyCustomer->state_id) {
            $legacyState = DB::connection('legacy')
                ->table('states')
                ->where('id', $legacyCustomer->state_id)
                ->first();
        }

        // Create customer using DB insert to preserve exact timestamps and original ID
        DB::table('customers')->insert([
            'id' => $legacyCustomerId, // Preserve original ID
            'store_id' => $this->newStoreId,
            'first_name' => $legacyCustomer->first_name,
            'last_name' => $legacyCustomer->last_name,
            'company_name' => $legacyCustomer->company_name,
            'email' => $legacyCustomer->email,
            'phone_number' => $legacyCustomer->phone_number,
            'address' => $legacyCustomer->street_address ?? $legacyCustomer->address ?? null,
            'address2' => $legacyCustomer->street_address2 ?? $legacyCustomer->address2 ?? null,
            'city' => $legacyCustomer->city ?? null,
            'state' => $legacyState?->code ?? $legacyCustomer->state ?? null,
            'state_id' => $legacyCustomer->state_id ?? null,
            'country_id' => $legacyCustomer->country_id ?? null,
            'zip' => $legacyCustomer->zip ?? null,
            'ethnicity' => $legacyCustomer->ethnicity,
            'photo' => $legacyCustomer->drivers_license_photo ?? $legacyCustomer->photo ?? null,
            'accepts_marketing' => (bool) ($legacyCustomer->accepts_marketing ?? false),
            'is_active' => (bool) ($legacyCustomer->is_active ?? true),
            'number_of_sales' => $legacyCustomer->number_of_sales ?? 0,
            'number_of_buys' => $legacyCustomer->number_of_buys ?? 0,
            'last_sales_date' => $legacyCustomer->last_sales_date ?? null,
            'additional_fields' => json_encode([
                'legacy_notes' => $legacyCustomer->customer_notes ?? null,
            ]),
            'created_at' => $legacyCustomer->created_at,
            'updated_at' => $legacyCustomer->updated_at,
        ]);

        $customer = Customer::find($legacyCustomerId);

        // Migrate customer address if exists
        $this->migrateCustomerAddress($legacyCustomerId, $customer);

        $this->customerMap[$legacyCustomerId] = $legacyCustomerId;

        return $legacyCustomerId;
    }

    protected function updateCustomerFromLegacy(Customer $customer, object $legacyCustomer): void
    {
        if ($this->dryRun) {
            return;
        }

        $legacyState = null;
        if ($legacyCustomer->state_id ?? null) {
            $legacyState = DB::connection('legacy')
                ->table('states')
                ->where('id', $legacyCustomer->state_id)
                ->first();
        }

        $legacyData = (array) $legacyCustomer;

        Customer::withoutGlobalScopes()
            ->where('id', $customer->id)
            ->update([
                'first_name' => $legacyCustomer->first_name,
                'last_name' => $legacyCustomer->last_name,
                'company_name' => $legacyCustomer->company_name ?? null,
                'email' => $legacyCustomer->email,
                'phone_number' => $legacyCustomer->phone_number ?? null,
                'address' => $legacyData['street_address'] ?? $legacyData['address'] ?? null,
                'address2' => $legacyData['street_address2'] ?? $legacyData['address2'] ?? null,
                'city' => $legacyCustomer->city ?? null,
                'state' => $legacyState?->code ?? ($legacyCustomer->state ?? null),
                'state_id' => $legacyCustomer->state_id ?? null,
                'zip' => $legacyCustomer->zip ?? null,
            ]);
    }

    protected function migrateCustomerAddress(int $legacyCustomerId, Customer $customer): void
    {
        $legacyAddress = DB::connection('legacy')
            ->table('addresses')
            ->where('addressable_type', 'App\\Models\\Customer')
            ->where('addressable_id', $legacyCustomerId)
            ->first();

        if (! $legacyAddress || $this->dryRun) {
            return;
        }

        try {
            // Get state
            $legacyState = null;
            if ($legacyAddress->state_id) {
                $legacyState = DB::connection('legacy')
                    ->table('states')
                    ->where('id', $legacyAddress->state_id)
                    ->first();
            }

            // Use DB insert to preserve exact timestamps
            DB::table('addresses')->insert([
                'store_id' => $this->newStoreId,
                'addressable_type' => Customer::class,
                'addressable_id' => $customer->id,
                'type' => 'primary',
                'first_name' => $legacyAddress->first_name ?? null,
                'last_name' => $legacyAddress->last_name ?? null,
                'address' => $legacyAddress->address,
                'address2' => $legacyAddress->address2,
                'city' => $legacyAddress->city,
                'state_id' => $legacyAddress->state_id,
                'zip' => $legacyAddress->zip,
                'country_id' => 1, // US
                'phone' => $legacyAddress->phone ?? null,
                'is_default' => true,
                'created_at' => $legacyAddress->created_at,
                'updated_at' => $legacyAddress->updated_at,
            ]);
        } catch (\Exception $e) {
            Log::warning("Failed to migrate customer address for legacy customer #{$legacyCustomerId}: {$e->getMessage()}");
            $this->warn("  Warning: Failed to migrate customer address for customer #{$legacyCustomerId}: {$e->getMessage()}");
        }
    }

    protected function migrateShippingAddress(object $legacyTransaction, Transaction $transaction): void
    {
        // Get the transaction's payment address which often contains the shipping info
        $legacyAddress = DB::connection('legacy')
            ->table('addresses')
            ->where('addressable_type', 'App\\Models\\Transaction')
            ->where('addressable_id', $legacyTransaction->id)
            ->first();

        if (! $legacyAddress || $this->dryRun) {
            return;
        }

        try {
            // Get state
            $legacyState = null;
            if ($legacyAddress->state_id) {
                $legacyState = DB::connection('legacy')
                    ->table('states')
                    ->where('id', $legacyAddress->state_id)
                    ->first();
            }

            // Use DB insert to preserve exact timestamps
            $addressId = DB::table('addresses')->insertGetId([
                'store_id' => $this->newStoreId,
                'addressable_type' => Transaction::class,
                'addressable_id' => $transaction->id,
                'type' => 'shipping',
                'first_name' => $legacyAddress->first_name ?? null,
                'last_name' => $legacyAddress->last_name ?? null,
                'address' => $legacyAddress->address,
                'address2' => $legacyAddress->address2,
                'city' => $legacyAddress->city,
                'state_id' => $legacyAddress->state_id,
                'zip' => $legacyAddress->zip,
                'country_id' => 1, // US
                'phone' => $legacyAddress->phone ?? null,
                'is_shipping' => true,
                'created_at' => $legacyAddress->created_at,
                'updated_at' => $legacyAddress->updated_at,
            ]);

            $transaction->update(['shipping_address_id' => $addressId]);
        } catch (\Exception $e) {
            Log::warning("Failed to migrate shipping address for transaction #{$legacyTransaction->id}: {$e->getMessage()}");
            $this->warn("  Warning: Failed to migrate shipping address for transaction #{$legacyTransaction->id}: {$e->getMessage()}");
        }
    }

    protected function migrateTransactionItems(int $legacyTransactionId, Transaction $transaction): void
    {
        $legacyItems = DB::connection('legacy')
            ->table('transaction_items')
            ->where('transaction_id', $legacyTransactionId)
            ->get();

        foreach ($legacyItems as $legacyItem) {
            if ($this->dryRun) {
                continue;
            }

            // Check if transaction item with this ID already exists
            $existingItem = TransactionItem::find($legacyItem->id);
            if ($existingItem && ! $this->reimport) {
                continue;
            }

            // Find category — prefer category_id (actual store_categories FK), fall back to product_type_id
            $legacyCategoryId = ! empty($legacyItem->category_id) ? (int) $legacyItem->category_id : null;
            $legacyProductTypeId = ! empty($legacyItem->product_type_id) ? (int) $legacyItem->product_type_id : null;
            $legacyProductTypeId = $legacyCategoryId ?? $legacyProductTypeId;
            $categoryResult = $this->findOrCreateCategoryWithTemplate($legacyProductTypeId, $legacyItem->html_form_id ?? null);
            $categoryId = $categoryResult['category_id'];
            $templateId = $categoryResult['template_id'];

            // Build initial attributes with legacy info and template reference
            $attributes = [
                'legacy_category_id' => $legacyItem->category_id,
                'legacy_product_type_id' => $legacyItem->product_type_id ?? null,
                'legacy_html_form_id' => $legacyItem->html_form_id ?? null,
                'template_id' => $templateId,
                'template_values' => [], // Will be populated by migrateTransactionItemMetas
            ];

            $itemData = [
                'legacy_id' => $legacyItem->id,
                'transaction_id' => $transaction->id,
                'category_id' => $categoryId,
                'sku' => $legacyItem->sku,
                'title' => $legacyItem->title ?? $legacyItem->item ?? 'Item',
                'description' => $legacyItem->description,
                'quantity' => 1,
                'price' => $legacyItem->price ?? 0,
                'buy_price' => $legacyItem->buy_price ?? 0,
                'dwt' => $legacyItem->dwt ?? 0,
                'precious_metal' => $legacyItem->precious_metal,
                'condition' => $legacyItem->condition ?? null,
                'is_added_to_inventory' => (bool) ($legacyItem->is_added_to_inventory ?? false),
                'date_added_to_inventory' => $legacyItem->date_added_to_inventory,
                'reviewed_at' => $legacyItem->reviewed_date_time ?? null,
                'attributes' => json_encode($attributes),
                'created_at' => $legacyItem->created_at,
                'updated_at' => $legacyItem->updated_at,
            ];

            if ($existingItem && $this->reimport) {
                DB::table('transaction_items')
                    ->where('id', $legacyItem->id)
                    ->update($itemData);
                $item = TransactionItem::find($legacyItem->id);
            } else {
                $itemData['id'] = $legacyItem->id;
                DB::table('transaction_items')->insert($itemData);
                $item = TransactionItem::find($legacyItem->id);
            }

            // Migrate template attribute values from legacy metas table
            $this->migrateTransactionItemMetas($legacyItem, $item, $templateId);

            // Migrate item images
            if (! $this->option('skip-images')) {
                $this->migrateItemImages($legacyItem->id, $item);
            }
        }
    }

    protected function migrateItemImages(int $legacyItemId, TransactionItem $item): void
    {
        // Check both transaction_item_images and the polymorphic images table
        $legacyImages = DB::connection('legacy')
            ->table('images')
            ->where('imageable_type', 'App\\Models\\TransactionItem')
            ->where('imageable_id', $legacyItemId)
            ->get();

        // Also check the specific transaction_item_images table
        $legacyItemImages = DB::connection('legacy')
            ->table('transaction_item_images')
            ->where('transaction_item_id', $legacyItemId)
            ->get();

        foreach ($legacyImages as $legacyImage) {
            if ($this->dryRun) {
                continue;
            }

            // Extract path from URL for legacy images
            $path = parse_url($legacyImage->url, PHP_URL_PATH) ?? $legacyImage->url;

            // Use DB insert to preserve exact timestamps
            DB::table('images')->insert([
                'store_id' => $this->newStoreId,
                'imageable_type' => TransactionItem::class,
                'imageable_id' => $item->id,
                'path' => $path,
                'url' => $legacyImage->url,
                'thumbnail_url' => $legacyImage->thumbnail ?? null,
                'sort_order' => $legacyImage->rank ?? 0,
                'created_at' => $legacyImage->created_at,
                'updated_at' => $legacyImage->updated_at,
            ]);
        }

        foreach ($legacyItemImages as $legacyImage) {
            if ($this->dryRun) {
                continue;
            }

            // Check if we already imported this image (by URL)
            $exists = Image::where('imageable_type', TransactionItem::class)
                ->where('imageable_id', $item->id)
                ->where('url', $legacyImage->url)
                ->exists();

            if (! $exists) {
                $path = parse_url($legacyImage->url, PHP_URL_PATH) ?? $legacyImage->url;

                // Use DB insert to preserve exact timestamps
                DB::table('images')->insert([
                    'store_id' => $this->newStoreId,
                    'imageable_type' => TransactionItem::class,
                    'imageable_id' => $item->id,
                    'path' => $path,
                    'url' => $legacyImage->url,
                    'thumbnail_url' => $legacyImage->thumbnail_url ?? null,
                    'sort_order' => 0,
                    'created_at' => $legacyImage->created_at,
                    'updated_at' => $legacyImage->updated_at,
                ]);
            }
        }
    }

    protected function migrateTransactionImages(int $legacyTransactionId, Transaction $transaction): void
    {
        $legacyImages = DB::connection('legacy')
            ->table('images')
            ->where('imageable_type', 'App\\Models\\Transaction')
            ->where('imageable_id', $legacyTransactionId)
            ->get();

        foreach ($legacyImages as $legacyImage) {
            if ($this->dryRun) {
                continue;
            }

            $path = parse_url($legacyImage->url, PHP_URL_PATH) ?? $legacyImage->url;

            // Use DB insert to preserve exact timestamps
            DB::table('images')->insert([
                'store_id' => $this->newStoreId,
                'imageable_type' => Transaction::class,
                'imageable_id' => $transaction->id,
                'path' => $path,
                'url' => $legacyImage->url,
                'thumbnail_url' => $legacyImage->thumbnail ?? null,
                'sort_order' => $legacyImage->rank ?? 0,
                'created_at' => $legacyImage->created_at,
                'updated_at' => $legacyImage->updated_at,
            ]);
        }
    }

    protected function migrateActivities(int $legacyTransactionId, Transaction $transaction): void
    {
        // Migrate from store_activities table
        $legacyActivities = DB::connection('legacy')
            ->table('store_activities')
            ->where('activityable_type', 'App\\Models\\Transaction')
            ->where('activityable_id', $legacyTransactionId)
            ->orderBy('created_at', 'asc')
            ->get();

        foreach ($legacyActivities as $legacyActivity) {
            if ($this->dryRun) {
                continue;
            }

            // Map legacy activity to new activity slug
            $activitySlug = $this->mapActivitySlug($legacyActivity->activity);

            // Check if user exists in new database, set to null if not
            $userId = $legacyActivity->user_id;
            if ($userId) {
                $userExists = DB::connection('mysql')->table('users')->where('id', $userId)->exists();
                if (! $userExists) {
                    $userId = null;
                }
            }

            // Handle causer - check if it exists when it's a User
            $causerType = $legacyActivity->creatable_type ? $this->mapCauserType($legacyActivity->creatable_type) : null;
            $causerId = $legacyActivity->creatable_id;
            if ($causerType === 'App\\Models\\User' && $causerId) {
                $causerExists = DB::connection('mysql')->table('users')->where('id', $causerId)->exists();
                if (! $causerExists) {
                    $causerType = null;
                    $causerId = null;
                }
            }

            // Use DB insert to preserve exact timestamps
            DB::table('activity_logs')->insert([
                'store_id' => $this->newStoreId,
                'user_id' => $userId,
                'activity_slug' => $activitySlug,
                'subject_type' => Transaction::class,
                'subject_id' => $transaction->id,
                'causer_type' => $causerType,
                'causer_id' => $causerId,
                'properties' => json_encode([
                    'legacy_activity' => $legacyActivity->activity,
                    'legacy_description' => $legacyActivity->description,
                    'legacy_id' => $legacyActivity->id,
                ]),
                'description' => $legacyActivity->description,
                'created_at' => $legacyActivity->created_at,
                'updated_at' => $legacyActivity->updated_at,
            ]);
        }

        // Also migrate from the 'activities' table (status changes with is_status = 1)
        $this->migrateStatusActivities($legacyTransactionId, $transaction);

        // Also migrate non-status activities (notes, notifications, calls, SMS, etc.)
        $this->migrateNonStatusActivities($legacyTransactionId, $transaction);
    }

    /**
     * Migrate non-status activities from the legacy 'activities' table.
     * These include notes, notifications, calls, SMS, etc. (is_status = 0, is_tag = 0)
     */
    protected function migrateNonStatusActivities(int $legacyTransactionId, Transaction $transaction): void
    {
        $legacyActivities = DB::connection('legacy')
            ->table('activities')
            ->where('activityable_type', 'App\\Models\\Transaction')
            ->where('activityable_id', $legacyTransactionId)
            ->where('is_status', 0)
            ->where('is_tag', 0)
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->orderBy('created_at', 'asc')
            ->get();

        foreach ($legacyActivities as $legacyActivity) {
            if ($this->dryRun) {
                continue;
            }

            // Check if user exists in new database
            $userId = $legacyActivity->user_id;
            if ($userId) {
                $userExists = DB::connection('mysql')->table('users')->where('id', $userId)->exists();
                if (! $userExists) {
                    $userId = null;
                }
            }

            // Determine activity slug based on the activity name
            $activitySlug = $this->mapNonStatusActivitySlug($legacyActivity->name);

            // Use DB insert to preserve exact timestamps
            DB::table('activity_logs')->insert([
                'store_id' => $this->newStoreId,
                'user_id' => $userId,
                'activity_slug' => $activitySlug,
                'subject_type' => Transaction::class,
                'subject_id' => $transaction->id,
                'causer_type' => $userId ? 'App\\Models\\User' : null,
                'causer_id' => $userId,
                'properties' => json_encode([
                    'legacy_name' => $legacyActivity->name,
                    'legacy_status' => $legacyActivity->status,
                    'notes' => $legacyActivity->notes,
                    'is_from_admin' => $legacyActivity->is_from_admin,
                    'legacy_activity_id' => $legacyActivity->id,
                ]),
                'description' => $legacyActivity->name,
                'created_at' => $legacyActivity->created_at,
                'updated_at' => $legacyActivity->updated_at,
            ]);
        }
    }

    /**
     * Map non-status activity names to activity slugs.
     */
    protected function mapNonStatusActivitySlug(?string $activityName): string
    {
        if (! $activityName) {
            return 'transactions.update';
        }

        $normalizedName = strtolower(trim($activityName));

        // Map common activity types
        if (str_contains($normalizedName, 'sms') || str_contains($normalizedName, 'text')) {
            return 'transactions.update';
        }
        if (str_contains($normalizedName, 'call') || str_contains($normalizedName, 'called')) {
            return 'transactions.update';
        }
        if (str_contains($normalizedName, 'email')) {
            return 'transactions.update';
        }
        if (str_contains($normalizedName, 'note')) {
            return 'notes.create';
        }
        if (str_contains($normalizedName, 'shipping') || str_contains($normalizedName, 'fedex') || str_contains($normalizedName, 'tracking')) {
            return 'transactions.update';
        }
        if (str_contains($normalizedName, 'package')) {
            return 'transactions.update';
        }

        return 'transactions.update';
    }

    /**
     * Migrate status change activities from the legacy 'activities' table.
     * These records have is_status = 1 and contain the historical status transitions.
     */
    protected function migrateStatusActivities(int $legacyTransactionId, Transaction $transaction): void
    {
        $legacyStatusActivities = DB::connection('legacy')
            ->table('activities')
            ->where('activityable_type', 'App\\Models\\Transaction')
            ->where('activityable_id', $legacyTransactionId)
            ->where('is_status', 1)
            ->orderBy('created_at', 'asc')
            ->get();

        foreach ($legacyStatusActivities as $legacyActivity) {
            if ($this->dryRun) {
                continue;
            }

            // Check if user exists in new database
            $userId = $legacyActivity->user_id;
            if ($userId) {
                $userExists = DB::connection('mysql')->table('users')->where('id', $userId)->exists();
                if (! $userExists) {
                    $userId = null;
                }
            }

            // Find or create the status in the new system
            $newStatusModel = $this->findOrCreateStatus($legacyActivity->status);
            $newStatus = $newStatusModel->slug;
            $activitySlug = $this->mapStatusToActivitySlug($legacyActivity->status);

            // Use DB insert to preserve exact timestamps
            DB::table('activity_logs')->insert([
                'store_id' => $this->newStoreId,
                'user_id' => $userId,
                'activity_slug' => $activitySlug,
                'subject_type' => Transaction::class,
                'subject_id' => $transaction->id,
                'causer_type' => $userId ? 'App\\Models\\User' : null,
                'causer_id' => $userId,
                'properties' => json_encode([
                    'legacy_status' => $legacyActivity->status,
                    'new_status' => $newStatus,
                    'offer' => $legacyActivity->offer,
                    'notes' => $legacyActivity->notes,
                    'is_from_admin' => $legacyActivity->is_from_admin,
                    'legacy_activity_id' => $legacyActivity->id,
                ]),
                'description' => $legacyActivity->name ?: "Status changed to {$legacyActivity->status}",
                'created_at' => $legacyActivity->created_at,
                'updated_at' => $legacyActivity->updated_at,
            ]);
        }
    }

    /**
     * Map legacy status name to activity slug.
     */
    protected function mapStatusToActivitySlug(?string $statusName): string
    {
        if (! $statusName) {
            return 'transactions.status_change';
        }

        $normalizedName = strtolower(trim($statusName));

        // Map specific status changes to their activity slugs
        if (str_contains($normalizedName, 'offer given') || str_contains($normalizedName, 'offer #')) {
            return 'transactions.submit_offer';
        }
        if (str_contains($normalizedName, 'offer accepted')) {
            return 'transactions.accept_offer';
        }
        if (str_contains($normalizedName, 'offer declined') || str_contains($normalizedName, 'customer declined')) {
            return 'transactions.decline_offer';
        }
        if (str_contains($normalizedName, 'payment processed') || str_contains($normalizedName, 'payments processed')) {
            return 'transactions.process_payment';
        }
        if (str_contains($normalizedName, 'pending kit request')) {
            return 'transactions.create';
        }

        return 'transactions.status_change';
    }

    /**
     * Find or create a Status record in the new system from a legacy status name.
     * Caches results to avoid repeated DB queries.
     */
    protected function findOrCreateStatus(?string $legacyStatusName): Status
    {
        $name = trim($legacyStatusName ?? 'Pending') ?: 'Pending';
        $slug = Str::slug($name, '_');

        if (isset($this->statusCache[$slug])) {
            return $this->statusCache[$slug];
        }

        $status = Status::firstOrCreate(
            [
                'store_id' => $this->newStoreId,
                'entity_type' => 'transaction',
                'slug' => $slug,
            ],
            [
                'name' => $name,
                'is_default' => $slug === 'pending',
                'is_system' => false,
                'sort_order' => count($this->statusCache),
            ]
        );

        $this->statusCache[$slug] = $status;

        return $status;
    }

    /**
     * Map multiple legacy payment addresses to a comma-separated payment method string.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $paymentAddresses
     */
    protected function mapPaymentMethods($paymentAddresses): ?string
    {
        if ($paymentAddresses->isEmpty()) {
            return null;
        }

        $methods = $paymentAddresses
            ->map(fn ($pa) => $this->mapPaymentMethodById((int) $pa->payment_type_id))
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        return count($methods) > 0 ? implode(',', $methods) : null;
    }

    /**
     * Map a legacy payment type ID to the new payment method constant.
     * Uses the legacy code's hardcoded mapping (from PaymentType::getIdFromName).
     */
    protected function mapPaymentMethodById(int $paymentTypeId): ?string
    {
        return match ($paymentTypeId) {
            1 => Transaction::PAYMENT_CHECK,
            2 => Transaction::PAYMENT_PAYPAL,
            3 => Transaction::PAYMENT_ACH,
            4 => Transaction::PAYMENT_VENMO,
            5 => Transaction::PAYMENT_STORE_CREDIT,
            6 => Transaction::PAYMENT_CASH,
            default => null,
        };
    }

    protected function mapActivitySlug(string $legacyActivity): string
    {
        $mapping = [
            'status_updated' => 'transactions.update',
            'item_added' => 'transactions.update',
            'item_deleted' => 'transactions.update',
            'internal_note_created' => 'transactions.update',
            'internal_note_edited' => 'transactions.update',
            'external_note_created' => 'transactions.update',
            'details_updated' => 'transactions.update',
            'created_transaction' => 'transactions.create',
            'created_shipping_label' => 'transactions.update',
            'created_return_label' => 'transactions.update',
            'added_tracking_number' => 'transactions.update',
            'payment_added_to_transaction' => 'transactions.update',
            'item_added_to_transaction' => 'transactions.update',
            'transaction_status_changed' => 'transactions.update',
            'transaction_item_reviewed' => 'transactions.update',
        ];

        return $mapping[$legacyActivity] ?? 'transactions.update';
    }

    protected function mapCauserType(string $legacyType): string
    {
        return match ($legacyType) {
            'App\\Models\\User' => 'App\\Models\\User',
            'App\\Models\\Customer' => 'App\\Models\\Customer',
            default => 'App\\Models\\User',
        };
    }

    /**
     * Find a category by the legacy product_type_id.
     *
     * Queries the legacy store_categories table at runtime to get accurate mapping.
     * Validates that the category has a valid parent chain (leads to parent_id = 0).
     */
    /**
     * Find or create a category (and its template) from a legacy product_type_id.
     * Looks up the legacy category by ID, then finds or creates in the new system by name.
     *
     * @return array{category_id: int|null, template_id: int|null}
     */
    protected function findOrCreateCategoryWithTemplate(?int $productTypeId, ?int $legacyHtmlFormId): array
    {
        if (! $productTypeId) {
            return ['category_id' => null, 'template_id' => null];
        }

        // Check cache first
        if (isset($this->categoryMap[$productTypeId])) {
            $categoryId = $this->categoryMap[$productTypeId];
            $templateId = $this->categoryTemplateMap[$categoryId] ?? null;

            if ($templateId && $legacyHtmlFormId) {
                $this->ensureTemplateFieldsMapped($legacyHtmlFormId, $templateId);
            }

            return ['category_id' => $categoryId, 'template_id' => $templateId];
        }

        // Look up legacy category by ID (could be from any store)
        $legacyCategory = DB::connection('legacy')
            ->table('store_categories')
            ->where('id', $productTypeId)
            ->whereNull('deleted_at')
            ->first();

        if (! $legacyCategory) {
            static $missingLegacyCategories = [];
            if (! isset($missingLegacyCategories[$productTypeId])) {
                $this->warn("  Legacy category ID {$productTypeId} not found in store_categories.");
                $missingLegacyCategories[$productTypeId] = true;
            }

            return ['category_id' => null, 'template_id' => null];
        }

        // Find or create the template first if the legacy category has one
        $templateId = null;
        $legacyFormId = $legacyCategory->html_form_id ?? $legacyHtmlFormId;

        if ($legacyFormId) {
            $templateId = $this->findOrCreateTemplate($legacyFormId);
        }

        // Find or create category in the new system by name
        $category = Category::withoutGlobalScopes()->firstOrCreate(
            [
                'store_id' => $this->newStoreId,
                'name' => $legacyCategory->name,
                'type' => 'transaction_item_category',
            ],
            [
                'slug' => Str::limit(Str::slug($legacyCategory->name), 74, '').'-'.Str::random(4),
                'template_id' => $templateId,
                'sort_order' => $legacyCategory->sort_order ?? 0,
                'level' => $legacyCategory->level ?? 0,
            ]
        );

        $this->categoryMap[$productTypeId] = $category->id;
        $this->categoryTemplateMap[$category->id] = $category->template_id;

        if ($category->template_id && $legacyFormId) {
            $this->ensureTemplateFieldsMapped($legacyFormId, $category->template_id);
        }

        return ['category_id' => $category->id, 'template_id' => $category->template_id];
    }

    /**
     * Find or create a ProductTemplate from a legacy html_form_id.
     * Also creates fields and field options if the template is new.
     */
    protected function findOrCreateTemplate(int $legacyFormId): ?int
    {
        static $templateCache = [];
        if (isset($templateCache[$legacyFormId])) {
            return $templateCache[$legacyFormId];
        }

        $legacyForm = DB::connection('legacy')
            ->table('html_forms')
            ->where('id', $legacyFormId)
            ->first();

        if (! $legacyForm) {
            $templateCache[$legacyFormId] = null;

            return null;
        }

        $template = ProductTemplate::firstOrCreate(
            [
                'store_id' => $this->newStoreId,
                'name' => $legacyForm->title,
            ],
            [
                'description' => null,
                'is_active' => true,
                'ai_generated' => false,
            ]
        );

        $templateCache[$legacyFormId] = $template->id;

        // If just created, migrate its fields and options
        if ($template->wasRecentlyCreated) {
            $this->createTemplateFields($legacyFormId, $template);
        }

        return $template->id;
    }

    /**
     * Create fields and options for a newly created ProductTemplate from a legacy html_form.
     */
    protected function createTemplateFields(int $legacyFormId, ProductTemplate $template): void
    {
        $legacyFields = DB::connection('legacy')
            ->table('html_form_fields')
            ->where('html_form_id', $legacyFormId)
            ->orderBy('sort_order')
            ->get();

        $usedFieldNames = [];

        foreach ($legacyFields as $legacyField) {
            $fieldType = $this->mapFieldType($legacyField->component ?? $legacyField->type ?? 'text');

            $baseName = Str::snake($legacyField->name);
            $fieldName = $baseName;
            $counter = 1;
            while (in_array($fieldName, $usedFieldNames)) {
                $counter++;
                $fieldName = "{$baseName}_{$counter}";
            }
            $usedFieldNames[] = $fieldName;

            $field = ProductTemplateField::create([
                'product_template_id' => $template->id,
                'name' => $fieldName,
                'canonical_name' => $baseName,
                'label' => $legacyField->label.($counter > 1 ? " ({$counter})" : ''),
                'type' => $fieldType,
                'is_required' => false,
                'is_searchable' => (bool) ($legacyField->is_searchable ?? false),
                'is_filterable' => false,
                'show_in_listing' => true,
                'sort_order' => $legacyField->sort_order ?? 0,
                'width_class' => 'w-full',
                'ai_generated' => false,
            ]);

            // Migrate field options for select/radio/checkbox
            $legacyOptions = DB::connection('legacy')
                ->table('html_form_field_values')
                ->where('html_form_field_id', $legacyField->id)
                ->orderBy('sort_order')
                ->get();

            foreach ($legacyOptions as $index => $legacyOption) {
                ProductTemplateFieldOption::create([
                    'product_template_field_id' => $field->id,
                    'label' => $legacyOption->value,
                    'value' => Str::slug($legacyOption->value, '_'),
                    'sort_order' => $legacyOption->sort_order ?? $index,
                ]);
            }
        }
    }

    /**
     * Migrate transaction item attribute values from the legacy metas table.
     *
     * Legacy structure:
     * - metas.metaable_type = 'App\Models\TransactionItem'
     * - metas.metaable_id = transaction item ID
     * - metas.field = field name (matches html_form_fields.name)
     * - metas.value = the actual value
     *
     * New structure:
     * - Stored in transaction_items.attributes JSON as 'template_values' key
     * - Format: { field_id: value } for each migrated field
     */
    protected function migrateTransactionItemMetas(object $legacyItem, TransactionItem $newItem, ?int $templateId): void
    {
        if (! $templateId) {
            return;
        }

        // Get metas for this transaction item (legacy uses metaable_type/metaable_id with double 'a')
        $legacyMetas = DB::connection('legacy')
            ->table('metas')
            ->where('metaable_type', 'App\\Models\\TransactionItem')
            ->where('metaable_id', $legacyItem->id)
            ->whereNull('deleted_at')
            ->get();

        if ($legacyMetas->isEmpty()) {
            return;
        }

        // Get the field mapping for this template
        $fieldMap = $this->templateFieldNameMap[$templateId] ?? [];
        $fieldTypeMap = $this->templateFieldTypeMap[$templateId] ?? [];
        $fieldOptionsMap = $this->templateFieldOptionsMap[$templateId] ?? [];

        if (empty($fieldMap)) {
            return;
        }

        $templateValues = [];

        foreach ($legacyMetas as $meta) {
            // Skip empty values
            if ($meta->value === null || $meta->value === '') {
                continue;
            }

            $fieldName = strtolower($meta->field);
            $matchedFieldName = null;
            $newFieldId = null;

            // Try different name formats
            if (isset($fieldMap[$fieldName])) {
                $matchedFieldName = $fieldName;
                $newFieldId = $fieldMap[$fieldName];
            } elseif (isset($fieldMap[str_replace('-', '_', $fieldName)])) {
                $matchedFieldName = str_replace('-', '_', $fieldName);
                $newFieldId = $fieldMap[$matchedFieldName];
            } elseif (isset($fieldMap[Str::snake($fieldName)])) {
                $matchedFieldName = Str::snake($fieldName);
                $newFieldId = $fieldMap[$matchedFieldName];
            }

            if (! $newFieldId || ! $matchedFieldName) {
                continue;
            }

            // Get field type and options for value transformation
            $fieldType = $fieldTypeMap[$matchedFieldName] ?? 'text';
            $fieldOptions = $fieldOptionsMap[$matchedFieldName] ?? [];

            // Transform value for select fields
            $value = $this->transformMetaValue($meta->value, $fieldType, $fieldOptions);

            // Store using field_id as key for consistency with product attribute values
            $templateValues[(string) $newFieldId] = $value;
            $this->attributeValueCount++;
        }

        if (! empty($templateValues)) {
            // Update the attributes JSON to include template_values
            $attributes = $newItem->attributes ?? [];
            $attributes['template_values'] = $templateValues;
            $newItem->update(['attributes' => $attributes]);
        }
    }

    /**
     * Ensure template fields are mapped for an existing template.
     * This populates templateFieldNameMap, templateFieldTypeMap, and templateFieldOptionsMap.
     */
    protected function ensureTemplateFieldsMapped(int $legacyTemplateId, int $templateId): void
    {
        // If already mapped, skip
        if (isset($this->templateFieldNameMap[$templateId]) && ! empty($this->templateFieldNameMap[$templateId])) {
            return;
        }

        // Fetch legacy fields
        if (! isset($this->legacyTemplateFieldsCache[$legacyTemplateId])) {
            $legacyFields = DB::connection('legacy')
                ->table('html_form_fields')
                ->where('html_form_id', $legacyTemplateId)
                ->orderBy('sort_order')
                ->get();

            $this->legacyTemplateFieldsCache[$legacyTemplateId] = $legacyFields;
        }

        $legacyFields = $this->legacyTemplateFieldsCache[$legacyTemplateId];

        // Get existing new fields
        $existingFields = ProductTemplateField::where('product_template_id', $templateId)->get();
        $existingFieldsByName = $existingFields->keyBy(fn ($f) => strtolower($f->canonical_name ?? $f->name));
        $existingFieldsBySnakeName = $existingFields->keyBy(fn ($f) => strtolower($f->name));

        $this->templateFieldNameMap[$templateId] = [];
        $this->templateFieldTypeMap[$templateId] = [];
        $this->templateFieldOptionsMap[$templateId] = [];

        foreach ($legacyFields as $legacyField) {
            $canonicalName = strtolower($legacyField->name);

            // Try to find matching existing field
            $existingField = $existingFieldsByName[$canonicalName]
                ?? $existingFieldsBySnakeName[$canonicalName]
                ?? $existingFieldsBySnakeName[Str::snake($canonicalName)]
                ?? null;

            if (! $existingField) {
                continue;
            }

            $fieldType = $this->mapFieldType($legacyField->type ?? 'text');

            // Store mappings
            $this->templateFieldNameMap[$templateId][$canonicalName] = $existingField->id;
            $this->templateFieldTypeMap[$templateId][$canonicalName] = $fieldType;

            // Get field options for select/radio/checkbox fields
            if (in_array($fieldType, ['select', 'radio', 'checkbox'])) {
                $legacyOptions = DB::connection('legacy')
                    ->table('html_form_field_options')
                    ->where('html_form_field_id', $legacyField->id)
                    ->orderBy('sort_order')
                    ->get();

                $options = [];
                foreach ($legacyOptions as $legacyOption) {
                    $options[] = [
                        'value' => $legacyOption->value ?? Str::slug($legacyOption->label ?? ''),
                        'label' => $legacyOption->label ?? $legacyOption->value ?? '',
                    ];
                }

                $this->templateFieldOptionsMap[$templateId][$canonicalName] = $options;
            }
        }
    }

    /**
     * Transform a legacy meta value to match the new field format.
     * Handles select fields by matching against available options.
     *
     * @param  array<int, array{value: string, label: string}>  $fieldOptions
     */
    protected function transformMetaValue(string $value, string $fieldType, array $fieldOptions): string
    {
        // Only transform select field values
        if ($fieldType !== 'select' || empty($fieldOptions)) {
            return $value;
        }

        // Build a map of option values (existing values in the select)
        $optionValues = collect($fieldOptions)->pluck('value')->filter()->toArray();

        // If value already matches an option exactly, use it
        if (in_array($value, $optionValues, true)) {
            return $value;
        }

        // Try lowercase match
        $lowerValue = strtolower($value);
        foreach ($optionValues as $optionValue) {
            if (strtolower($optionValue) === $lowerValue) {
                return $optionValue;
            }
        }

        // Try slugified match (handles "Natural Diamond" -> "natural-diamond")
        $slugValue = Str::slug($value);
        foreach ($optionValues as $optionValue) {
            if ($optionValue === $slugValue) {
                return $optionValue;
            }
        }

        // Try matching by label
        foreach ($fieldOptions as $option) {
            $label = $option['label'] ?? '';
            if (strtolower($label) === $lowerValue || Str::slug($label) === $slugValue) {
                return $option['value'] ?? $value;
            }
        }

        // Return original value if no match found
        return $value;
    }

    /**
     * Map legacy field type to new field type.
     */
    protected function mapFieldType(?string $legacyType): string
    {
        if (! $legacyType) {
            return 'text';
        }

        return match (strtolower($legacyType)) {
            'text', 'string' => 'text',
            'textarea', 'text_area' => 'textarea',
            'number', 'integer', 'float', 'decimal' => 'number',
            'select', 'dropdown' => 'select',
            'checkbox' => 'checkbox',
            'radio' => 'radio',
            'date', 'datetime' => 'date',
            default => 'text',
        };
    }
}
