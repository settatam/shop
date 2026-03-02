<?php

namespace App\Console\Commands;

use App\Models\Address;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Image;
use App\Models\Lead;
use App\Models\LeadItem;
use App\Models\Status;
use App\Services\Leads\LeadConversionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MigrateLegacyLeads extends Command
{
    protected $signature = 'migrate:legacy-leads
                            {store_id : The legacy store ID to migrate}
                            {--new-store-id= : The new store ID to migrate to (defaults to same as legacy)}
                            {--category-mapping-store= : Use category mappings from a different legacy store}
                            {--limit= : Limit the number of leads to migrate}
                            {--latest : Get the most recent leads instead of oldest (use with --limit)}
                            {--dry-run : Run without making any changes}
                            {--fresh : Delete all existing leads and related data for this store before migrating}
                            {--skip-customers : Skip migrating customers}
                            {--skip-images : Skip migrating images}
                            {--skip-activities : Skip migrating activities}
                            {--skip-category-mapping : Skip using category mappings (set category_id to null)}
                            {--skip-conversion : Skip converting payment_processed leads to transactions}
                            {--sync-deletes : Soft-delete new records if legacy record is soft-deleted}
                            {--lead-id= : Re-import a single legacy transaction by ID (updates existing)}';

    protected $description = 'Migrate legacy transactions as leads from shopmata-new database for a specific store';

    /**
     * Map legacy status IDs to new lead status strings.
     *
     * @var array<int, string>
     */
    protected array $statusIdMap = [
        1 => Lead::STATUS_KIT_SENT,
        2 => Lead::STATUS_ITEMS_RECEIVED,
        3 => Lead::STATUS_KIT_REQUEST_REJECTED,
        4 => Lead::STATUS_OFFER_GIVEN,
        5 => Lead::STATUS_OFFER_ACCEPTED,
        6 => Lead::STATUS_CUSTOMER_DECLINED_OFFER,
        8 => Lead::STATUS_PAYMENT_PROCESSED,
        11 => Lead::STATUS_ITEMS_RETURNED,
        12 => Lead::STATUS_CUSTOMER_DECLINED_OFFER,
        13 => Lead::STATUS_PAYMENT_PROCESSED,
        18 => Lead::STATUS_ITEMS_RETURNED,
        19 => Lead::STATUS_CUSTOMER_DECLINED_OFFER,
        20 => Lead::STATUS_KIT_REQUEST_REJECTED,
        21 => Lead::STATUS_ITEMS_RETURNED,
        25 => Lead::STATUS_KIT_REQUEST_ON_HOLD,
        50 => Lead::STATUS_ITEMS_REVIEWED,
        53 => Lead::STATUS_KIT_REQUEST_ON_HOLD,
        54 => Lead::STATUS_KIT_REQUEST_CONFIRMED,
        55 => Lead::STATUS_ITEMS_REVIEWED,
        57 => Lead::STATUS_PENDING_KIT_REQUEST,
        58 => Lead::STATUS_KIT_REQUEST_REJECTED,
        60 => Lead::STATUS_PENDING_KIT_REQUEST,
        61 => Lead::STATUS_PENDING_KIT_REQUEST,
        62 => Lead::STATUS_PENDING_KIT_REQUEST,
        64 => Lead::STATUS_PENDING_KIT_REQUEST,
        65 => Lead::STATUS_OFFER_GIVEN,
        66 => Lead::STATUS_OFFER_ACCEPTED,
        67 => Lead::STATUS_PAYMENT_PROCESSED,
        68 => Lead::STATUS_ITEMS_RETURNED,
        69 => Lead::STATUS_PAYMENT_PROCESSED,
        70 => Lead::STATUS_CANCELLED,
        71 => Lead::STATUS_ITEMS_REVIEWED,
        72 => Lead::STATUS_PAYMENT_PROCESSED,
    ];

    /**
     * Map legacy status names (case-insensitive) to new lead status strings.
     *
     * @var array<string, string>
     */
    protected array $statusNameMap = [
        'pending' => Lead::STATUS_PENDING_KIT_REQUEST,
        'pending kit request' => Lead::STATUS_PENDING_KIT_REQUEST,
        'pending kit requests' => Lead::STATUS_PENDING_KIT_REQUEST,
        'kit request confirmed' => Lead::STATUS_KIT_REQUEST_CONFIRMED,
        'kit request rejected' => Lead::STATUS_KIT_REQUEST_REJECTED,
        'kit request on hold' => Lead::STATUS_KIT_REQUEST_ON_HOLD,
        'kit sent' => Lead::STATUS_KIT_SENT,
        'kit delivered' => Lead::STATUS_KIT_DELIVERED,
        'kits received' => Lead::STATUS_ITEMS_RECEIVED,
        'kit received' => Lead::STATUS_ITEMS_RECEIVED,
        'items received' => Lead::STATUS_ITEMS_RECEIVED,
        'items reviewed' => Lead::STATUS_ITEMS_REVIEWED,
        'reviewed' => Lead::STATUS_ITEMS_REVIEWED,
        'offer given' => Lead::STATUS_OFFER_GIVEN,
        'pending offer' => Lead::STATUS_OFFER_GIVEN,
        'offer accepted' => Lead::STATUS_OFFER_ACCEPTED,
        'offer declined' => Lead::STATUS_CUSTOMER_DECLINED_OFFER,
        'offers declined' => Lead::STATUS_CUSTOMER_DECLINED_OFFER,
        'payment pending' => Lead::STATUS_OFFER_ACCEPTED,
        'payment processed' => Lead::STATUS_PAYMENT_PROCESSED,
        'paid' => Lead::STATUS_PAYMENT_PROCESSED,
        'moved to nwe' => Lead::STATUS_PAYMENT_PROCESSED,
        'sold' => Lead::STATUS_PAYMENT_PROCESSED,
        'refund payment processed' => Lead::STATUS_PAYMENT_PROCESSED,
        'return requested' => Lead::STATUS_ITEMS_RETURNED,
        'items returned' => Lead::STATUS_ITEMS_RETURNED,
        'returned by admin' => Lead::STATUS_ITEMS_RETURNED,
        'return received' => Lead::STATUS_ITEMS_RETURNED,
        'cancelled' => Lead::STATUS_CANCELLED,
        'archive' => Lead::STATUS_CANCELLED,
        'on hold' => Lead::STATUS_KIT_REQUEST_ON_HOLD,
        '14 day - on hold' => Lead::STATUS_KIT_REQUEST_ON_HOLD,
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

    /**
     * Map category_id to template_id.
     *
     * @var array<int, int|null>
     */
    protected array $categoryTemplateMap = [];

    /**
     * Maps legacy html_form_field.name => new product_template_field.id (by template).
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

    protected int $attributeValueCount = 0;

    protected bool $dryRun = false;

    protected bool $reimport = false;

    protected int $legacyStoreId;

    protected int $newStoreId;

    protected int $convertedCount = 0;

    public function handle(): int
    {
        $this->legacyStoreId = (int) $this->argument('store_id');
        $this->newStoreId = (int) ($this->option('new-store-id') ?? $this->legacyStoreId);
        $this->dryRun = (bool) $this->option('dry-run');

        if ($this->dryRun) {
            $this->warn('Running in DRY RUN mode - no changes will be made');
        }

        $this->info("Starting lead migration for store ID: {$this->legacyStoreId} -> {$this->newStoreId}");

        // Test legacy database connection
        try {
            DB::connection('legacy')->getPdo();
            $this->info('Connected to legacy database successfully.');
        } catch (\Exception $e) {
            $this->error('Could not connect to legacy database: '.$e->getMessage());
            $this->info('Please ensure you have configured the "legacy" database connection in config/database.php');

            return self::FAILURE;
        }

        // Handle single lead re-import
        if ($this->option('lead-id')) {
            $this->reimport = true;
            $leadId = (int) $this->option('lead-id');

            $legacyTransaction = DB::connection('legacy')
                ->table('transactions')
                ->where('id', $leadId)
                ->first();

            if (! $legacyTransaction) {
                $this->error("Legacy transaction #{$leadId} not found.");

                return self::FAILURE;
            }

            $this->info("Re-importing as lead #{$leadId}...");

            try {
                $result = $this->migrateLead($legacyTransaction);
                $this->info("Lead #{$leadId} re-imported successfully ({$result}).");
            } catch (\Exception $e) {
                $this->error("Failed to re-import lead #{$leadId}: {$e->getMessage()}");
                Log::error('Failed to re-import lead', [
                    'legacy_id' => $leadId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        // Handle fresh option
        if ($this->option('fresh')) {
            if (! $this->dryRun) {
                if (! $this->confirm("This will DELETE all leads, lead items, related images, addresses, and activity logs for store {$this->newStoreId}. Are you sure?")) {
                    $this->info('Operation cancelled.');

                    return self::SUCCESS;
                }
                $this->deleteExistingLeadData();
            } else {
                $this->warn('Fresh mode: Would delete all lead data for store '.$this->newStoreId);
            }
        }

        if (! $this->option('skip-category-mapping')) {
            $this->info('Category lookups will be performed at runtime from legacy store_categories table.');
        }

        $syncDeletes = (bool) $this->option('sync-deletes');

        if ($syncDeletes) {
            $this->info('Sync deletes enabled - will soft-delete leads if legacy is soft-deleted');
        }

        // Get transactions from legacy database (ALL transactions become leads)
        $order = $this->option('latest') ? 'desc' : 'asc';
        $query = DB::connection('legacy')
            ->table('transactions')
            ->where('store_id', $this->legacyStoreId)
            ->whereNotNull('customer_id')
            ->orderBy('created_at', $order);

        if (! $syncDeletes) {
            $query->whereNull('deleted_at');
        }

        if ($this->option('limit')) {
            $query->limit((int) $this->option('limit'));
        }

        $legacyTransactions = $query->get();

        $this->info("Found {$legacyTransactions->count()} legacy transactions to migrate as leads");

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
                $result = $this->migrateLead($legacyTransaction, $syncDeletes);
                if ($result === 'skipped') {
                    $skipped++;
                } elseif ($result === 'synced') {
                    $synced++;
                } else {
                    $migrated++;
                }
            } catch (\Exception $e) {
                $errors++;
                Log::error('Failed to migrate lead', [
                    'legacy_id' => $legacyTransaction->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $this->newLine();
                $this->error("Failed to migrate lead for legacy transaction #{$legacyTransaction->id}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Migration complete!');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Migrated as leads', $migrated],
                ['Converted to transactions', $this->convertedCount],
                ['Skipped', $skipped],
                ['Soft-deleted (synced)', $synced],
                ['Template attribute values', $this->attributeValueCount],
                ['Errors', $errors],
            ]
        );

        return self::SUCCESS;
    }

    protected function deleteExistingLeadData(): void
    {
        $this->info('Deleting existing lead data for store '.$this->newStoreId.'...');

        $leadIds = Lead::withTrashed()
            ->where('store_id', $this->newStoreId)
            ->pluck('id')
            ->toArray();

        if (empty($leadIds)) {
            $this->info('No existing leads found.');

            return;
        }

        $this->info('Found '.count($leadIds).' leads to delete.');

        $leadItemIds = LeadItem::whereIn('lead_id', $leadIds)
            ->pluck('id')
            ->toArray();

        $this->info('Found '.count($leadItemIds).' lead items to delete.');

        // Delete images for lead items
        $deletedItemImages = DB::table('images')
            ->where('imageable_type', LeadItem::class)
            ->whereIn('imageable_id', $leadItemIds)
            ->delete();
        $this->info("Deleted {$deletedItemImages} lead item images.");

        // Delete images for leads
        $deletedLeadImages = DB::table('images')
            ->where('imageable_type', Lead::class)
            ->whereIn('imageable_id', $leadIds)
            ->delete();
        $this->info("Deleted {$deletedLeadImages} lead images.");

        // Delete addresses for leads
        $deletedAddresses = DB::table('addresses')
            ->where('addressable_type', Lead::class)
            ->whereIn('addressable_id', $leadIds)
            ->delete();
        $this->info("Deleted {$deletedAddresses} lead addresses.");

        // Delete activity logs for leads
        $deletedActivities = DB::table('activity_logs')
            ->where('subject_type', Lead::class)
            ->whereIn('subject_id', $leadIds)
            ->delete();
        $this->info("Deleted {$deletedActivities} activity logs.");

        // Delete lead items
        $deletedItems = DB::table('lead_items')
            ->whereIn('lead_id', $leadIds)
            ->delete();
        $this->info("Deleted {$deletedItems} lead items.");

        // Delete leads
        $deletedLeads = DB::table('leads')
            ->where('store_id', $this->newStoreId)
            ->delete();
        $this->info("Deleted {$deletedLeads} leads.");

        $this->customerMap = [];

        $this->info('Existing lead data deleted successfully.');
        $this->newLine();
    }

    protected function migrateLead(object $legacyTransaction, bool $syncDeletes = false): string
    {
        // Check if already migrated by legacy_id
        $existing = Lead::withTrashed()
            ->where('legacy_id', $legacyTransaction->id)
            ->where('store_id', $this->newStoreId)
            ->first();

        if ($existing && ! $this->reimport) {
            if ($syncDeletes && $legacyTransaction->deleted_at && ! $existing->deleted_at) {
                if (! $this->dryRun) {
                    $existing->delete();
                }

                return 'synced';
            }

            return 'skipped';
        }

        // Migrate customer
        $customerId = null;
        if (! $this->option('skip-customers') && $legacyTransaction->customer_id) {
            $customerId = $this->migrateCustomer($legacyTransaction->customer_id);
        }

        // Get legacy status and map
        $legacyStatus = DB::connection('legacy')
            ->table('statuses')
            ->where('store_id', $this->legacyStoreId)
            ->where('status_id', $legacyTransaction->status_id)
            ->first();

        $statusName = $this->mapLegacyStatus(
            $legacyTransaction->status_id,
            $legacyStatus?->name
        );

        // Determine type
        $type = $legacyTransaction->is_in_house ? Lead::TYPE_IN_STORE : Lead::TYPE_MAIL_IN;

        // Get legacy tracking info
        $legacyOutboundTracking = DB::connection('legacy')
            ->table('shipping_labels')
            ->where('shippable_type', 'App\\Models\\Transaction')
            ->where('shippable_id', $legacyTransaction->id)
            ->where('to_customer', true)
            ->first();

        $legacyReturnTracking = DB::connection('legacy')
            ->table('shipping_labels')
            ->where('shippable_type', 'App\\Models\\Transaction')
            ->where('shippable_id', $legacyTransaction->id)
            ->where('is_return', true)
            ->first();

        // Get payment methods
        $legacyPaymentAddresses = DB::connection('legacy')
            ->table('transaction_payment_addresses')
            ->where('transaction_id', $legacyTransaction->id)
            ->get();

        $paymentMethods = $this->mapPaymentMethods($legacyPaymentAddresses);

        if ($this->dryRun) {
            return 'migrated';
        }

        // Get payment_processed_at from legacy activity log
        $paymentActivity = DB::connection('legacy')
            ->table('store_activities')
            ->where('activityable_type', 'App\\Models\\Transaction')
            ->where('activityable_id', $legacyTransaction->id)
            ->where('activity', 'payment_added_to_transaction')
            ->orderBy('created_at', 'asc')
            ->first();

        $paymentProcessedAt = $paymentActivity?->created_at;

        // Build lead data
        $leadData = [
            'store_id' => $this->newStoreId,
            'customer_id' => $customerId,
            'user_id' => null,
            'lead_number' => 'LEAD-TEMP', // Will be updated after creation
            'legacy_id' => $legacyTransaction->id,
            'status' => $statusName,
            'type' => $type,
            'source' => null,
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
            'status_id' => $this->getStatusIdForSlug($this->newStoreId, $statusName, 'lead'),
            'bin_location' => $legacyTransaction->bin_location,
            'customer_notes' => $legacyTransaction->pub_note ?? null,
            'internal_notes' => $legacyTransaction->private_note ?? null,
            'customer_description' => $legacyTransaction->customer_description ?? null,
            'payment_processed_at' => $paymentProcessedAt,
            'created_at' => $legacyTransaction->created_at,
            'updated_at' => $legacyTransaction->updated_at,
        ];

        // Add tracking if available
        if ($legacyOutboundTracking?->tracking_number) {
            $leadData['outbound_tracking_number'] = $legacyOutboundTracking->tracking_number;
            $leadData['outbound_carrier'] = 'fedex';
        }
        if ($legacyReturnTracking?->tracking_number) {
            $leadData['return_tracking_number'] = $legacyReturnTracking->tracking_number;
            $leadData['return_carrier'] = 'fedex';
        }

        if ($this->reimport && $existing) {
            DB::table('leads')
                ->where('id', $existing->id)
                ->update(array_merge($leadData, ['deleted_at' => null]));
            $lead = Lead::find($existing->id);
        } else {
            // Use DB::table to preserve exact timestamps
            $leadId = DB::table('leads')->insertGetId($leadData);
            $lead = Lead::find($leadId);

            // Update lead_number with the proper prefix
            $store = $lead->store;
            $prefix = $store?->lead_id_prefix ?? 'LEAD';
            $suffix = $store?->lead_id_suffix ?? '';
            $lead->lead_number = "{$prefix}-{$lead->id}{$suffix}";
            $lead->saveQuietly();
        }

        // Migrate items
        $this->migrateLeadItems($legacyTransaction->id, $lead);

        // Migrate lead images
        if (! $this->option('skip-images')) {
            $this->migrateLeadImages($legacyTransaction->id, $lead);
        }

        // Migrate activities
        if (! $this->option('skip-activities')) {
            $this->migrateActivities($legacyTransaction->id, $lead);
        }

        // Migrate shipping address
        $this->migrateShippingAddress($legacyTransaction, $lead);

        // If lead is payment_processed, convert to transaction (buy)
        if ($statusName === Lead::STATUS_PAYMENT_PROCESSED && ! $this->option('skip-conversion')) {
            try {
                $lead->refresh();
                $conversionService = app(LeadConversionService::class);
                $conversionService->convertToTransaction($lead);
                $this->convertedCount++;
            } catch (\Exception $e) {
                Log::warning("Failed to convert lead #{$lead->id} to transaction: {$e->getMessage()}");
                $this->newLine();
                $this->warn("  Warning: Failed to convert lead #{$lead->id} to transaction: {$e->getMessage()}");
            }
        }

        return 'migrated';
    }

    protected function migrateCustomer(int $legacyCustomerId): ?int
    {
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

        // Check if customer already exists by email or phone
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

        $legacyState = null;
        if ($legacyCustomer->state_id) {
            $legacyState = DB::connection('legacy')
                ->table('states')
                ->where('id', $legacyCustomer->state_id)
                ->first();
        }

        DB::table('customers')->insert([
            'id' => $legacyCustomerId,
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
            $legacyState = null;
            if ($legacyAddress->state_id) {
                $legacyState = DB::connection('legacy')
                    ->table('states')
                    ->where('id', $legacyAddress->state_id)
                    ->first();
            }

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
                'country_id' => 1,
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

    protected function migrateShippingAddress(object $legacyTransaction, Lead $lead): void
    {
        $legacyAddress = DB::connection('legacy')
            ->table('addresses')
            ->where('addressable_type', 'App\\Models\\Transaction')
            ->where('addressable_id', $legacyTransaction->id)
            ->first();

        if (! $legacyAddress || $this->dryRun) {
            return;
        }

        try {
            $legacyState = null;
            if ($legacyAddress->state_id) {
                $legacyState = DB::connection('legacy')
                    ->table('states')
                    ->where('id', $legacyAddress->state_id)
                    ->first();
            }

            $addressId = DB::table('addresses')->insertGetId([
                'store_id' => $this->newStoreId,
                'addressable_type' => Lead::class,
                'addressable_id' => $lead->id,
                'type' => 'shipping',
                'first_name' => $legacyAddress->first_name ?? null,
                'last_name' => $legacyAddress->last_name ?? null,
                'address' => $legacyAddress->address,
                'address2' => $legacyAddress->address2,
                'city' => $legacyAddress->city,
                'state_id' => $legacyAddress->state_id,
                'zip' => $legacyAddress->zip,
                'country_id' => 1,
                'phone' => $legacyAddress->phone ?? null,
                'is_shipping' => true,
                'created_at' => $legacyAddress->created_at,
                'updated_at' => $legacyAddress->updated_at,
            ]);

            $lead->update(['shipping_address_id' => $addressId]);
        } catch (\Exception $e) {
            Log::warning("Failed to migrate shipping address for lead (legacy transaction #{$legacyTransaction->id}): {$e->getMessage()}");
            $this->warn("  Warning: Failed to migrate shipping address for legacy transaction #{$legacyTransaction->id}: {$e->getMessage()}");
        }
    }

    protected function migrateLeadItems(int $legacyTransactionId, Lead $lead): void
    {
        $legacyItems = DB::connection('legacy')
            ->table('transaction_items')
            ->where('transaction_id', $legacyTransactionId)
            ->get();

        foreach ($legacyItems as $legacyItem) {
            if ($this->dryRun) {
                continue;
            }

            // Check if lead item already exists for this lead with matching legacy data
            if ($this->reimport) {
                // Delete existing items and re-create
                LeadItem::where('lead_id', $lead->id)->delete();
            }

            // Find category
            $legacyProductTypeId = $legacyItem->product_type_id ?? $legacyItem->category_id ?? null;
            $categoryId = null;
            if (! $this->option('skip-category-mapping') && $legacyProductTypeId) {
                $categoryId = $this->findCategoryByLegacyProductType($legacyProductTypeId);
            }

            $attributes = [
                'legacy_category_id' => $legacyItem->category_id,
                'legacy_product_type_id' => $legacyItem->product_type_id ?? null,
                'legacy_item_id' => $legacyItem->id,
            ];

            $itemData = [
                'lead_id' => $lead->id,
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

            $itemId = DB::table('lead_items')->insertGetId($itemData);
            $item = LeadItem::find($itemId);

            // Migrate item images
            if (! $this->option('skip-images')) {
                $this->migrateItemImages($legacyItem->id, $item);
            }
        }
    }

    protected function migrateItemImages(int $legacyItemId, LeadItem $item): void
    {
        $legacyImages = DB::connection('legacy')
            ->table('images')
            ->where('imageable_type', 'App\\Models\\TransactionItem')
            ->where('imageable_id', $legacyItemId)
            ->get();

        $legacyItemImages = DB::connection('legacy')
            ->table('transaction_item_images')
            ->where('transaction_item_id', $legacyItemId)
            ->get();

        foreach ($legacyImages as $legacyImage) {
            $path = parse_url($legacyImage->url, PHP_URL_PATH) ?? $legacyImage->url;

            DB::table('images')->insert([
                'store_id' => $this->newStoreId,
                'imageable_type' => LeadItem::class,
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
            $exists = Image::where('imageable_type', LeadItem::class)
                ->where('imageable_id', $item->id)
                ->where('url', $legacyImage->url)
                ->exists();

            if (! $exists) {
                $path = parse_url($legacyImage->url, PHP_URL_PATH) ?? $legacyImage->url;

                DB::table('images')->insert([
                    'store_id' => $this->newStoreId,
                    'imageable_type' => LeadItem::class,
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

    protected function migrateLeadImages(int $legacyTransactionId, Lead $lead): void
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

            DB::table('images')->insert([
                'store_id' => $this->newStoreId,
                'imageable_type' => Lead::class,
                'imageable_id' => $lead->id,
                'path' => $path,
                'url' => $legacyImage->url,
                'thumbnail_url' => $legacyImage->thumbnail ?? null,
                'sort_order' => $legacyImage->rank ?? 0,
                'created_at' => $legacyImage->created_at,
                'updated_at' => $legacyImage->updated_at,
            ]);
        }
    }

    protected function migrateActivities(int $legacyTransactionId, Lead $lead): void
    {
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

            $activitySlug = $this->mapActivitySlug($legacyActivity->activity);

            $userId = $legacyActivity->user_id;
            if ($userId) {
                $userExists = DB::connection('mysql')->table('users')->where('id', $userId)->exists();
                if (! $userExists) {
                    $userId = null;
                }
            }

            $causerType = $legacyActivity->creatable_type ? $this->mapCauserType($legacyActivity->creatable_type) : null;
            $causerId = $legacyActivity->creatable_id;
            if ($causerType === 'App\\Models\\User' && $causerId) {
                $causerExists = DB::connection('mysql')->table('users')->where('id', $causerId)->exists();
                if (! $causerExists) {
                    $causerType = null;
                    $causerId = null;
                }
            }

            DB::table('activity_logs')->insert([
                'store_id' => $this->newStoreId,
                'user_id' => $userId,
                'activity_slug' => $activitySlug,
                'subject_type' => Lead::class,
                'subject_id' => $lead->id,
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

        $this->migrateStatusActivities($legacyTransactionId, $lead);
        $this->migrateNonStatusActivities($legacyTransactionId, $lead);
    }

    protected function migrateNonStatusActivities(int $legacyTransactionId, Lead $lead): void
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

            $userId = $legacyActivity->user_id;
            if ($userId) {
                $userExists = DB::connection('mysql')->table('users')->where('id', $userId)->exists();
                if (! $userExists) {
                    $userId = null;
                }
            }

            $activitySlug = $this->mapNonStatusActivitySlug($legacyActivity->name);

            DB::table('activity_logs')->insert([
                'store_id' => $this->newStoreId,
                'user_id' => $userId,
                'activity_slug' => $activitySlug,
                'subject_type' => Lead::class,
                'subject_id' => $lead->id,
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

    protected function mapNonStatusActivitySlug(?string $activityName): string
    {
        if (! $activityName) {
            return 'leads.update';
        }

        $normalizedName = strtolower(trim($activityName));

        if (str_contains($normalizedName, 'note')) {
            return 'notes.create';
        }

        return 'leads.update';
    }

    protected function migrateStatusActivities(int $legacyTransactionId, Lead $lead): void
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

            $userId = $legacyActivity->user_id;
            if ($userId) {
                $userExists = DB::connection('mysql')->table('users')->where('id', $userId)->exists();
                if (! $userExists) {
                    $userId = null;
                }
            }

            $newStatus = $this->mapLegacyStatusName($legacyActivity->status);
            $activitySlug = $this->mapStatusToActivitySlug($legacyActivity->status);

            DB::table('activity_logs')->insert([
                'store_id' => $this->newStoreId,
                'user_id' => $userId,
                'activity_slug' => $activitySlug,
                'subject_type' => Lead::class,
                'subject_id' => $lead->id,
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

    protected function mapStatusToActivitySlug(?string $statusName): string
    {
        if (! $statusName) {
            return 'leads.status_change';
        }

        $normalizedName = strtolower(trim($statusName));

        if (str_contains($normalizedName, 'offer given') || str_contains($normalizedName, 'offer #')) {
            return 'leads.submit_offer';
        }
        if (str_contains($normalizedName, 'offer accepted')) {
            return 'leads.accept_offer';
        }
        if (str_contains($normalizedName, 'offer declined') || str_contains($normalizedName, 'customer declined')) {
            return 'leads.decline_offer';
        }
        if (str_contains($normalizedName, 'payment processed') || str_contains($normalizedName, 'payments processed')) {
            return 'leads.process_payment';
        }
        if (str_contains($normalizedName, 'pending kit request')) {
            return 'leads.create';
        }

        return 'leads.status_change';
    }

    protected function mapLegacyStatusName(?string $statusName): string
    {
        if (! $statusName) {
            return Lead::STATUS_PENDING_KIT_REQUEST;
        }

        $normalizedName = strtolower(trim($statusName));

        if (isset($this->statusNameMap[$normalizedName])) {
            return $this->statusNameMap[$normalizedName];
        }

        foreach ($this->statusNameMap as $key => $value) {
            if (str_contains($normalizedName, $key)) {
                return $value;
            }
        }

        if (str_contains($normalizedName, 'ready for melt') || str_contains($normalizedName, 'moved to nwe')) {
            return Lead::STATUS_PAYMENT_PROCESSED;
        }
        if (str_contains($normalizedName, 'kit received - ready to buy')) {
            return Lead::STATUS_ITEMS_REVIEWED;
        }
        if (str_contains($normalizedName, 'high value')) {
            return Lead::STATUS_KIT_REQUEST_ON_HOLD;
        }
        if (str_contains($normalizedName, 'incomplete') || str_contains($normalizedName, 'bulk')) {
            return Lead::STATUS_PENDING_KIT_REQUEST;
        }
        if (str_contains($normalizedName, 'archive')) {
            return Lead::STATUS_CANCELLED;
        }

        return Lead::STATUS_PENDING_KIT_REQUEST;
    }

    /**
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

    protected function mapPaymentMethodById(int $paymentTypeId): ?string
    {
        return match ($paymentTypeId) {
            1 => Lead::PAYMENT_CHECK,
            2 => Lead::PAYMENT_PAYPAL,
            3 => Lead::PAYMENT_ACH,
            4 => Lead::PAYMENT_VENMO,
            5 => Lead::PAYMENT_STORE_CREDIT,
            6 => Lead::PAYMENT_CASH,
            default => null,
        };
    }

    protected function mapLegacyStatus(?int $statusId, ?string $statusName): string
    {
        if ($statusId !== null && isset($this->statusIdMap[$statusId])) {
            return $this->statusIdMap[$statusId];
        }

        if ($statusName !== null) {
            $normalizedName = strtolower(trim($statusName));

            if (isset($this->statusNameMap[$normalizedName])) {
                return $this->statusNameMap[$normalizedName];
            }

            foreach ($this->statusNameMap as $key => $value) {
                if (str_contains($normalizedName, $key)) {
                    return $value;
                }
            }

            if (str_contains($normalizedName, 'payment processed') || str_contains($normalizedName, 'paid')) {
                return Lead::STATUS_PAYMENT_PROCESSED;
            }
            if (str_contains($normalizedName, 'offer accepted')) {
                return Lead::STATUS_OFFER_ACCEPTED;
            }
            if (str_contains($normalizedName, 'offer declined') || str_contains($normalizedName, 'declined')) {
                return Lead::STATUS_CUSTOMER_DECLINED_OFFER;
            }
            if (str_contains($normalizedName, 'offer given') || str_contains($normalizedName, 'pending offer')) {
                return Lead::STATUS_OFFER_GIVEN;
            }
            if (str_contains($normalizedName, 'kit sent')) {
                return Lead::STATUS_KIT_SENT;
            }
            if (str_contains($normalizedName, 'received') || str_contains($normalizedName, 'kits received')) {
                return Lead::STATUS_ITEMS_RECEIVED;
            }
            if (str_contains($normalizedName, 'reviewed') || str_contains($normalizedName, 'ready to buy')) {
                return Lead::STATUS_ITEMS_REVIEWED;
            }
            if (str_contains($normalizedName, 'returned') || str_contains($normalizedName, 'return')) {
                return Lead::STATUS_ITEMS_RETURNED;
            }
            if (str_contains($normalizedName, 'pending kit') || str_contains($normalizedName, 'kit request')) {
                return Lead::STATUS_PENDING_KIT_REQUEST;
            }
            if (str_contains($normalizedName, 'on hold') || str_contains($normalizedName, 'hold')) {
                return Lead::STATUS_KIT_REQUEST_ON_HOLD;
            }
            if (str_contains($normalizedName, 'rejected')) {
                return Lead::STATUS_KIT_REQUEST_REJECTED;
            }
            if (str_contains($normalizedName, 'confirmed')) {
                return Lead::STATUS_KIT_REQUEST_CONFIRMED;
            }
            if (str_contains($normalizedName, 'archive') || str_contains($normalizedName, 'cancelled')) {
                return Lead::STATUS_CANCELLED;
            }
        }

        return Lead::STATUS_PENDING_KIT_REQUEST;
    }

    /**
     * Get the status_id for a given status slug from the store's statuses table.
     */
    protected function getStatusIdForSlug(int $storeId, string $statusSlug, string $entityType = 'lead'): ?int
    {
        static $statusCache = [];

        $cacheKey = "{$storeId}:{$entityType}:{$statusSlug}";

        if (isset($statusCache[$cacheKey])) {
            return $statusCache[$cacheKey];
        }

        $statusId = Status::where('store_id', $storeId)
            ->where('entity_type', $entityType)
            ->where('slug', $statusSlug)
            ->value('id');

        $statusCache[$cacheKey] = $statusId;

        if ($statusId === null) {
            static $missingStatuses = [];
            if (! isset($missingStatuses[$cacheKey])) {
                $this->warn("  Status '{$statusSlug}' (entity_type: {$entityType}) not found for store {$storeId}. Lead will have null status_id.");
                $missingStatuses[$cacheKey] = true;
            }
        }

        return $statusId;
    }

    protected function mapActivitySlug(string $legacyActivity): string
    {
        $mapping = [
            'status_updated' => 'leads.update',
            'item_added' => 'leads.update',
            'item_deleted' => 'leads.update',
            'internal_note_created' => 'leads.update',
            'internal_note_edited' => 'leads.update',
            'external_note_created' => 'leads.update',
            'details_updated' => 'leads.update',
            'created_transaction' => 'leads.create',
            'created_shipping_label' => 'leads.update',
            'created_return_label' => 'leads.update',
            'added_tracking_number' => 'leads.update',
            'payment_added_to_transaction' => 'leads.update',
            'item_added_to_transaction' => 'leads.update',
            'transaction_status_changed' => 'leads.update',
            'transaction_item_reviewed' => 'leads.update',
        ];

        return $mapping[$legacyActivity] ?? 'leads.update';
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
     */
    protected function findCategoryByLegacyProductType(?int $productTypeId): ?int
    {
        if (! $productTypeId) {
            return null;
        }

        if (isset($this->categoryMap[$productTypeId])) {
            return $this->categoryMap[$productTypeId];
        }

        // Use the category-mapping-store option if provided
        $mappingStoreId = $this->option('category-mapping-store') ?? $this->legacyStoreId;

        $legacyCategory = DB::connection('legacy')
            ->table('store_categories')
            ->where('id', $productTypeId)
            ->whereNull('deleted_at')
            ->first();

        if (! $legacyCategory) {
            return null;
        }

        // Try to find by name match in new store
        $newCategory = Category::where('store_id', $this->newStoreId)
            ->where('name', $legacyCategory->name)
            ->first();

        if ($newCategory) {
            $this->categoryMap[$productTypeId] = $newCategory->id;

            return $newCategory->id;
        }

        $this->categoryMap[$productTypeId] = null;

        return null;
    }
}
