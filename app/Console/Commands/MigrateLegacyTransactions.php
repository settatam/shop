<?php

namespace App\Console\Commands;

use App\Models\Address;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Image;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
                            {--sync-deletes : Soft-delete new records if legacy record is soft-deleted}';

    protected $description = 'Migrate legacy transactions from shopmata-new database for a specific store';

    /**
     * Map legacy status IDs to new status strings.
     *
     * @var array<int, string>
     */
    protected array $statusIdMap = [
        1 => Transaction::STATUS_KIT_SENT,
        2 => Transaction::STATUS_ITEMS_RECEIVED,
        3 => Transaction::STATUS_KIT_REQUEST_REJECTED,
        4 => Transaction::STATUS_OFFER_GIVEN,
        5 => Transaction::STATUS_OFFER_ACCEPTED,
        6 => Transaction::STATUS_OFFER_DECLINED,
        8 => Transaction::STATUS_PAYMENT_PROCESSED,
        11 => Transaction::STATUS_ITEMS_RETURNED,
        12 => Transaction::STATUS_OFFER_DECLINED,
        13 => Transaction::STATUS_PAYMENT_PROCESSED,
        18 => Transaction::STATUS_ITEMS_RETURNED,
        19 => Transaction::STATUS_OFFER_DECLINED,
        20 => Transaction::STATUS_KIT_REQUEST_REJECTED,
        21 => Transaction::STATUS_ITEMS_RETURNED,
        25 => Transaction::STATUS_KIT_REQUEST_ON_HOLD,
        50 => Transaction::STATUS_ITEMS_REVIEWED,
        53 => Transaction::STATUS_KIT_REQUEST_ON_HOLD,
        54 => Transaction::STATUS_KIT_REQUEST_CONFIRMED,
        55 => Transaction::STATUS_ITEMS_REVIEWED,
        57 => Transaction::STATUS_PENDING_KIT_REQUEST,
        58 => Transaction::STATUS_KIT_REQUEST_REJECTED,
        60 => Transaction::STATUS_PENDING_KIT_REQUEST,
        61 => Transaction::STATUS_PENDING_KIT_REQUEST,
        62 => Transaction::STATUS_PENDING_KIT_REQUEST,
        64 => Transaction::STATUS_PENDING_KIT_REQUEST,
        65 => Transaction::STATUS_OFFER_GIVEN,
        66 => Transaction::STATUS_OFFER_ACCEPTED,
        67 => Transaction::STATUS_PAYMENT_PROCESSED,
        68 => Transaction::STATUS_ITEMS_RETURNED,
        69 => Transaction::STATUS_PAYMENT_PROCESSED,
        70 => Transaction::STATUS_CANCELLED,
        71 => Transaction::STATUS_ITEMS_REVIEWED,
        72 => Transaction::STATUS_PAYMENT_PROCESSED,
    ];

    /**
     * Map legacy status names (case-insensitive) to new status strings.
     * Used as a fallback when status_id is not in statusIdMap.
     *
     * @var array<string, string>
     */
    protected array $statusNameMap = [
        'pending' => Transaction::STATUS_PENDING,
        'pending kit request' => Transaction::STATUS_PENDING_KIT_REQUEST,
        'pending kit requests' => Transaction::STATUS_PENDING_KIT_REQUEST,
        'kit request confirmed' => Transaction::STATUS_KIT_REQUEST_CONFIRMED,
        'kit request rejected' => Transaction::STATUS_KIT_REQUEST_REJECTED,
        'kit request on hold' => Transaction::STATUS_KIT_REQUEST_ON_HOLD,
        'kit sent' => Transaction::STATUS_KIT_SENT,
        'kit delivered' => Transaction::STATUS_KIT_DELIVERED,
        'kits received' => Transaction::STATUS_ITEMS_RECEIVED,
        'kit received' => Transaction::STATUS_ITEMS_RECEIVED,
        'items received' => Transaction::STATUS_ITEMS_RECEIVED,
        'items reviewed' => Transaction::STATUS_ITEMS_REVIEWED,
        'reviewed' => Transaction::STATUS_ITEMS_REVIEWED,
        'offer given' => Transaction::STATUS_OFFER_GIVEN,
        'pending offer' => Transaction::STATUS_OFFER_GIVEN,
        'offer accepted' => Transaction::STATUS_OFFER_ACCEPTED,
        'offer declined' => Transaction::STATUS_OFFER_DECLINED,
        'offers declined' => Transaction::STATUS_OFFER_DECLINED,
        'payment pending' => Transaction::STATUS_PAYMENT_PENDING,
        'payment processed' => Transaction::STATUS_PAYMENT_PROCESSED,
        'paid' => Transaction::STATUS_PAYMENT_PROCESSED,
        'moved to nwe' => Transaction::STATUS_PAYMENT_PROCESSED,
        'sold' => Transaction::STATUS_PAYMENT_PROCESSED,
        'refund payment processed' => Transaction::STATUS_PAYMENT_PROCESSED,
        'return requested' => Transaction::STATUS_RETURN_REQUESTED,
        'items returned' => Transaction::STATUS_ITEMS_RETURNED,
        'returned by admin' => Transaction::STATUS_ITEMS_RETURNED,
        'return received' => Transaction::STATUS_ITEMS_RETURNED,
        'cancelled' => Transaction::STATUS_CANCELLED,
        'archive' => Transaction::STATUS_CANCELLED,
        'on hold' => Transaction::STATUS_KIT_REQUEST_ON_HOLD,
        '14 day - on hold' => Transaction::STATUS_KIT_REQUEST_ON_HOLD,
    ];

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

    protected bool $dryRun = false;

    protected int $legacyStoreId;

    protected int $newStoreId;

    public function handle(): int
    {
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

        // Load category mappings if available
        if (! $this->option('skip-category-mapping')) {
            // Allow using category mappings from a different legacy store (e.g., store 44 uses store 43's categories)
            $categoryMappingStore = (int) ($this->option('category-mapping-store') ?? $this->legacyStoreId);
            $mappings = MigrateLegacyCategories::loadMappings($categoryMappingStore, $this->newStoreId);
            if ($mappings) {
                $this->categoryMap = $mappings['categories'];
                $this->info('Loaded '.count($this->categoryMap)." category mappings from store {$categoryMappingStore}");
            } else {
                $this->warn("No category mappings found for store {$categoryMappingStore}. Run migrate:legacy-categories first for proper category linking.");
                $this->warn('Continuing with category_id set to null for all items.');
            }
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

        $legacyTransactions = $query->get();

        $this->info("Found {$legacyTransactions->count()} transactions to migrate");

        if ($legacyTransactions->isEmpty()) {
            $this->warn('No transactions found for this store');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($legacyTransactions->count());
        $bar->start();

        $migrated = 0;
        $skipped = 0;
        $synced = 0;
        $errors = 0;

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

        $bar->finish();
        $this->newLine(2);

        $this->info('Migration complete!');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Migrated', $migrated],
                ['Skipped', $skipped],
                ['Soft-deleted (synced)', $synced],
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
        // Check if already migrated by ID (preserving original IDs)
        $existing = Transaction::withTrashed()->find($legacyTransaction->id);

        if ($existing) {
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

        // Get legacy status and map to new system
        $legacyStatus = DB::connection('legacy')
            ->table('statuses')
            ->where('store_id', $this->legacyStoreId)
            ->where('status_id', $legacyTransaction->status_id)
            ->first();

        // Map status: first try status_id, then status name, then default to pending
        $statusName = $this->mapLegacyStatus(
            $legacyTransaction->status_id,
            $legacyStatus?->name
        );

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

        // Determine if this is a payment_processed status (for setting timestamps)
        // Since we now map to constants, just compare directly
        $isPaymentProcessed = $statusName === Transaction::STATUS_PAYMENT_PROCESSED;

        // Create the transaction using DB insert to preserve timestamps exactly
        $transactionData = [
            'store_id' => $this->newStoreId,
            'customer_id' => $customerId,
            'user_id' => null, // We don't migrate users
            'transaction_number' => (string) $legacyTransaction->id, // Use legacy ID as transaction number
            'status' => $statusName, // Use exact legacy status name
            'type' => $type,
            'source' => null, // All legacy transactions are in-store
            'preliminary_offer' => $legacyTransaction->preliminary_offer ?? 0,
            'final_offer' => $legacyTransaction->final_offer ?? 0,
            'estimated_value' => $legacyTransaction->est_value ?? 0,
            'payment_method' => $paymentMethods,
            'payment_details' => json_encode([
                'legacy_id' => $legacyTransaction->id,
                'legacy_status_id' => $legacyTransaction->status_id,
                'legacy_status_name' => $legacyStatus?->name,
                'legacy_payment_addresses' => $legacyPaymentAddresses->map(fn ($pa) => (array) $pa)->toArray(),
            ]),
            'status_id' => null, // Don't use legacy status_id - it references a different statuses table
            'bin_location' => $legacyTransaction->bin_location,
            'customer_notes' => $legacyTransaction->pub_note ?? null,
            'internal_notes' => $legacyTransaction->private_note ?? null,
            'customer_description' => $legacyTransaction->customer_description ?? null,
            'created_at' => $legacyTransaction->created_at,
            'updated_at' => $legacyTransaction->updated_at,
            // Set payment_processed_at for transactions that have reached that status
            'payment_processed_at' => $isPaymentProcessed ? ($legacyTransaction->updated_at ?? $legacyTransaction->created_at) : null,
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

        // Add ID to preserve original
        $transactionData['id'] = $legacyTransaction->id;

        // Use DB::table to preserve exact timestamps and original ID
        DB::table('transactions')->insert($transactionData);
        $transaction = Transaction::find($legacyTransaction->id);

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
        // Check cache first
        if (isset($this->customerMap[$legacyCustomerId])) {
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

        if ($existingCustomer) {
            $this->customerMap[$legacyCustomerId] = $existingCustomer->id;

            return $existingCustomer->id;
        }

        // Check if customer with this ID already exists (from another store migration)
        $existingById = Customer::withTrashed()->find($legacyCustomerId);
        if ($existingById) {
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
            'address' => $legacyCustomer->address ?? null,
            'address2' => $legacyCustomer->address2 ?? null,
            'city' => $legacyCustomer->city ?? null,
            'state' => $legacyState?->code ?? $legacyCustomer->state ?? null,
            'zip' => $legacyCustomer->zip ?? null,
            'ethnicity' => $legacyCustomer->ethnicity,
            'accepts_marketing' => (bool) ($legacyCustomer->accepts_marketing ?? false),
            'is_active' => (bool) ($legacyCustomer->is_active ?? true),
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
            if ($existingItem) {
                continue;
            }

            // Find or create category by looking up product_type_id in store_categories
            $categoryId = $this->findOrCreateCategoryByLegacyProductType(
                $legacyItem->product_type_id ?? $legacyItem->category_id ?? null
            );

            // Use DB insert to preserve exact timestamps and original ID
            DB::table('transaction_items')->insert([
                'id' => $legacyItem->id, // Preserve original ID
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
                'attributes' => json_encode([
                    'legacy_category_id' => $legacyItem->category_id,
                    'legacy_product_type_id' => $legacyItem->product_type_id ?? null,
                    'legacy_html_form_id' => $legacyItem->html_form_id ?? null,
                ]),
                'created_at' => $legacyItem->created_at,
                'updated_at' => $legacyItem->updated_at,
            ]);

            $item = TransactionItem::find($legacyItem->id);

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

    /**
     * Map legacy status to new system status constant.
     *
     * Priority:
     * 1. Check statusIdMap for exact status_id match
     * 2. Check statusNameMap for status name match (case-insensitive, with fuzzy matching)
     * 3. Default to pending
     */
    protected function mapLegacyStatus(?int $statusId, ?string $statusName): string
    {
        // First try mapping by status_id
        if ($statusId !== null && isset($this->statusIdMap[$statusId])) {
            return $this->statusIdMap[$statusId];
        }

        // Then try mapping by status name
        if ($statusName !== null) {
            $normalizedName = strtolower(trim($statusName));

            // Direct match
            if (isset($this->statusNameMap[$normalizedName])) {
                return $this->statusNameMap[$normalizedName];
            }

            // Fuzzy match - check if any key is contained in the status name
            foreach ($this->statusNameMap as $key => $value) {
                if (str_contains($normalizedName, $key)) {
                    return $value;
                }
            }

            // Check for common patterns
            if (str_contains($normalizedName, 'payment processed') || str_contains($normalizedName, 'paid')) {
                return Transaction::STATUS_PAYMENT_PROCESSED;
            }
            if (str_contains($normalizedName, 'offer accepted')) {
                return Transaction::STATUS_OFFER_ACCEPTED;
            }
            if (str_contains($normalizedName, 'offer declined') || str_contains($normalizedName, 'declined')) {
                return Transaction::STATUS_OFFER_DECLINED;
            }
            if (str_contains($normalizedName, 'offer given') || str_contains($normalizedName, 'pending offer')) {
                return Transaction::STATUS_OFFER_GIVEN;
            }
            if (str_contains($normalizedName, 'kit sent')) {
                return Transaction::STATUS_KIT_SENT;
            }
            if (str_contains($normalizedName, 'received') || str_contains($normalizedName, 'kits received')) {
                return Transaction::STATUS_ITEMS_RECEIVED;
            }
            if (str_contains($normalizedName, 'reviewed') || str_contains($normalizedName, 'ready to buy')) {
                return Transaction::STATUS_ITEMS_REVIEWED;
            }
            if (str_contains($normalizedName, 'returned') || str_contains($normalizedName, 'return')) {
                return Transaction::STATUS_ITEMS_RETURNED;
            }
            if (str_contains($normalizedName, 'pending kit') || str_contains($normalizedName, 'kit request')) {
                return Transaction::STATUS_PENDING_KIT_REQUEST;
            }
            if (str_contains($normalizedName, 'on hold') || str_contains($normalizedName, 'hold')) {
                return Transaction::STATUS_KIT_REQUEST_ON_HOLD;
            }
            if (str_contains($normalizedName, 'rejected')) {
                return Transaction::STATUS_KIT_REQUEST_REJECTED;
            }
            if (str_contains($normalizedName, 'confirmed')) {
                return Transaction::STATUS_KIT_REQUEST_CONFIRMED;
            }
            if (str_contains($normalizedName, 'archive') || str_contains($normalizedName, 'cancelled')) {
                return Transaction::STATUS_CANCELLED;
            }
        }

        // Default to pending
        return Transaction::STATUS_PENDING;
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
     * Uses the category mapping loaded from migrate:legacy-categories.
     * The mapping handles cases where IDs couldn't be preserved due to collisions.
     *
     * This avoids issues with duplicate category names like "Other" appearing in multiple parent categories.
     */
    protected function findOrCreateCategoryByLegacyProductType(?int $productTypeId): ?int
    {
        if (! $productTypeId) {
            return null;
        }

        // Check if we already have this in our mapping (loaded from file or cached)
        if (isset($this->categoryMap[$productTypeId])) {
            return $this->categoryMap[$productTypeId];
        }

        // Verify this category exists in the legacy system (regardless of which store owns it)
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

            return null;
        }

        // Try to find category by legacy ID (works when ID was preserved)
        $category = Category::withoutGlobalScopes()->find($productTypeId);

        if ($category) {
            $this->categoryMap[$productTypeId] = $category->id;

            return $category->id;
        }

        // Category not found in new system - it should have been pre-migrated
        static $missingCategories = [];
        if (! isset($missingCategories[$productTypeId])) {
            $this->warn("  Category ID {$productTypeId} ('{$legacyCategory->name}') not migrated. Run migrate:legacy-categories for store {$legacyCategory->store_id} first.");
            $missingCategories[$productTypeId] = true;
        }

        return null;
    }
}
