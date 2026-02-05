<?php

namespace App\Console\Commands;

use App\Enums\Platform;
use App\Models\Order;
use App\Models\StoreMarketplace;
use App\Services\Webhooks\OrderImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class FetchShopifyOrder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:fetch-order
                            {order : The Shopify order number or order ID to fetch}
                            {--marketplace= : The store_marketplace_id to use}
                            {--store= : The store ID (will use first active Shopify marketplace)}
                            {--dry-run : Fetch and display the order without importing}
                            {--import : Import the order into the system}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch a specific order from Shopify by order number or ID';

    protected string $apiVersion = '2024-01';

    public function __construct(
        protected OrderImportService $orderImportService,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $orderIdentifier = $this->argument('order');
        $marketplaceId = $this->option('marketplace');
        $storeId = $this->option('store');
        $dryRun = $this->option('dry-run');
        $import = $this->option('import');

        // Find the marketplace connection
        $marketplace = $this->findMarketplace($marketplaceId, $storeId);

        if (! $marketplace) {
            $this->error('No Shopify marketplace connection found.');
            $this->info('Please specify --marketplace=<id> or --store=<id>');

            return self::FAILURE;
        }

        $this->info("Using Shopify connection: {$marketplace->name} ({$marketplace->shop_domain})");

        // Fetch the order from Shopify
        $shopifyOrder = $this->fetchShopifyOrder($marketplace, $orderIdentifier);

        if (! $shopifyOrder) {
            $this->error("Order '{$orderIdentifier}' not found on Shopify.");

            return self::FAILURE;
        }

        $this->displayOrderSummary($shopifyOrder);

        if ($dryRun) {
            $this->newLine();
            $this->comment('Dry run mode - order not imported.');
            $this->newLine();
            $this->info('Raw order data:');
            $this->line(json_encode($shopifyOrder, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if (! $import) {
            $this->newLine();
            $this->comment('Use --import to import this order into the system.');

            return self::SUCCESS;
        }

        // Import the order
        return $this->importOrder($shopifyOrder, $marketplace);
    }

    protected function findMarketplace(?string $marketplaceId, ?string $storeId): ?StoreMarketplace
    {
        if ($marketplaceId) {
            return StoreMarketplace::find($marketplaceId);
        }

        $query = StoreMarketplace::where('platform', Platform::Shopify)
            ->where('status', 'active');

        if ($storeId) {
            $query->where('store_id', $storeId);
        }

        return $query->first();
    }

    protected function fetchShopifyOrder(StoreMarketplace $marketplace, string $orderIdentifier): ?array
    {
        // Try fetching by order number first (most common use case)
        $this->info("Searching for order: {$orderIdentifier}...");

        // If it's numeric and looks like an order number (under 10 digits), search by order number
        // If it's a large number (Shopify ID is 13+ digits), search by ID
        if (is_numeric($orderIdentifier) && strlen($orderIdentifier) >= 13) {
            // Likely a Shopify order ID
            return $this->fetchOrderById($marketplace, $orderIdentifier);
        }

        // Try by order number first
        $order = $this->fetchOrderByNumber($marketplace, $orderIdentifier);

        if ($order) {
            return $order;
        }

        // Fall back to ID search
        if (is_numeric($orderIdentifier)) {
            return $this->fetchOrderById($marketplace, $orderIdentifier);
        }

        return null;
    }

    protected function fetchOrderByNumber(StoreMarketplace $marketplace, string $orderNumber): ?array
    {
        // Remove # prefix if present
        $orderNumber = ltrim($orderNumber, '#');

        $response = $this->shopifyRequest($marketplace, 'GET', 'orders.json', [
            'name' => $orderNumber,
            'status' => 'any',
        ]);

        if (isset($response['orders']) && count($response['orders']) > 0) {
            return $response['orders'][0];
        }

        // Try with # prefix
        $response = $this->shopifyRequest($marketplace, 'GET', 'orders.json', [
            'name' => '#'.$orderNumber,
            'status' => 'any',
        ]);

        if (isset($response['orders']) && count($response['orders']) > 0) {
            return $response['orders'][0];
        }

        return null;
    }

    protected function fetchOrderById(StoreMarketplace $marketplace, string $orderId): ?array
    {
        try {
            $response = $this->shopifyRequest($marketplace, 'GET', "orders/{$orderId}.json");

            return $response['order'] ?? null;
        } catch (\Exception $e) {
            $this->warn("Could not fetch order by ID: {$e->getMessage()}");

            return null;
        }
    }

    protected function shopifyRequest(
        StoreMarketplace $connection,
        string $method,
        string $endpoint,
        array $params = []
    ): array {
        $url = "https://{$connection->shop_domain}/admin/api/{$this->apiVersion}/{$endpoint}";

        $request = Http::withHeaders([
            'X-Shopify-Access-Token' => $connection->access_token,
            'Content-Type' => 'application/json',
        ]);

        $response = match (strtoupper($method)) {
            'GET' => $request->get($url, $params),
            'POST' => $request->post($url, $params),
            'PUT' => $request->put($url, $params),
            'DELETE' => $request->delete($url),
            default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
        };

        if ($response->failed()) {
            throw new \Exception("Shopify API error: {$response->status()} - {$response->body()}");
        }

        return $response->json() ?? [];
    }

    protected function displayOrderSummary(array $order): void
    {
        $this->newLine();
        $this->info('=== Shopify Order Found ===');
        $this->newLine();

        $this->table(
            ['Field', 'Value'],
            [
                ['Order ID', $order['id']],
                ['Order Number', $order['order_number'] ?? $order['name'] ?? 'N/A'],
                ['Name', $order['name'] ?? 'N/A'],
                ['Created', $order['created_at'] ?? 'N/A'],
                ['Status', $order['financial_status'] ?? 'N/A'],
                ['Fulfillment', $order['fulfillment_status'] ?? 'unfulfilled'],
                ['Subtotal', '$'.number_format((float) ($order['subtotal_price'] ?? 0), 2)],
                ['Tax', '$'.number_format((float) ($order['total_tax'] ?? 0), 2)],
                ['Shipping', '$'.number_format((float) ($order['total_shipping_price_set']['shop_money']['amount'] ?? 0), 2)],
                ['Discounts', '$'.number_format((float) ($order['total_discounts'] ?? 0), 2)],
                ['Total', '$'.number_format((float) ($order['total_price'] ?? 0), 2)],
                ['Currency', $order['currency'] ?? 'USD'],
            ]
        );

        // Customer info
        if ($customer = ($order['customer'] ?? null)) {
            $this->newLine();
            $this->info('Customer:');
            $this->line("  Name: {$customer['first_name']} {$customer['last_name']}");
            $this->line('  Email: '.($customer['email'] ?? $order['email'] ?? 'N/A'));
            $this->line('  Phone: '.($customer['phone'] ?? 'N/A'));
        }

        // Shipping address
        if ($address = ($order['shipping_address'] ?? null)) {
            $this->newLine();
            $this->info('Shipping Address:');
            $this->line("  {$address['name']}");
            $this->line("  {$address['address1']}");
            if (! empty($address['address2'])) {
                $this->line("  {$address['address2']}");
            }
            $this->line("  {$address['city']}, {$address['province_code']} {$address['zip']}");
            $this->line("  {$address['country']}");
        }

        // Line items
        $this->newLine();
        $this->info('Line Items:');
        $lineItems = [];
        foreach ($order['line_items'] ?? [] as $item) {
            $lineItems[] = [
                $item['sku'] ?? 'N/A',
                $item['title'] ?? 'Unknown',
                $item['quantity'],
                '$'.number_format((float) ($item['price'] ?? 0), 2),
                '$'.number_format((float) (($item['price'] ?? 0) * ($item['quantity'] ?? 1)), 2),
            ];
        }
        $this->table(['SKU', 'Title', 'Qty', 'Price', 'Total'], $lineItems);

        // Check if already imported
        $existingOrder = Order::where('external_marketplace_id', $order['id'])->first();
        if ($existingOrder) {
            $this->newLine();
            $this->warn("This order has already been imported as Order #{$existingOrder->id} ({$existingOrder->invoice_number})");
        }
    }

    protected function importOrder(array $shopifyOrder, StoreMarketplace $marketplace): int
    {
        $this->newLine();
        $this->info('Importing order...');

        try {
            $order = $this->orderImportService->importFromWebhookPayload(
                $shopifyOrder,
                $marketplace,
                Platform::Shopify
            );

            $this->newLine();
            $this->info('Order imported successfully!');
            $this->table(
                ['Field', 'Value'],
                [
                    ['Order ID', $order->id],
                    ['Invoice Number', $order->invoice_number ?? 'N/A'],
                    ['Status', $order->status],
                    ['Customer', $order->customer?->full_name ?? 'N/A'],
                    ['Total', '$'.number_format((float) $order->total, 2)],
                    ['Items', $order->items->count()],
                ]
            );

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to import order: {$e->getMessage()}");
            $this->newLine();
            $this->line($e->getTraceAsString());

            return self::FAILURE;
        }
    }
}
