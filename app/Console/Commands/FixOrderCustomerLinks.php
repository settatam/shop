<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixOrderCustomerLinks extends Command
{
    protected $signature = 'fix:order-customer-links
                            {--store-id=63 : Legacy store ID}
                            {--new-store-id=25 : New store ID}
                            {--dry-run : Show what would be fixed without making changes}
                            {--create-missing : Create missing customers from legacy data}';

    protected $description = 'Fix missing customer links on orders by matching with legacy data';

    protected array $customerMap = [];

    public function handle(): int
    {
        $legacyStoreId = (int) $this->option('store-id');
        $newStoreId = (int) $this->option('new-store-id');
        $isDryRun = $this->option('dry-run');
        $createMissing = $this->option('create-missing');

        $newStore = Store::find($newStoreId);
        if (! $newStore) {
            $this->error("Store {$newStoreId} not found");

            return 1;
        }

        $this->info("Fixing order customer links for store: {$newStore->name}");
        if ($isDryRun) {
            $this->warn('DRY RUN - No changes will be made');
        }

        // Build customer map: legacy_id -> new_id
        $this->buildCustomerMap($legacyStoreId, $newStoreId, $createMissing, $isDryRun);

        // Get orders with NULL customer_id
        $ordersToFix = Order::where('store_id', $newStoreId)
            ->whereNull('customer_id')
            ->get();

        $this->info("Found {$ordersToFix->count()} orders with NULL customer_id");

        $fixed = 0;
        $notFound = 0;
        $legacyHadNoCustomer = 0;

        foreach ($ordersToFix as $order) {
            // Find corresponding legacy order by matching total and created_at
            $legacyOrder = DB::connection('legacy')
                ->table('orders')
                ->where('store_id', $legacyStoreId)
                ->where('total', $order->total)
                ->where('created_at', $order->created_at)
                ->first();

            if (! $legacyOrder) {
                // Try matching by order_id
                if ($order->order_id) {
                    $legacyOrder = DB::connection('legacy')
                        ->table('orders')
                        ->where('store_id', $legacyStoreId)
                        ->where('order_id', $order->order_id)
                        ->first();
                }
            }

            if (! $legacyOrder) {
                $notFound++;

                continue;
            }

            if (! $legacyOrder->customer_id) {
                // Legacy had no customer - create unknown customer for this order
                if (! $isDryRun) {
                    $unknownCustomer = $this->getOrCreateUnknownCustomer($newStoreId);
                    $order->update(['customer_id' => $unknownCustomer]);
                    $this->line("  Fixed order #{$order->id}: linked to Unknown Customer (legacy had none)");
                }
                $legacyHadNoCustomer++;

                continue;
            }

            // Look up the customer in our map
            $newCustomerId = $this->customerMap[$legacyOrder->customer_id] ?? null;

            // If not in map, fetch from legacy and create
            if (! $newCustomerId && ! $isDryRun) {
                $newCustomerId = $this->createCustomerFromLegacy($legacyOrder->customer_id, $newStoreId);
            }

            if ($newCustomerId) {
                if (! $isDryRun) {
                    $order->update(['customer_id' => $newCustomerId]);
                }
                $fixed++;
                $this->line("  Fixed order #{$order->id}: linked to customer #{$newCustomerId}");
            } else {
                $this->warn("  Order #{$order->id}: Legacy customer #{$legacyOrder->customer_id} could not be created");
                $notFound++;
            }
        }

        $this->newLine();
        $this->info('=== Summary ===');
        $this->line("Fixed: {$fixed}");
        $this->line("Legacy had no customer: {$legacyHadNoCustomer}");
        $this->line("Could not match: {$notFound}");

        return 0;
    }

    protected function buildCustomerMap(int $legacyStoreId, int $newStoreId, bool $createMissing, bool $isDryRun): void
    {
        $this->info('Building customer map...');

        $legacyCustomers = DB::connection('legacy')
            ->table('customers')
            ->where('store_id', $legacyStoreId)
            ->get();

        $newCustomers = Customer::where('store_id', $newStoreId)->get();

        // Index by email
        $newCustomersByEmail = $newCustomers
            ->filter(fn ($c) => $c->email)
            ->keyBy(fn ($c) => strtolower($c->email));

        // Index by normalized name
        $newCustomersByName = $newCustomers
            ->keyBy(fn ($c) => $this->normalizeName($c->first_name, $c->last_name));

        $mappedByEmail = 0;
        $mappedByName = 0;
        $created = 0;
        $notMapped = 0;

        foreach ($legacyCustomers as $legacy) {
            // Try email match first
            if ($legacy->email && $newCustomersByEmail->has(strtolower($legacy->email))) {
                $this->customerMap[$legacy->id] = $newCustomersByEmail->get(strtolower($legacy->email))->id;
                $mappedByEmail++;

                continue;
            }

            // Try name match
            $normalizedName = $this->normalizeName($legacy->first_name, $legacy->last_name);
            if ($normalizedName && $newCustomersByName->has($normalizedName)) {
                $this->customerMap[$legacy->id] = $newCustomersByName->get($normalizedName)->id;
                $mappedByName++;

                continue;
            }

            // Create missing customer if requested
            if ($createMissing && ! $isDryRun) {
                $newCustomer = Customer::create([
                    'store_id' => $newStoreId,
                    'first_name' => $legacy->first_name,
                    'last_name' => $legacy->last_name,
                    'email' => $legacy->email,
                    'phone_number' => $legacy->phone_number,
                    'address' => $legacy->street_address,
                    'city' => $legacy->city,
                    'zip' => $legacy->zip,
                    'company_name' => $legacy->company_name,
                    'is_active' => true,
                    'created_at' => $legacy->created_at,
                    'updated_at' => $legacy->updated_at,
                ]);

                $this->customerMap[$legacy->id] = $newCustomer->id;
                $created++;

                // Add to name index for subsequent matches
                if ($normalizedName) {
                    $newCustomersByName[$normalizedName] = $newCustomer;
                }

                continue;
            }

            $notMapped++;
        }

        $this->line('  Mapped: '.count($this->customerMap)." ({$mappedByEmail} by email, {$mappedByName} by name, {$created} created, {$notMapped} not mapped)");
    }

    protected function normalizeName(?string $firstName, ?string $lastName): ?string
    {
        $name = trim(strtolower(($firstName ?? '').' '.($lastName ?? '')));

        return $name !== '' ? $name : null;
    }

    protected ?int $unknownCustomerId = null;

    /**
     * Get or create a generic "Unknown Customer" for orders with no customer data.
     */
    protected function getOrCreateUnknownCustomer(int $newStoreId): int
    {
        if ($this->unknownCustomerId) {
            return $this->unknownCustomerId;
        }

        // Check if one already exists
        $existing = Customer::where('store_id', $newStoreId)
            ->where('first_name', 'Walk-in')
            ->where('last_name', 'Customer')
            ->first();

        if ($existing) {
            $this->unknownCustomerId = $existing->id;

            return $existing->id;
        }

        // Create new
        $customer = Customer::create([
            'store_id' => $newStoreId,
            'first_name' => 'Walk-in',
            'last_name' => 'Customer',
            'is_active' => true,
        ]);

        $this->unknownCustomerId = $customer->id;

        return $customer->id;
    }

    /**
     * Fetch a customer from legacy DB by ID and create in new store.
     * If legacy customer doesn't exist, creates an "Unknown Customer" record.
     */
    protected function createCustomerFromLegacy(int $legacyCustomerId, int $newStoreId): ?int
    {
        // Fetch from legacy (any store - customer might be from different store)
        $legacy = DB::connection('legacy')
            ->table('customers')
            ->where('id', $legacyCustomerId)
            ->first();

        if ($legacy) {
            // Create the customer from legacy data
            $newCustomer = Customer::create([
                'store_id' => $newStoreId,
                'first_name' => $legacy->first_name ?: 'Unknown',
                'last_name' => $legacy->last_name ?: 'Customer',
                'email' => $legacy->email,
                'phone_number' => $legacy->phone_number,
                'address' => $legacy->street_address ?? null,
                'city' => $legacy->city,
                'zip' => $legacy->zip,
                'company_name' => $legacy->company_name,
                'is_active' => true,
                'created_at' => $legacy->created_at,
                'updated_at' => $legacy->updated_at,
            ]);
        } else {
            // Legacy customer doesn't exist - create unknown customer placeholder
            $newCustomer = Customer::create([
                'store_id' => $newStoreId,
                'first_name' => 'Unknown',
                'last_name' => "Customer (Legacy #{$legacyCustomerId})",
                'is_active' => true,
            ]);
        }

        // Cache for future lookups
        $this->customerMap[$legacyCustomerId] = $newCustomer->id;

        return $newCustomer->id;
    }
}
