<?php

namespace App\Console\Commands;

use App\Enums\Platform;
use App\Models\Store;
use App\Models\StoreMarketplace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SetupShopifyConnection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:setup
                            {--store= : The store ID to connect (defaults to first store)}
                            {--domain= : The Shopify store domain (e.g., mystore.myshopify.com)}
                            {--token= : The Shopify Admin API access token}
                            {--name= : Optional name for this connection}
                            {--list : List all existing Shopify connections}
                            {--test= : Test an existing connection by marketplace ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set up or manage Shopify store connections';

    protected string $apiVersion = '2024-01';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->listConnections();
        }

        if ($testId = $this->option('test')) {
            return $this->testConnection($testId);
        }

        return $this->setupConnection();
    }

    protected function listConnections(): int
    {
        $connections = StoreMarketplace::where('platform', Platform::Shopify)->get();

        if ($connections->isEmpty()) {
            $this->info('No Shopify connections found.');
            $this->newLine();
            $this->comment('To set up a connection, run:');
            $this->line('  php artisan shopify:setup --domain=mystore.myshopify.com --token=your_token');

            return self::SUCCESS;
        }

        $this->info('Shopify Connections:');
        $this->newLine();

        $rows = $connections->map(fn ($c) => [
            $c->id,
            $c->store_id,
            $c->name,
            $c->shop_domain,
            $c->status,
            $c->last_sync_at?->diffForHumans() ?? 'Never',
        ]);

        $this->table(
            ['ID', 'Store ID', 'Name', 'Domain', 'Status', 'Last Sync'],
            $rows
        );

        return self::SUCCESS;
    }

    protected function testConnection(string $marketplaceId): int
    {
        $connection = StoreMarketplace::find($marketplaceId);

        if (! $connection) {
            $this->error("Connection #{$marketplaceId} not found.");

            return self::FAILURE;
        }

        $this->info("Testing connection: {$connection->name} ({$connection->shop_domain})...");

        try {
            $response = $this->shopifyRequest($connection, 'GET', 'shop.json');

            if (isset($response['shop'])) {
                $shop = $response['shop'];
                $this->newLine();
                $this->info('Connection successful!');
                $this->table(
                    ['Field', 'Value'],
                    [
                        ['Shop Name', $shop['name']],
                        ['Email', $shop['email']],
                        ['Domain', $shop['domain']],
                        ['Plan', $shop['plan_display_name'] ?? 'N/A'],
                        ['Currency', $shop['currency']],
                        ['Timezone', $shop['iana_timezone']],
                    ]
                );

                // Update status to active
                $connection->update([
                    'status' => 'active',
                    'last_error' => null,
                ]);

                return self::SUCCESS;
            }

            $this->error('Unexpected response from Shopify.');

            return self::FAILURE;
        } catch (\Exception $e) {
            $this->error("Connection failed: {$e->getMessage()}");
            $connection->update([
                'status' => 'error',
                'last_error' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }
    }

    protected function setupConnection(): int
    {
        $storeId = $this->option('store');
        $domain = $this->option('domain');
        $token = $this->option('token');
        $name = $this->option('name');

        // Get store
        if ($storeId) {
            $store = Store::find($storeId);
        } else {
            $store = Store::first();
        }

        if (! $store) {
            $this->error('No store found. Please create a store first or specify --store=<id>.');

            return self::FAILURE;
        }

        // Get domain interactively if not provided
        if (! $domain) {
            $domain = $this->ask('Enter Shopify store domain (e.g., mystore.myshopify.com)');
        }

        if (! $domain) {
            $this->error('Domain is required.');

            return self::FAILURE;
        }

        // Normalize domain
        $domain = $this->normalizeDomain($domain);

        // Get token interactively if not provided
        if (! $token) {
            $token = $this->secret('Enter Shopify Admin API access token');
        }

        if (! $token) {
            $this->error('Access token is required.');

            return self::FAILURE;
        }

        // Set default name
        if (! $name) {
            $name = str_replace('.myshopify.com', '', $domain);
        }

        $this->info("Setting up Shopify connection for: {$domain}");

        // Validate credentials
        $this->info('Validating credentials...');

        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $token,
                'Content-Type' => 'application/json',
            ])->get("https://{$domain}/admin/api/{$this->apiVersion}/shop.json");

            if ($response->failed()) {
                $this->error('Invalid credentials. Could not connect to Shopify.');
                $this->line("Response: {$response->body()}");

                return self::FAILURE;
            }

            $shop = $response->json()['shop'] ?? null;

            if (! $shop) {
                $this->error('Invalid response from Shopify.');

                return self::FAILURE;
            }

            $this->info('Credentials validated successfully!');
            $this->newLine();

            // Create or update connection
            $connection = StoreMarketplace::updateOrCreate(
                [
                    'store_id' => $store->id,
                    'platform' => Platform::Shopify,
                    'shop_domain' => $domain,
                ],
                [
                    'name' => $name,
                    'access_token' => $token,
                    'external_store_id' => (string) $shop['id'],
                    'status' => 'active',
                    'settings' => [
                        'shop_name' => $shop['name'],
                        'email' => $shop['email'],
                        'currency' => $shop['currency'],
                        'timezone' => $shop['iana_timezone'],
                    ],
                ]
            );

            $this->info('Shopify connection created successfully!');
            $this->table(
                ['Field', 'Value'],
                [
                    ['Connection ID', $connection->id],
                    ['Store ID', $store->id],
                    ['Name', $connection->name],
                    ['Domain', $connection->shop_domain],
                    ['Status', $connection->status],
                ]
            );

            $this->newLine();
            $this->comment('You can now fetch orders using:');
            $this->line("  php artisan shopify:fetch-order <order_number> --marketplace={$connection->id}");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to connect: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    protected function normalizeDomain(string $domain): string
    {
        // Remove protocol
        $domain = preg_replace('#^https?://#', '', $domain);

        // Remove trailing slash
        $domain = rtrim($domain, '/');

        // Add .myshopify.com if not present
        if (! str_contains($domain, '.myshopify.com') && ! str_contains($domain, '.')) {
            $domain .= '.myshopify.com';
        }

        return $domain;
    }

    protected function shopifyRequest(StoreMarketplace $connection, string $method, string $endpoint): array
    {
        $url = "https://{$connection->shop_domain}/admin/api/{$this->apiVersion}/{$endpoint}";

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $connection->access_token,
            'Content-Type' => 'application/json',
        ])->get($url);

        if ($response->failed()) {
            throw new \Exception("Shopify API error: {$response->status()} - {$response->body()}");
        }

        return $response->json() ?? [];
    }
}
