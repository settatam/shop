<?php

namespace App\Console\Commands;

use App\Enums\Platform;
use App\Models\StoreMarketplace;
use App\Models\WebhookLog;
use App\Services\Queue\JobLogger;
use App\Services\Webhooks\OrderImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SimulateShopifyWebhookCommand extends Command
{
    protected $signature = 'webhook:simulate-shopify
                            {--store= : Store ID to use}
                            {--sku= : Product SKU to include in the order}';

    protected $description = 'Simulate a Shopify order webhook for testing';

    public function __construct(
        protected OrderImportService $orderImportService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $storeId = $this->option('store') ?? 4;
        $sku = $this->option('sku');

        // Find or create Shopify connection for the store
        $connection = StoreMarketplace::where('store_id', $storeId)
            ->where('platform', Platform::Shopify)
            ->first();

        if (! $connection) {
            $this->error("No Shopify connection found for store {$storeId}");

            return self::FAILURE;
        }

        $this->info("Using Shopify connection ID: {$connection->id} for store: {$connection->store->name}");

        // Start job logging
        $jobId = JobLogger::started(
            self::class,
            ['store_id' => $storeId, 'event' => 'orders/create', 'trigger_reason' => 'shopify_webhook_simulation'],
            $storeId
        );

        // Generate realistic Shopify order payload
        $externalOrderId = (string) random_int(5000000000, 5999999999);
        $orderNumber = random_int(1000, 9999);

        $payload = $this->generateShopifyPayload($externalOrderId, $orderNumber, $sku);

        // Create webhook log entry
        $webhookLog = WebhookLog::create([
            'store_marketplace_id' => $connection->id,
            'store_id' => $storeId,
            'platform' => Platform::Shopify,
            'event_type' => 'orders/create',
            'external_id' => $externalOrderId,
            'status' => WebhookLog::STATUS_PENDING,
            'headers' => [
                'x-shopify-topic' => 'orders/create',
                'x-shopify-shop-domain' => 'test-store.myshopify.com',
                'x-shopify-hmac-sha256' => 'simulated-signature',
            ],
            'payload' => $payload,
            'ip_address' => '127.0.0.1',
            'signature' => 'simulated-signature',
        ]);

        $this->info("Created WebhookLog ID: {$webhookLog->id}");
        $this->newLine();
        $this->info('Processing webhook...');

        try {
            $webhookLog->markAsProcessing();

            // Import the order
            $result = $this->orderImportService->importFromWebhookPayload(
                $payload,
                $connection,
                Platform::Shopify
            );

            $webhookLog->markAsCompleted([
                'order_id' => $result['order_id'] ?? null,
                'platform_order_id' => $result['platform_order_id'] ?? null,
                'status' => 'imported',
            ]);

            // Complete job log
            JobLogger::completed($jobId, [
                'webhook_log_id' => $webhookLog->id,
                'order_id' => $result['order_id'] ?? null,
                'platform_order_id' => $result['platform_order_id'] ?? null,
                'external_order_id' => $externalOrderId,
                'order_number' => $orderNumber,
            ]);

            $this->newLine();
            $this->info('Webhook processed successfully!');
            $this->table(
                ['Field', 'Value'],
                [
                    ['Webhook Log ID', $webhookLog->id],
                    ['External Order ID', $externalOrderId],
                    ['Order Number', "#{$orderNumber}"],
                    ['Platform Order ID', $result['platform_order_id'] ?? 'N/A'],
                    ['Internal Order ID', $result['order_id'] ?? 'N/A'],
                    ['Status', $result['status'] ?? 'imported'],
                ]
            );

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $webhookLog->markAsFailed($e->getMessage());
            JobLogger::failed($jobId, $e);

            $this->error("Webhook processing failed: {$e->getMessage()}");
            $this->line($e->getTraceAsString());

            return self::FAILURE;
        }
    }

    protected function generateShopifyPayload(string $orderId, int $orderNumber, ?string $sku = null): array
    {
        $lineItems = [];

        if ($sku) {
            $lineItems[] = [
                'id' => random_int(10000000000, 19999999999),
                'title' => "Product with SKU {$sku}",
                'sku' => $sku,
                'quantity' => 1,
                'price' => '99.99',
                'total_discount' => '0.00',
                'tax_lines' => [
                    ['price' => '8.00', 'rate' => 0.08, 'title' => 'Sales Tax'],
                ],
            ];
        } else {
            // Default line item
            $lineItems[] = [
                'id' => random_int(10000000000, 19999999999),
                'title' => 'Test Product from Shopify',
                'sku' => 'SHOPIFY-TEST-'.strtoupper(Str::random(4)),
                'quantity' => 2,
                'price' => '49.99',
                'total_discount' => '5.00',
                'tax_lines' => [
                    ['price' => '7.60', 'rate' => 0.08, 'title' => 'Sales Tax'],
                ],
            ];
        }

        return [
            'id' => $orderId,
            'order_number' => $orderNumber,
            'name' => "#{$orderNumber}",
            'email' => 'customer@example.com',
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'financial_status' => 'paid',
            'fulfillment_status' => null,
            'currency' => 'USD',
            'total_price' => '107.59',
            'subtotal_price' => '94.98',
            'total_tax' => '7.60',
            'total_discounts' => '5.00',
            'total_shipping_price_set' => [
                'shop_money' => ['amount' => '9.99', 'currency_code' => 'USD'],
            ],
            'customer' => [
                'id' => random_int(6000000000, 6999999999),
                'email' => 'customer@example.com',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'phone' => '+1234567890',
            ],
            'billing_address' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'address1' => '123 Main St',
                'address2' => 'Apt 4B',
                'city' => 'New York',
                'province' => 'NY',
                'province_code' => 'NY',
                'country' => 'United States',
                'country_code' => 'US',
                'zip' => '10001',
                'phone' => '+1234567890',
            ],
            'shipping_address' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'address1' => '123 Main St',
                'address2' => 'Apt 4B',
                'city' => 'New York',
                'province' => 'NY',
                'province_code' => 'NY',
                'country' => 'United States',
                'country_code' => 'US',
                'zip' => '10001',
                'phone' => '+1234567890',
            ],
            'line_items' => $lineItems,
            'shipping_lines' => [
                [
                    'title' => 'Standard Shipping',
                    'price' => '9.99',
                    'code' => 'standard',
                ],
            ],
            'note' => 'Simulated webhook order for testing',
            'tags' => 'test, simulated',
            'source_name' => 'web',
        ];
    }
}
