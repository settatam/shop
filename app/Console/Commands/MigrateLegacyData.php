<?php

namespace App\Console\Commands;

use App\Enums\StatusableType;
use App\Models\ActivityLog;
use App\Models\Address;
use App\Models\Category;
use App\Models\Customer;
use App\Models\LeadSource;
use App\Models\Note;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\ProductTemplate;
use App\Models\ProductTemplateField;
use App\Models\ProductTemplateFieldOption;
use App\Models\Role;
use App\Models\ShippingLabel;
use App\Models\Status;
use App\Models\StatusHistory;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Statuses\StatusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MigrateLegacyData extends Command
{
    protected $signature = 'migrate:legacy
                            {--store-id=43 : Legacy store ID to migrate}
                            {--limit=100 : Number of transactions to migrate (0 for all)}
                            {--skip-users : Skip user migration}
                            {--skip-customers : Skip customer migration}
                            {--skip-transactions : Skip transaction migration}
                            {--skip-categories : Skip category migration}
                            {--skip-templates : Skip product template migration}
                            {--skip-sms : Skip SMS message migration}
                            {--skip-notes : Skip transaction notes migration}
                            {--skip-shipping-labels : Skip shipping labels migration}
                            {--skip-status-history : Skip status history migration}
                            {--skip-addresses : Skip address migration}
                            {--dry-run : Show what would be migrated without making changes}
                            {--fresh : Delete existing migrated data and start fresh}';

    protected $description = 'Migrate data from the legacy Laravel 8 database';

    protected array $statusMap = [];

    protected array $customerMap = [];

    protected array $userMap = [];

    protected array $categoryMap = [];

    protected array $templateMap = [];

    protected array $templateFieldMap = [];

    protected array $transactionMap = [];

    protected array $addressMap = [];

    protected array $leadSourceMap = [];

    protected ?Store $newStore = null;

    protected ?Warehouse $warehouse = null;

    public function handle(): int
    {
        $legacyStoreId = (int) $this->option('store-id');
        $limit = (int) $this->option('limit');
        $isDryRun = $this->option('dry-run');

        $this->info("Starting migration from legacy store ID: {$legacyStoreId}");

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

        $this->info("Migrating store: {$legacyStore->name}");

        if ($this->option('fresh') && ! $isDryRun) {
            if (! $this->input->isInteractive() || $this->confirm('This will delete all previously migrated data. Continue?')) {
                $this->cleanupMigratedData($legacyStoreId);
            }
        }

        try {
            DB::beginTransaction();

            // Step 1: Migrate Store
            $this->newStore = $this->migrateStore($legacyStore, $isDryRun);
            if (! $this->newStore) {
                throw new \Exception('Failed to create/find store');
            }

            // Step 2: Migrate Users
            if (! $this->option('skip-users')) {
                $this->migrateUsers($legacyStoreId, $isDryRun);
            }

            // Step 3: Migrate Product Templates (html_forms) - BEFORE categories so template_id can be linked
            if (! $this->option('skip-templates')) {
                $this->migrateProductTemplates($legacyStoreId, $isDryRun);
            }

            // Step 4: Migrate Categories (from store_categories, with template_id linking)
            if (! $this->option('skip-categories')) {
                $this->migrateCategories($legacyStoreId, $isDryRun);
            }

            // Step 5: Create Status Mapping
            $this->createStatusMapping($legacyStoreId, $isDryRun);

            // Step 5.5: Migrate Lead Sources (before customers)
            $this->migrateLeadSources($legacyStoreId, $isDryRun);

            // Step 6: Migrate Customers
            if (! $this->option('skip-customers')) {
                $this->migrateCustomers($legacyStoreId, $isDryRun, $limit > 0 ? $limit * 2 : 0);
            }

            // Step 6.5: Migrate Addresses (after customers, before transactions)
            if (! $this->option('skip-addresses')) {
                $this->migrateAddresses($legacyStoreId, $isDryRun);
            }

            // Step 7: Migrate Transactions (preserving IDs)
            if (! $this->option('skip-transactions')) {
                $this->migrateTransactions($legacyStoreId, $isDryRun, $limit);
            }

            // Step 8: Migrate SMS Messages
            if (! $this->option('skip-sms')) {
                $this->migrateSmsMessages($legacyStoreId, $isDryRun);
            }

            // Step 9: Migrate Transaction Notes
            if (! $this->option('skip-notes')) {
                $this->migrateTransactionNotes($legacyStoreId, $isDryRun);
            }

            // Step 10: Migrate Shipping Labels
            if (! $this->option('skip-shipping-labels')) {
                $this->migrateShippingLabels($legacyStoreId, $isDryRun);
            }

            // Step 11: Migrate Status History
            if (! $this->option('skip-status-history')) {
                $this->migrateStatusHistory($legacyStoreId, $isDryRun);
            }

            if ($isDryRun) {
                DB::rollBack();
                $this->info('Dry run complete - no changes made');
            } else {
                DB::commit();
                $this->info('Migration complete!');
            }

            $this->displaySummary();

            return 0;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Migration failed: {$e->getMessage()}");
            $this->error($e->getTraceAsString());

            return 1;
        }
    }

    protected function migrateStore(object $legacyStore, bool $isDryRun): ?Store
    {
        $this->info('Migrating store...');

        // Check if store already exists (by legacy_id in metadata or name)
        $existingStore = Store::where('name', $legacyStore->name)->first();

        if ($existingStore) {
            $this->line("  Store '{$legacyStore->name}' already exists (ID: {$existingStore->id})");
            $this->warehouse = $existingStore->warehouses()->where('is_default', true)->first();

            return $existingStore;
        }

        if ($isDryRun) {
            $this->line("  Would create store: {$legacyStore->name}");

            return new Store(['id' => 999, 'name' => $legacyStore->name]);
        }

        // Get or create owner user
        $legacyOwner = DB::connection('legacy')
            ->table('users')
            ->where('id', $legacyStore->user_id)
            ->first();

        $owner = null;
        if ($legacyOwner) {
            $owner = User::where('email', $legacyOwner->email)->first();
            if (! $owner) {
                $owner = User::create([
                    'name' => trim(($legacyOwner->first_name ?? '').' '.($legacyOwner->last_name ?? '')) ?: 'Store Owner',
                    'email' => $legacyOwner->email,
                    'password' => $legacyOwner->password ?? Hash::make('changeme123'),
                    'email_verified_at' => now(),
                ]);
                $this->line("  Created owner user: {$owner->email}");
                $this->userMap[$legacyStore->user_id] = $owner->id;
            }
        } else {
            // Create a placeholder owner
            $owner = User::create([
                'name' => 'Store Owner',
                'email' => $legacyStore->account_email ?? "owner-{$legacyStore->id}@migrated.local",
                'password' => Hash::make('changeme123'),
                'email_verified_at' => now(),
            ]);
        }

        // Create the store
        $store = Store::create([
            'user_id' => $owner->id,
            'name' => $legacyStore->name,
            'slug' => $legacyStore->slug,
            'business_name' => $legacyStore->business_name,
            'account_email' => $legacyStore->account_email,
            'customer_email' => $legacyStore->customer_email,
            'phone' => $legacyStore->phone,
            'address' => $legacyStore->street_address,
            'address2' => $legacyStore->street_address2,
            'city' => $legacyStore->city,
            'state' => $legacyStore->state,
            'zip' => $legacyStore->zip,
            'url' => $legacyStore->url,
            'store_domain' => $legacyStore->store_domain,
            'step' => 2, // Mark onboarding complete
        ]);

        $this->line("  Created store: {$store->name} (ID: {$store->id})");

        // Create owner role and store_user
        $ownerRole = Role::create([
            'store_id' => $store->id,
            'name' => 'Owner',
            'slug' => 'owner',
            'permissions' => ['*'],
            'is_system' => true,
        ]);

        StoreUser::create([
            'user_id' => $owner->id,
            'store_id' => $store->id,
            'role_id' => $ownerRole->id,
            'is_owner' => true,
        ]);

        $owner->update(['current_store_id' => $store->id]);

        // Create default warehouse from store_locations
        $legacyLocation = DB::connection('legacy')
            ->table('store_locations')
            ->where('store_id', $legacyStore->id)
            ->where('is_default', true)
            ->orWhere(function ($q) use ($legacyStore) {
                $q->where('store_id', $legacyStore->id);
            })
            ->first();

        $this->warehouse = Warehouse::create([
            'store_id' => $store->id,
            'name' => $legacyLocation->name ?? 'Main Location',
            'code' => 'MAIN',
            'is_default' => true,
            'address_line1' => $legacyStore->street_address,
            'city' => $legacyStore->city,
            'state' => $legacyStore->state,
            'postal_code' => $legacyStore->zip,
            'country' => 'United States',
            'tax_rate' => $legacyLocation->tax_rate ?? 0,
        ]);

        $this->line("  Created warehouse: {$this->warehouse->name}");

        // Create default statuses for all entity types
        $statusService = app(StatusService::class);
        foreach (StatusableType::cases() as $type) {
            $statusService->createDefaultStatuses($store->id, $type);
        }
        $this->line('  Created default statuses for all entity types');

        return $store;
    }

    protected function migrateUsers(int $legacyStoreId, bool $isDryRun): void
    {
        $this->info('Migrating users...');

        $legacyUsers = DB::connection('legacy')
            ->table('store_users')
            ->where('store_id', $legacyStoreId)
            ->whereNull('deleted_at')
            ->get();

        $count = 0;
        foreach ($legacyUsers as $legacyStoreUser) {
            // Get the actual user record
            $legacyUser = DB::connection('legacy')
                ->table('users')
                ->where('id', $legacyStoreUser->user_id)
                ->first();

            if (! $legacyUser) {
                continue;
            }

            // Check if user already exists
            $existingUser = User::where('email', $legacyUser->email)->first();

            if ($isDryRun) {
                $action = $existingUser ? 'Would link' : 'Would create';
                $this->line("  {$action} user: {$legacyUser->email}");
                $count++;

                continue;
            }

            if (! $existingUser) {
                $existingUser = User::create([
                    'name' => trim(($legacyUser->first_name ?? '').' '.($legacyUser->last_name ?? '')) ?: $legacyUser->email,
                    'email' => $legacyUser->email,
                    'password' => $legacyUser->password ?? Hash::make('changeme123'),
                    'email_verified_at' => now(),
                ]);
                $this->line("  Created user: {$existingUser->email}");
            }

            $this->userMap[$legacyStoreUser->user_id] = $existingUser->id;

            // Check if store_user relationship exists
            $existingStoreUser = StoreUser::where('user_id', $existingUser->id)
                ->where('store_id', $this->newStore->id)
                ->first();

            if (! $existingStoreUser) {
                // Get or create a staff role
                $staffRole = Role::where('store_id', $this->newStore->id)
                    ->where('slug', 'staff')
                    ->first();

                if (! $staffRole) {
                    $staffRole = Role::create([
                        'store_id' => $this->newStore->id,
                        'name' => 'Staff',
                        'slug' => 'staff',
                        'permissions' => [
                            'products.view', 'products.create', 'products.edit',
                            'orders.view', 'orders.create',
                            'customers.view', 'customers.create', 'customers.edit',
                            'transactions.view', 'transactions.create', 'transactions.edit',
                        ],
                        'is_system' => true,
                    ]);
                }

                StoreUser::create([
                    'user_id' => $existingUser->id,
                    'store_id' => $this->newStore->id,
                    'role_id' => $staffRole->id,
                ]);
            }

            $count++;
        }

        $this->line("  Processed {$count} users");
    }

    protected function migrateCategories(int $legacyStoreId, bool $isDryRun): void
    {
        $this->info('Migrating categories from store_categories...');

        // Get all store_categories for this store (including html_form_id for template linking)
        $legacyCategories = DB::connection('legacy')
            ->table('store_categories')
            ->where('store_id', $legacyStoreId)
            ->whereNull('deleted_at')
            ->orderBy('level')
            ->orderBy('parent_id')
            ->orderBy('sort_order')
            ->get();

        if ($isDryRun) {
            $this->line("  Would migrate {$legacyCategories->count()} categories");

            return;
        }

        $count = 0;
        $linkedTemplates = 0;

        // First pass: Create all categories with template linking
        foreach ($legacyCategories as $legacyCategory) {
            // Clean up duplicate suffixes in names like "Pocket Watch - Pocket Watch - ..."
            $name = $legacyCategory->name;
            $name = preg_replace('/( - [^-]+)\1+$/', '$1', $name);

            $slug = Str::slug($name);

            // Truncate slug to 100 chars max
            if (strlen($slug) > 100) {
                $slug = substr($slug, 0, 100);
            }

            // Make slug unique within store
            $baseSlug = $slug;
            $counter = 1;
            while (Category::where('store_id', $this->newStore->id)->where('slug', $slug)->exists()) {
                $suffix = "-{$counter}";
                $slug = substr($baseSlug, 0, 100 - strlen($suffix)).$suffix;
                $counter++;
            }

            // Legacy uses 1-indexed levels, we use 0-indexed
            $level = ($legacyCategory->level ?? 1) - 1;
            if ($level < 0) {
                $level = 0;
            }

            // Map legacy html_form_id to new template_id
            $templateId = null;
            if ($legacyCategory->html_form_id && isset($this->templateMap[$legacyCategory->html_form_id])) {
                $templateId = $this->templateMap[$legacyCategory->html_form_id];
                $linkedTemplates++;
            }

            $newCategory = Category::create([
                'store_id' => $this->newStore->id,
                'name' => $name,
                'slug' => $slug,
                'sort_order' => $legacyCategory->sort_order ?? 0,
                'level' => $level,
                'template_id' => $templateId,
                'sku_prefix' => $legacyCategory->sku_prefix,
                'sku_suffix' => $legacyCategory->sku_suffix,
                'charge_taxes' => $legacyCategory->charge_taxes ?? true,
                'created_at' => $legacyCategory->created_at,
                'updated_at' => $legacyCategory->updated_at,
            ]);

            $this->categoryMap[$legacyCategory->id] = $newCategory->id;
            $count++;
        }

        // Second pass: Set parent relationships
        foreach ($legacyCategories as $legacyCategory) {
            if ($legacyCategory->parent_id && $legacyCategory->parent_id != 0) {
                $newCategoryId = $this->categoryMap[$legacyCategory->id] ?? null;
                $newParentId = $this->categoryMap[$legacyCategory->parent_id] ?? null;

                if ($newCategoryId && $newParentId) {
                    Category::where('id', $newCategoryId)->update(['parent_id' => $newParentId]);
                }
            }
        }

        // Third pass: Recalculate levels for orphaned categories (parent was deleted)
        $this->recalculateCategoryLevels();

        // Fourth pass: Assign templates to leaf categories using sibling templates
        $inherited = $this->inheritSiblingTemplates();

        $this->line("  Created {$count} categories with hierarchy ({$linkedTemplates} linked to templates, {$inherited} inherited from siblings)");
    }

    /**
     * Assign templates to leaf categories that don't have one, using their siblings' templates.
     * Only leaf categories should have templates assigned.
     */
    protected function inheritSiblingTemplates(): int
    {
        $inherited = 0;

        // Get all leaf categories without templates
        $leafCategories = Category::where('store_id', $this->newStore->id)
            ->whereNull('template_id')
            ->whereDoesntHave('children')
            ->whereNotNull('parent_id')
            ->get();

        foreach ($leafCategories as $category) {
            // Find a sibling with a template
            $siblingWithTemplate = Category::where('store_id', $this->newStore->id)
                ->where('parent_id', $category->parent_id)
                ->where('id', '!=', $category->id)
                ->whereNotNull('template_id')
                ->first();

            if ($siblingWithTemplate) {
                $category->update(['template_id' => $siblingWithTemplate->template_id]);
                $inherited++;
            }
        }

        return $inherited;
    }

    /**
     * Recalculate category levels based on parent hierarchy.
     */
    protected function recalculateCategoryLevels(): void
    {
        $categories = Category::where('store_id', $this->newStore->id)->get()->keyBy('id');

        foreach ($categories as $category) {
            $level = $this->calculateCategoryLevel($category, $categories);
            if ($category->level !== $level) {
                $category->update(['level' => $level]);
            }
        }
    }

    /**
     * Calculate the level of a category based on its parent chain.
     */
    protected function calculateCategoryLevel(Category $category, \Illuminate\Support\Collection $categories, array $visited = []): int
    {
        if ($category->parent_id === null) {
            return 0;
        }

        // Prevent infinite loops from circular references
        if (in_array($category->id, $visited)) {
            return 0;
        }
        $visited[] = $category->id;

        $parent = $categories->get($category->parent_id);
        if (! $parent) {
            return 0;
        }

        return 1 + $this->calculateCategoryLevel($parent, $categories, $visited);
    }

    protected function migrateProductTemplates(int $legacyStoreId, bool $isDryRun): void
    {
        $this->info('Migrating product templates (html_forms)...');

        // Get html_forms for this store
        $legacyForms = DB::connection('legacy')
            ->table('html_forms')
            ->where('store_id', $legacyStoreId)
            ->whereNull('deleted_at')
            ->get();

        if ($isDryRun) {
            $this->line("  Would migrate {$legacyForms->count()} product templates");

            return;
        }

        $templateCount = 0;
        $fieldCount = 0;
        $optionCount = 0;

        foreach ($legacyForms as $legacyForm) {
            // Create product template
            $template = ProductTemplate::create([
                'store_id' => $this->newStore->id,
                'name' => $legacyForm->title,
                'description' => "Migrated from legacy html_form #{$legacyForm->id}",
                'is_active' => true,
                'created_at' => $legacyForm->created_at,
                'updated_at' => $legacyForm->updated_at,
            ]);

            $this->templateMap[$legacyForm->id] = $template->id;
            $templateCount++;

            // Get fields for this form
            $legacyFields = DB::connection('legacy')
                ->table('html_form_fields')
                ->where('html_form_id', $legacyForm->id)
                ->orderBy('sort_order')
                ->get();

            $sortOrder = 0;
            $usedFieldNames = []; // Track used field names within this template

            foreach ($legacyFields as $legacyField) {
                // Map component type to our field types
                $fieldType = $this->mapFieldType($legacyField->component);

                // Make field name unique within the template
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
                    'canonical_name' => $legacyField->name,
                    'label' => $legacyField->label.($counter > 1 ? " ({$counter})" : ''),
                    'type' => $fieldType,
                    'is_required' => false,
                    'is_searchable' => (bool) $legacyField->is_searchable,
                    'sort_order' => $sortOrder++,
                    'created_at' => $legacyField->created_at,
                    'updated_at' => $legacyField->updated_at,
                ]);

                $this->templateFieldMap[$legacyField->id] = $field->id;
                $fieldCount++;

                // Get field options (for select fields)
                if (in_array($fieldType, ['select', 'multiselect', 'radio', 'checkbox'])) {
                    $legacyOptions = DB::connection('legacy')
                        ->table('html_form_field_values')
                        ->where('html_form_field_id', $legacyField->id)
                        ->orderBy('sort_order')
                        ->get();

                    $optSortOrder = 0;
                    foreach ($legacyOptions as $legacyOption) {
                        ProductTemplateFieldOption::create([
                            'product_template_field_id' => $field->id,
                            'label' => $legacyOption->value,
                            'value' => Str::slug($legacyOption->value),
                            'sort_order' => $optSortOrder++,
                            'created_at' => $legacyOption->created_at,
                            'updated_at' => $legacyOption->updated_at,
                        ]);
                        $optionCount++;
                    }
                }
            }
        }

        $this->line("  Created {$templateCount} templates with {$fieldCount} fields and {$optionCount} options");
    }

    protected function mapFieldType(string $component): string
    {
        return match (strtolower($component)) {
            'select' => 'select',
            'input' => 'text',
            'textarea' => 'textarea',
            'checkbox' => 'checkbox',
            'radio' => 'radio',
            'number' => 'number',
            'date' => 'date',
            'file' => 'file',
            'image' => 'image',
            default => 'text',
        };
    }

    protected function createStatusMapping(int $legacyStoreId, bool $isDryRun): void
    {
        $this->info('Creating status mapping...');

        // Get legacy statuses
        $legacyStatuses = DB::connection('legacy')
            ->table('statuses')
            ->where('store_id', $legacyStoreId)
            ->whereNull('deleted_at')
            ->get();

        // Get new statuses for this store
        $newStatuses = Status::where('store_id', $this->newStore->id)
            ->where('entity_type', 'transaction')
            ->get()
            ->keyBy('slug');

        // Create mapping based on name similarity
        $mappings = [
            // Legacy name patterns => new status slug
            'pending' => 'pending',
            'kit' => 'pending', // Kit-related statuses map to pending
            'received' => 'items_received',
            'reviewed' => 'items_reviewed',
            'offer given' => 'offer_given',
            'offer accepted' => 'offer_accepted',
            'offer declined' => 'offer_declined',
            'customer declined' => 'offer_declined',
            'payment' => 'payment_processed',
            'returned' => 'cancelled',
            'rejected' => 'cancelled',
            'on hold' => 'pending',
        ];

        foreach ($legacyStatuses as $legacyStatus) {
            $legacyName = strtolower($legacyStatus->name);
            $mappedSlug = 'pending'; // Default

            foreach ($mappings as $pattern => $slug) {
                if (str_contains($legacyName, $pattern)) {
                    $mappedSlug = $slug;
                    break;
                }
            }

            $newStatus = $newStatuses->get($mappedSlug);
            if ($newStatus) {
                $this->statusMap[$legacyStatus->id] = $newStatus->id;
                $this->line("  Mapped '{$legacyStatus->name}' => '{$newStatus->name}'");
            }
        }

        $this->line('  Created '.count($this->statusMap).' status mappings');
    }

    protected function migrateLeadSources(int $legacyStoreId, bool $isDryRun): void
    {
        $this->info('Migrating lead sources...');

        $legacyLeadSources = DB::connection('legacy')
            ->table('leads')
            ->where('store_id', $legacyStoreId)
            ->orderBy('id')
            ->get();

        $count = 0;

        foreach ($legacyLeadSources as $index => $legacyLead) {
            // Check if lead source already exists by name
            $existingSource = LeadSource::where('store_id', $this->newStore->id)
                ->where('name', $legacyLead->name)
                ->first();

            if ($existingSource) {
                $this->leadSourceMap[$legacyLead->id] = $existingSource->id;

                continue;
            }

            if ($isDryRun) {
                $this->line("  Would create lead source: {$legacyLead->name}");
                $count++;

                continue;
            }

            $newLeadSource = LeadSource::create([
                'store_id' => $this->newStore->id,
                'name' => $legacyLead->name,
                'sort_order' => $index,
                'is_active' => true,
                'created_at' => $legacyLead->created_at,
                'updated_at' => $legacyLead->updated_at,
            ]);

            $this->leadSourceMap[$legacyLead->id] = $newLeadSource->id;
            $count++;
        }

        $this->line("  Created {$count} lead sources");
    }

    protected function migrateCustomers(int $legacyStoreId, bool $isDryRun, int $limit = 0): void
    {
        $this->info('Migrating customers...');

        $query = DB::connection('legacy')
            ->table('customers')
            ->where('store_id', $legacyStoreId)
            ->whereNull('deleted_at');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $legacyCustomers = $query->get();
        $count = 0;
        $skipped = 0;

        foreach ($legacyCustomers as $legacyCustomer) {
            // Check if customer already exists by email (if they have one)
            $existingCustomer = null;
            if ($legacyCustomer->email) {
                $existingCustomer = Customer::where('store_id', $this->newStore->id)
                    ->where('email', $legacyCustomer->email)
                    ->first();
            }

            if ($existingCustomer) {
                $this->customerMap[$legacyCustomer->id] = $existingCustomer->id;
                $skipped++;

                continue;
            }

            if ($isDryRun) {
                $name = trim(($legacyCustomer->first_name ?? '').' '.($legacyCustomer->last_name ?? ''));
                $this->line("  Would create customer: {$name} ({$legacyCustomer->email})");
                $count++;

                continue;
            }

            // Map lead source ID
            $newLeadSourceId = null;
            if (isset($legacyCustomer->lead_id) && $legacyCustomer->lead_id) {
                $newLeadSourceId = $this->leadSourceMap[$legacyCustomer->lead_id] ?? null;
            }

            $newCustomer = Customer::create([
                'store_id' => $this->newStore->id,
                'lead_source_id' => $newLeadSourceId,
                'first_name' => $legacyCustomer->first_name,
                'last_name' => $legacyCustomer->last_name,
                'email' => $legacyCustomer->email,
                'phone_number' => $legacyCustomer->phone_number,
                'address' => $legacyCustomer->street_address,
                'address2' => $legacyCustomer->street_address2,
                'city' => $legacyCustomer->city,
                'zip' => $legacyCustomer->zip,
                'company_name' => $legacyCustomer->company_name,
                'ethnicity' => $legacyCustomer->ethnicity,
                'photo' => $legacyCustomer->photo,
                'accepts_marketing' => $legacyCustomer->accepts_marketing ?? false,
                'is_active' => $legacyCustomer->is_active ?? true,
                'number_of_sales' => $legacyCustomer->number_of_sales ?? 0,
                'number_of_buys' => $legacyCustomer->number_of_buys ?? 0,
                'last_sales_date' => $legacyCustomer->last_sales_date,
                'created_at' => $legacyCustomer->created_at,
                'updated_at' => $legacyCustomer->updated_at,
            ]);

            $this->customerMap[$legacyCustomer->id] = $newCustomer->id;
            $count++;
        }

        $this->line("  Created {$count} customers, skipped {$skipped} existing");
    }

    protected function migrateTransactions(int $legacyStoreId, bool $isDryRun, int $limit = 0): void
    {
        $this->info('Migrating transactions (preserving legacy IDs)...');

        $query = DB::connection('legacy')
            ->table('transactions')
            ->where('store_id', $legacyStoreId)
            ->whereNull('deleted_at')
            ->orderBy('id', 'asc'); // Process in order to preserve IDs

        if ($limit > 0) {
            $query->limit($limit);
        }

        $legacyTransactions = $query->get();
        $count = 0;
        $itemCount = 0;

        // Get default status
        $defaultStatus = Status::where('store_id', $this->newStore->id)
            ->where('entity_type', 'transaction')
            ->where('is_default', true)
            ->first();

        foreach ($legacyTransactions as $legacyTxn) {
            // Get current status from status_updates table (most recent entry)
            $currentStatusUpdate = DB::connection('legacy')
                ->table('status_updates')
                ->where('store_id', $legacyStoreId)
                ->where('updateable_type', 'App\\Models\\Transaction')
                ->where('updateable_id', $legacyTxn->id)
                ->orderBy('created_at', 'desc')
                ->first();

            // Map status name to new status_id
            $newStatusId = $this->mapStatusNameToId($currentStatusUpdate?->current_status, $defaultStatus?->id);

            // Map customer
            $newCustomerId = null;
            if ($legacyTxn->customer_id && isset($this->customerMap[$legacyTxn->customer_id])) {
                $newCustomerId = $this->customerMap[$legacyTxn->customer_id];
            } elseif ($legacyTxn->customer_id) {
                // Customer not migrated yet, try to migrate just this one
                $legacyCustomer = DB::connection('legacy')
                    ->table('customers')
                    ->where('id', $legacyTxn->customer_id)
                    ->first();

                if ($legacyCustomer && ! $isDryRun) {
                    $newCustomer = Customer::create([
                        'store_id' => $this->newStore->id,
                        'first_name' => $legacyCustomer->first_name,
                        'last_name' => $legacyCustomer->last_name,
                        'email' => $legacyCustomer->email,
                        'phone_number' => $legacyCustomer->phone_number,
                        'address' => $legacyCustomer->street_address,
                        'city' => $legacyCustomer->city,
                        'zip' => $legacyCustomer->zip,
                        'is_active' => true,
                        'created_at' => $legacyCustomer->created_at,
                    ]);
                    $this->customerMap[$legacyTxn->customer_id] = $newCustomer->id;
                    $newCustomerId = $newCustomer->id;
                }
            }

            // Map user
            $newUserId = null;
            if ($legacyTxn->user_id && isset($this->userMap[$legacyTxn->user_id])) {
                $newUserId = $this->userMap[$legacyTxn->user_id];
            }

            if ($isDryRun) {
                $this->line("  Would create transaction #{$legacyTxn->id}: \${$legacyTxn->final_offer}");
                $count++;

                continue;
            }

            // Get payment method from transaction_payment_addresses
            $paymentData = $this->getTransactionPaymentMethod($legacyTxn->id, $legacyStoreId);

            // Use insertGetId to preserve the legacy transaction ID
            $transactionId = DB::table('transactions')->insertGetId([
                'id' => $legacyTxn->id, // Preserve legacy ID
                'store_id' => $this->newStore->id,
                'customer_id' => $newCustomerId,
                'user_id' => $newUserId,
                'warehouse_id' => $this->warehouse?->id,
                'status_id' => $newStatusId,
                'status' => $this->deriveStatusString($legacyTxn, $currentStatusUpdate?->current_status),
                'type' => $legacyTxn->is_in_house ? Transaction::TYPE_IN_HOUSE : Transaction::TYPE_MAIL_IN,
                'source' => 'online',
                'transaction_number' => "TXN-{$legacyTxn->id}",
                'preliminary_offer' => $legacyTxn->preliminary_offer ?? 0,
                'final_offer' => $legacyTxn->final_offer ?? 0,
                'estimated_value' => $legacyTxn->estimated_value ?? 0,
                'payment_method' => $paymentData['method'],
                'payment_details' => $paymentData['details'] ? json_encode($paymentData['details']) : null,
                'bin_location' => $legacyTxn->bin_location,
                'customer_notes' => $legacyTxn->customer_description,
                'internal_notes' => $legacyTxn->comments,
                'customer_categories' => $legacyTxn->customer_categories,
                'customer_amount' => $legacyTxn->customer_amount,
                'created_at' => $this->fixDstTimestamp($legacyTxn->created_at),
                'updated_at' => $this->fixDstTimestamp($legacyTxn->updated_at),
            ]);

            // Migrate transaction items with category linking
            $legacyItems = DB::connection('legacy')
                ->table('transaction_items')
                ->where('transaction_id', $legacyTxn->id)
                ->get();

            foreach ($legacyItems as $legacyItem) {
                // Map category
                $newCategoryId = null;
                if ($legacyItem->category_id && isset($this->categoryMap[$legacyItem->category_id])) {
                    $newCategoryId = $this->categoryMap[$legacyItem->category_id];
                }

                DB::table('transaction_items')->insert([
                    'transaction_id' => $transactionId,
                    'category_id' => $newCategoryId,
                    'sku' => $legacyItem->sku,
                    'title' => $legacyItem->title ?? $legacyItem->product_name,
                    'description' => $this->buildItemDescription($legacyItem),
                    'price' => $legacyItem->price ?? 0,
                    'buy_price' => $legacyItem->override ? ($legacyItem->override_price ?? $legacyItem->price) : ($legacyItem->price ?? 0),
                    'dwt' => $legacyItem->dwt,
                    'precious_metal' => $legacyItem->precious_metal ?? $legacyItem->category,
                    'condition' => null,
                    'is_added_to_inventory' => $legacyItem->is_added_to_inventory ?? false,
                    'date_added_to_inventory' => $this->fixDstTimestamp($legacyItem->date_added_to_inventory),
                    'created_at' => $this->fixDstTimestamp($legacyItem->created_at),
                    'updated_at' => $this->fixDstTimestamp($legacyItem->updated_at),
                ]);
                $itemCount++;
            }

            // Migrate activities for this transaction (status changes)
            $this->migrateTransactionActivities($legacyTxn->id, $transactionId, $legacyStoreId);

            $count++;

            if ($count % 100 === 0) {
                $this->line("  Processed {$count} transactions...");
            }
        }

        $this->line("  Created {$count} transactions with {$itemCount} items");
    }

    protected function migrateTransactionActivities(int $legacyTxnId, int $newTxnId, int $legacyStoreId): void
    {
        // Get activities for this transaction
        $legacyActivities = DB::connection('legacy')
            ->table('activities')
            ->where('activityable_type', 'App\\Models\\Transaction')
            ->where('activityable_id', $legacyTxnId)
            ->where('store_id', $legacyStoreId)
            ->orderBy('created_at')
            ->get();

        foreach ($legacyActivities as $legacyActivity) {
            // Map user
            $userId = null;
            if ($legacyActivity->user_id && isset($this->userMap[$legacyActivity->user_id])) {
                $userId = $this->userMap[$legacyActivity->user_id];
            }

            // Create status history for status changes
            if ($legacyActivity->is_status) {
                StatusHistory::create([
                    'trackable_type' => Transaction::class,
                    'trackable_id' => $newTxnId,
                    'user_id' => $userId,
                    'from_status' => null, // Not tracked in legacy
                    'to_status' => $legacyActivity->status,
                    'notes' => $legacyActivity->notes,
                    'created_at' => $legacyActivity->created_at,
                    'updated_at' => $legacyActivity->updated_at,
                ]);
            }

            // Also create activity log entry
            ActivityLog::create([
                'store_id' => $this->newStore->id,
                'user_id' => $userId,
                'activity_slug' => $legacyActivity->is_status ? 'transactions.status_changed' : 'transactions.updated',
                'subject_type' => Transaction::class,
                'subject_id' => $newTxnId,
                'causer_type' => $userId ? User::class : null,
                'causer_id' => $userId,
                'properties' => [
                    'status' => $legacyActivity->status,
                    'notes' => $legacyActivity->notes,
                    'offer' => $legacyActivity->offer,
                    'is_from_admin' => $legacyActivity->is_from_admin,
                ],
                'description' => $legacyActivity->name ?? $legacyActivity->notes ?? 'Status changed',
                'created_at' => $legacyActivity->created_at,
                'updated_at' => $legacyActivity->updated_at,
            ]);
        }
    }

    protected function deriveStatusString(object $legacyTxn, ?string $currentStatusName = null): string
    {
        // Use the current status from status_updates if available
        if ($currentStatusName) {
            $statusName = strtolower($currentStatusName);

            // Map common status names to slugs
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
            ];

            foreach ($statusMappings as $pattern => $slug) {
                if (str_contains($statusName, $pattern)) {
                    return $slug;
                }
            }
        }

        // Fallback to legacy flags
        if ($legacyTxn->is_accepted) {
            return 'offer_accepted';
        }
        if ($legacyTxn->is_declined) {
            return 'offer_declined';
        }
        if ($legacyTxn->is_rejected) {
            return 'cancelled';
        }

        return 'pending';
    }

    protected function mapStatusNameToId(?string $statusName, ?int $defaultStatusId): ?int
    {
        if (! $statusName) {
            return $defaultStatusId;
        }

        $statusName = strtolower($statusName);

        // Map status names to slugs
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

        $slug = null;
        foreach ($statusMappings as $pattern => $mappedSlug) {
            if (str_contains($statusName, $pattern)) {
                $slug = $mappedSlug;
                break;
            }
        }

        if (! $slug) {
            return $defaultStatusId;
        }

        // Find the status by slug for this store
        $status = Status::where('store_id', $this->newStore->id)
            ->where('entity_type', 'transaction')
            ->where('slug', $slug)
            ->first();

        return $status?->id ?? $defaultStatusId;
    }

    protected function fixDstTimestamp(?string $timestamp): ?string
    {
        if (! $timestamp) {
            return null;
        }

        // Try to parse the timestamp
        try {
            $date = new \DateTime($timestamp, new \DateTimeZone('America/New_York'));

            return $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            // If parsing fails (likely DST gap), add an hour to skip over the invalid time
            $parts = explode(' ', $timestamp);
            if (count($parts) === 2) {
                $timeParts = explode(':', $parts[1]);
                if (count($timeParts) >= 2 && (int) $timeParts[0] === 2) {
                    // Move 2:xx to 3:xx during DST gap
                    $timeParts[0] = '03';

                    return $parts[0].' '.implode(':', $timeParts);
                }
            }

            return $timestamp;
        }
    }

    protected function buildItemDescription(object $legacyItem): string
    {
        $parts = [];

        if ($legacyItem->description) {
            $parts[] = $legacyItem->description;
        } elseif ($legacyItem->product_name) {
            $parts[] = $legacyItem->product_name;
        } elseif ($legacyItem->title) {
            $parts[] = $legacyItem->title;
        } elseif ($legacyItem->item) {
            $parts[] = $legacyItem->item;
        }

        if ($legacyItem->precious_metal) {
            $parts[] = "[{$legacyItem->precious_metal}]";
        }

        if ($legacyItem->dwt) {
            $parts[] = "{$legacyItem->dwt} dwt";
        }

        if ($legacyItem->category && ! str_contains(implode(' ', $parts), $legacyItem->category)) {
            $parts[] = "({$legacyItem->category})";
        }

        return implode(' ', $parts) ?: 'Item';
    }

    /**
     * Get payment method and details from legacy transaction_payment_addresses table.
     *
     * @return array{method: string|null, details: array|null}
     */
    protected function getTransactionPaymentMethod(int $legacyTransactionId, int $legacyStoreId): array
    {
        // Get the first (primary) payment address for this transaction
        $paymentAddress = DB::connection('legacy')
            ->table('transaction_payment_addresses')
            ->where('transaction_id', $legacyTransactionId)
            ->first();

        if (! $paymentAddress) {
            return ['method' => null, 'details' => null];
        }

        // Map payment_type_id to payment method string
        $paymentTypeMap = [
            1 => 'check',
            2 => 'paypal',
            3 => 'ach',
            4 => 'venmo',
            5 => 'wire_transfer',
            6 => 'bank_transfer',
            7 => 'cash_app',
        ];

        $method = $paymentTypeMap[$paymentAddress->payment_type_id] ?? 'cash';

        // Build payment details based on the method
        $details = [];

        switch ($method) {
            case 'check':
                if ($paymentAddress->check_name) {
                    $details['check_name'] = $paymentAddress->check_name;
                }
                if ($paymentAddress->check_address) {
                    $details['check_address'] = $paymentAddress->check_address;
                }
                if ($paymentAddress->check_address_2) {
                    $details['check_address_2'] = $paymentAddress->check_address_2;
                }
                if ($paymentAddress->check_city) {
                    $details['check_city'] = $paymentAddress->check_city;
                }
                if ($paymentAddress->check_state_id) {
                    $details['check_state'] = $paymentAddress->check_state_id;
                }
                if ($paymentAddress->check_zip) {
                    $details['check_zip'] = $paymentAddress->check_zip;
                }
                break;

            case 'paypal':
                if ($paymentAddress->paypal_address) {
                    $details['paypal_email'] = $paymentAddress->paypal_address;
                }
                break;

            case 'ach':
            case 'wire_transfer':
            case 'bank_transfer':
                if ($paymentAddress->bank_name) {
                    $details['bank_name'] = $paymentAddress->bank_name;
                }
                if ($paymentAddress->routing_number) {
                    $details['routing_number'] = $paymentAddress->routing_number;
                }
                if ($paymentAddress->account_number) {
                    $details['account_number'] = $paymentAddress->account_number;
                }
                if ($paymentAddress->account_name) {
                    $details['account_name'] = $paymentAddress->account_name;
                }
                if ($paymentAddress->account_type) {
                    $details['account_type'] = $paymentAddress->account_type;
                }
                if ($paymentAddress->bank_address) {
                    $details['bank_address'] = $paymentAddress->bank_address;
                }
                if ($paymentAddress->bank_address_city) {
                    $details['bank_city'] = $paymentAddress->bank_address_city;
                }
                if ($paymentAddress->bank_address_state_id) {
                    $details['bank_state'] = $paymentAddress->bank_address_state_id;
                }
                if ($paymentAddress->bank_address_zip) {
                    $details['bank_zip'] = $paymentAddress->bank_address_zip;
                }
                break;

            case 'venmo':
                if ($paymentAddress->venmo_address) {
                    $details['venmo_handle'] = $paymentAddress->venmo_address;
                }
                break;
        }

        // Add amount if specified
        if ($paymentAddress->amount) {
            $details['amount'] = $paymentAddress->amount;
        }

        return [
            'method' => $method,
            'details' => ! empty($details) ? $details : null,
        ];
    }

    protected function migrateSmsMessages(int $legacyStoreId, bool $isDryRun): void
    {
        $this->info('Migrating SMS messages...');

        // Check if legacy sms table exists
        if (! DB::connection('legacy')->getSchemaBuilder()->hasTable('sms')) {
            $this->line('  Legacy sms table not found, skipping');

            return;
        }

        // Get SMS messages for transactions (using polymorphic smsable_type/smsable_id)
        $legacySms = DB::connection('legacy')
            ->table('sms')
            ->where('store_id', $legacyStoreId)
            ->where('smsable_type', 'App\\Models\\Transaction')
            ->get();

        if ($isDryRun) {
            $this->line("  Would migrate {$legacySms->count()} SMS messages");

            return;
        }

        $count = 0;
        foreach ($legacySms as $legacyMsg) {
            // Map the transaction ID (they're preserved)
            $transactionId = $legacyMsg->smsable_id;
            if (! Transaction::where('id', $transactionId)->where('store_id', $this->newStore->id)->exists()) {
                continue;
            }

            NotificationLog::create([
                'store_id' => $this->newStore->id,
                'notifiable_type' => Transaction::class,
                'notifiable_id' => $transactionId,
                'channel' => NotificationChannel::TYPE_SMS,
                'recipient' => $legacyMsg->to,
                'content' => $legacyMsg->message,
                'status' => 'sent',
                'sent_at' => $legacyMsg->created_at,
                'created_at' => $legacyMsg->created_at,
                'updated_at' => $legacyMsg->updated_at,
            ]);
            $count++;
        }

        $this->line("  Migrated {$count} SMS messages");
    }

    protected function migrateTransactionNotes(int $legacyStoreId, bool $isDryRun): void
    {
        $this->info('Migrating transaction notes...');

        // Check if legacy transaction_notes table exists
        if (! DB::connection('legacy')->getSchemaBuilder()->hasTable('transaction_notes')) {
            $this->line('  Legacy transaction_notes table not found, skipping');

            return;
        }

        // Get notes joined with transactions
        $legacyNotes = DB::connection('legacy')
            ->table('transaction_notes')
            ->join('transactions', 'transaction_notes.transaction_id', '=', 'transactions.id')
            ->where('transactions.store_id', $legacyStoreId)
            ->select('transaction_notes.*')
            ->get();

        if ($isDryRun) {
            $this->line("  Would migrate {$legacyNotes->count()} transaction notes");

            return;
        }

        // Get the store owner's user ID for notes without a user
        $storeOwner = $this->newStore->storeUsers()->where('is_owner', true)->first();
        $defaultUserId = $storeOwner?->user_id ?? array_values($this->userMap)[0] ?? null;

        if (! $defaultUserId) {
            $this->line('  No user found to assign notes to, skipping');

            return;
        }

        $count = 0;
        foreach ($legacyNotes as $legacyNote) {
            // Skip empty notes
            if (empty($legacyNote->notes)) {
                continue;
            }

            // Map the transaction ID (they're preserved)
            $transactionId = $legacyNote->transaction_id;
            if (! Transaction::where('id', $transactionId)->where('store_id', $this->newStore->id)->exists()) {
                continue;
            }

            Note::create([
                'store_id' => $this->newStore->id,
                'notable_type' => Transaction::class,
                'notable_id' => $transactionId,
                'user_id' => $defaultUserId,
                'content' => $legacyNote->notes,
                'created_at' => $legacyNote->created_at,
                'updated_at' => $legacyNote->updated_at,
            ]);
            $count++;
        }

        $this->line("  Migrated {$count} transaction notes");
    }

    protected function migrateShippingLabels(int $legacyStoreId, bool $isDryRun): void
    {
        $this->info('Migrating shipping labels...');

        // Check if legacy shipping_labels table exists
        if (! DB::connection('legacy')->getSchemaBuilder()->hasTable('shipping_labels')) {
            $this->line('  Legacy shipping_labels table not found, skipping');

            return;
        }

        // Get shipping labels for transactions using polymorphic relationship
        $legacyLabels = DB::connection('legacy')
            ->table('shipping_labels')
            ->where('store_id', $legacyStoreId)
            ->where('shippable_type', 'App\\Models\\Transaction')
            ->whereNull('deleted_at')
            ->get();

        if ($isDryRun) {
            $this->line("  Would migrate {$legacyLabels->count()} shipping labels");

            return;
        }

        $count = 0;
        foreach ($legacyLabels as $legacyLabel) {
            // Map the transaction ID (they're preserved)
            $transactionId = $legacyLabel->shippable_id;
            if (! Transaction::where('id', $transactionId)->where('store_id', $this->newStore->id)->exists()) {
                continue;
            }

            // Map type: use is_return flag
            $type = $legacyLabel->is_return
                ? ShippingLabel::TYPE_RETURN
                : ShippingLabel::TYPE_OUTBOUND;

            // Map carrier to constants
            $carrier = strtoupper($legacyLabel->carrier ?? 'FEDEX');

            // Map status
            $status = $this->mapShippingLabelStatus($legacyLabel->status ?? 'created');

            ShippingLabel::create([
                'shippable_type' => Transaction::class,
                'shippable_id' => $transactionId,
                'type' => $type,
                'carrier' => $carrier,
                'service_type' => $legacyLabel->service_type ?? $legacyLabel->service ?? null,
                'tracking_number' => $legacyLabel->tracking_number,
                'tracking_url' => $legacyLabel->tracking_url,
                'label_url' => $legacyLabel->label_url ?? $legacyLabel->label_image_url,
                'zpl_data' => $legacyLabel->zpl_data ?? $legacyLabel->zpl,
                'shipping_cost' => $legacyLabel->cost ?? $legacyLabel->shipping_cost ?? 0,
                'status' => $status,
                'created_at' => $legacyLabel->created_at,
                'updated_at' => $legacyLabel->updated_at,
            ]);
            $count++;
        }

        $this->line("  Migrated {$count} shipping labels");
    }

    protected function mapShippingLabelStatus(string $legacyStatus): string
    {
        return match (strtolower($legacyStatus)) {
            'delivered' => ShippingLabel::STATUS_DELIVERED,
            'in_transit', 'intransit', 'transit' => ShippingLabel::STATUS_IN_TRANSIT,
            'voided', 'cancelled', 'canceled' => ShippingLabel::STATUS_VOIDED,
            'error', 'failed' => ShippingLabel::STATUS_ERROR,
            default => ShippingLabel::STATUS_CREATED,
        };
    }

    protected function migrateStatusHistory(int $legacyStoreId, bool $isDryRun): void
    {
        $this->info('Migrating status history...');

        // Get status updates for transactions from legacy
        $legacyUpdates = DB::connection('legacy')
            ->table('status_updates')
            ->where('store_id', $legacyStoreId)
            ->where('updateable_type', 'App\\Models\\Transaction')
            ->orderBy('updateable_id')
            ->orderBy('created_at')
            ->get();

        if ($isDryRun) {
            $this->line("  Would migrate {$legacyUpdates->count()} status history entries");

            return;
        }

        $count = 0;
        foreach ($legacyUpdates as $legacyUpdate) {
            // Check if transaction exists in new system
            $transactionId = $legacyUpdate->updateable_id;
            if (! Transaction::where('id', $transactionId)->where('store_id', $this->newStore->id)->exists()) {
                continue;
            }

            // Map user
            $userId = null;
            if ($legacyUpdate->user_id && isset($this->userMap[$legacyUpdate->user_id])) {
                $userId = $this->userMap[$legacyUpdate->user_id];
            }

            // Map status names to slugs for from/to
            $toStatus = $this->mapStatusNameToSlug($legacyUpdate->current_status);

            // Skip if current_status is empty
            if (! $toStatus || empty($legacyUpdate->current_status)) {
                continue;
            }

            $fromStatus = $this->mapStatusNameToSlug($legacyUpdate->previous_status) ?? 'pending';

            StatusHistory::create([
                'trackable_type' => Transaction::class,
                'trackable_id' => $transactionId,
                'user_id' => $userId,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'notes' => null,
                'created_at' => $legacyUpdate->created_at,
                'updated_at' => $legacyUpdate->updated_at ?? $legacyUpdate->created_at,
            ]);
            $count++;
        }

        $this->line("  Migrated {$count} status history entries");
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

        return $statusName; // Return as-is if no mapping found
    }

    protected function cleanupMigratedData(int $legacyStoreId): void
    {
        $this->warn('Cleaning up previously migrated data...');

        // Find the store that was migrated
        $legacyStore = DB::connection('legacy')
            ->table('stores')
            ->where('id', $legacyStoreId)
            ->first();

        if (! $legacyStore) {
            return;
        }

        $store = Store::where('name', $legacyStore->name)->first();
        if (! $store) {
            return;
        }

        // Delete in order of dependencies (force delete to handle soft deletes)
        ActivityLog::where('store_id', $store->id)->delete();
        StatusHistory::whereHasMorph('trackable', [Transaction::class], fn ($q) => $q->where('store_id', $store->id))->delete();
        TransactionItem::whereHas('transaction', fn ($q) => $q->where('store_id', $store->id))->forceDelete();
        Transaction::where('store_id', $store->id)->forceDelete();
        Customer::where('store_id', $store->id)->forceDelete();

        // Clean up product templates
        $templateIds = ProductTemplate::where('store_id', $store->id)->pluck('id');
        ProductTemplateFieldOption::whereIn('product_template_field_id',
            ProductTemplateField::whereIn('product_template_id', $templateIds)->pluck('id')
        )->delete();
        ProductTemplateField::whereIn('product_template_id', $templateIds)->delete();
        ProductTemplate::where('store_id', $store->id)->forceDelete();

        Category::where('store_id', $store->id)->forceDelete();
        Status::where('store_id', $store->id)->forceDelete();
        Warehouse::where('store_id', $store->id)->forceDelete();
        StoreUser::where('store_id', $store->id)->forceDelete();
        Role::where('store_id', $store->id)->forceDelete();
        $store->forceDelete();

        $this->line('  Cleanup complete');
    }

    /**
     * Migrate addresses from the legacy addresses table.
     * Handles polymorphic addresses for stores, customers, and transactions.
     */
    protected function migrateAddresses(int $legacyStoreId, bool $isDryRun): void
    {
        $this->info('Migrating addresses...');

        // Get legacy addresses for this store
        $legacyAddresses = DB::connection('legacy')
            ->table('addresses')
            ->where('store_id', $legacyStoreId)
            ->whereNull('deleted_at')
            ->get();

        $count = 0;
        $skipped = 0;

        // Map legacy addressable types to new model classes
        $typeMap = [
            'App\\Models\\Store' => Store::class,
            'App\\Store' => Store::class,
            'App\\Models\\Customer' => Customer::class,
            'App\\Customer' => Customer::class,
            'App\\Models\\Transaction' => Transaction::class,
            'App\\Transaction' => Transaction::class,
        ];

        foreach ($legacyAddresses as $legacyAddress) {
            $addressableType = $typeMap[$legacyAddress->addressable_type] ?? null;
            $addressableId = null;

            // Map the addressable_id to the new ID
            if ($addressableType === Store::class) {
                $addressableId = $this->newStore?->id;
            } elseif ($addressableType === Customer::class) {
                $addressableId = $this->customerMap[$legacyAddress->addressable_id] ?? null;
            } elseif ($addressableType === Transaction::class) {
                $addressableId = $this->transactionMap[$legacyAddress->addressable_id] ?? null;
            }

            // Skip if we couldn't map the addressable
            if (! $addressableType || ! $addressableId) {
                $skipped++;

                continue;
            }

            // Check if address already exists
            $existingAddress = Address::where('store_id', $this->newStore->id)
                ->where('addressable_type', $addressableType)
                ->where('addressable_id', $addressableId)
                ->where('address', $legacyAddress->address)
                ->first();

            if ($existingAddress) {
                $this->addressMap[$legacyAddress->id] = $existingAddress->id;
                $skipped++;

                continue;
            }

            if ($isDryRun) {
                $this->line("  Would create address: {$legacyAddress->address}, {$legacyAddress->city}");
                $count++;

                continue;
            }

            $newAddress = Address::create([
                'store_id' => $this->newStore->id,
                'addressable_type' => $addressableType,
                'addressable_id' => $addressableId,
                'first_name' => $legacyAddress->first_name,
                'last_name' => $legacyAddress->last_name,
                'company' => $legacyAddress->company ?? $legacyAddress->company_name,
                'nickname' => $legacyAddress->nickname,
                'address' => $legacyAddress->address,
                'address2' => $legacyAddress->address2,
                'city' => $legacyAddress->city,
                'state_id' => $legacyAddress->state_id,
                'country_id' => $legacyAddress->country_id,
                'zip' => $legacyAddress->zip ?? $legacyAddress->postal_code,
                'phone' => $legacyAddress->phone,
                'extension' => $legacyAddress->extension,
                'is_default' => $legacyAddress->is_default ?? false,
                'is_shipping' => $legacyAddress->is_shipping ?? true,
                'is_billing' => $legacyAddress->is_billing ?? false,
                'is_verified' => $legacyAddress->is_verified ?? false,
                'type' => $legacyAddress->type,
                'latitude' => $legacyAddress->latitude,
                'longitude' => $legacyAddress->longitude,
                'location_type' => $legacyAddress->location_type,
                'created_at' => $legacyAddress->created_at,
                'updated_at' => $legacyAddress->updated_at,
            ]);

            $this->addressMap[$legacyAddress->id] = $newAddress->id;

            // If this is a transaction address marked as shipping, link it
            if ($addressableType === Transaction::class && ($legacyAddress->is_shipping ?? true)) {
                Transaction::where('id', $addressableId)->update([
                    'shipping_address_id' => $newAddress->id,
                ]);
            }

            $count++;
        }

        $this->line("  Migrated {$count} addresses, skipped {$skipped}");
    }

    protected function displaySummary(): void
    {
        $this->newLine();
        $this->info('=== Migration Summary ===');
        $this->line('Store: '.($this->newStore?->name ?? 'N/A').' (ID: '.($this->newStore?->id ?? 'N/A').')');
        $this->line('Users mapped: '.count($this->userMap));
        $this->line('Customers mapped: '.count($this->customerMap));
        $this->line('Addresses mapped: '.count($this->addressMap));
        $this->line('Categories mapped: '.count($this->categoryMap));
        $this->line('Templates mapped: '.count($this->templateMap));
        $this->line('Statuses mapped: '.count($this->statusMap));

        if ($this->newStore) {
            $txnCount = Transaction::where('store_id', $this->newStore->id)->count();
            $customerCount = Customer::where('store_id', $this->newStore->id)->count();
            $categoryCount = Category::where('store_id', $this->newStore->id)->count();
            $templateCount = ProductTemplate::where('store_id', $this->newStore->id)->count();
            $this->line("Total transactions in new store: {$txnCount}");
            $this->line("Total customers in new store: {$customerCount}");
            $this->line("Total categories in new store: {$categoryCount}");
            $this->line("Total templates in new store: {$templateCount}");
        }
    }
}
