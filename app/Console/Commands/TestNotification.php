<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\NotificationSubscription;
use App\Models\NotificationTemplate;
use App\Models\Store;
use App\Services\Notifications\NotificationManager;
use Illuminate\Console\Command;

class TestNotification extends Command
{
    protected $signature = 'notification:test
        {--store= : The store ID to test with}
        {--activity= : The activity slug to test (e.g., products.price_change)}
        {--email= : Send test to this email address}
        {--template= : Template ID to test directly}
        {--list-activities : List all available activities}
        {--list-subscriptions : List all subscriptions for the store}';

    protected $description = 'Send a test notification to verify the notification system is working';

    public function handle(): int
    {
        if ($this->option('list-activities')) {
            return $this->listActivities();
        }

        $storeId = $this->option('store');

        if (! $storeId) {
            $this->error('--store option is required');

            return self::FAILURE;
        }

        $store = Store::with('owner')->find($storeId);

        if (! $store) {
            $this->error("Store {$storeId} not found");

            return self::FAILURE;
        }

        if ($this->option('list-subscriptions')) {
            return $this->listSubscriptions($store);
        }

        $email = $this->option('email');
        if (! $email) {
            $email = $store->owner?->email;
            if (! $email) {
                $this->error('No email provided and store owner has no email');

                return self::FAILURE;
            }
            $this->info("Using store owner email: {$email}");
        }

        // Test a specific template directly
        if ($templateId = $this->option('template')) {
            return $this->testTemplate($store, $templateId, $email);
        }

        // Test an activity-based notification
        if ($activity = $this->option('activity')) {
            return $this->testActivity($store, $activity, $email);
        }

        // No specific test - show available options
        $this->info('Available test options:');
        $this->line('  --activity=<slug>   Test a notification for an activity');
        $this->line('  --template=<id>     Test a specific template');
        $this->line('  --list-activities   List all activity slugs');
        $this->line('  --list-subscriptions List active subscriptions for the store');
        $this->newLine();
        $this->line('Example:');
        $this->line('  php artisan notification:test --store=25 --activity=products.price_change --email=test@example.com');

        return self::SUCCESS;
    }

    protected function listActivities(): int
    {
        $this->info('Available Activity Slugs:');
        $this->newLine();

        $grouped = Activity::getGroupedByCategory();

        foreach ($grouped as $category => $activities) {
            $this->line("<comment>{$category}</comment>");
            foreach ($activities as $slug => $definition) {
                $this->line("  {$slug} - {$definition['name']}");
            }
            $this->newLine();
        }

        return self::SUCCESS;
    }

    protected function listSubscriptions(Store $store): int
    {
        $subscriptions = NotificationSubscription::with('template')
            ->where('store_id', $store->id)
            ->where('is_enabled', true)
            ->get();

        if ($subscriptions->isEmpty()) {
            $this->warn('No active subscriptions found for this store');

            return self::SUCCESS;
        }

        $this->info("Active Subscriptions for Store #{$store->id}:");
        $this->newLine();

        $rows = $subscriptions->map(fn ($sub) => [
            $sub->id,
            $sub->activity,
            $sub->name,
            $sub->template?->name ?? 'N/A',
            count($sub->recipients ?? []),
        ]);

        $this->table(
            ['ID', 'Activity', 'Name', 'Template', 'Recipients'],
            $rows
        );

        return self::SUCCESS;
    }

    protected function testTemplate(Store $store, int $templateId, string $email): int
    {
        $template = NotificationTemplate::where('store_id', $store->id)
            ->find($templateId);

        if (! $template) {
            $this->error("Template {$templateId} not found for store {$store->id}");

            return self::FAILURE;
        }

        $this->info("Testing template: {$template->name}");

        $data = $this->getSampleData($store);

        $manager = new NotificationManager($store);

        try {
            $log = $manager->sendTemplate($template, $email, $data);

            $this->info('Test notification sent successfully!');
            $this->line("  Log ID: {$log->id}");
            $this->line("  Status: {$log->status}");
            $this->line("  Recipient: {$email}");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to send: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    protected function testActivity(Store $store, string $activity, string $email): int
    {
        // Check if activity exists
        $definitions = Activity::getDefinitions();
        if (! isset($definitions[$activity])) {
            $this->error("Unknown activity: {$activity}");
            $this->line('Use --list-activities to see available activities');

            return self::FAILURE;
        }

        // Check if there's a subscription for this activity
        $subscription = NotificationSubscription::with('template')
            ->where('store_id', $store->id)
            ->where('activity', $activity)
            ->where('is_enabled', true)
            ->first();

        if (! $subscription) {
            $this->warn("No subscription found for activity: {$activity}");
            $this->line('Creating a temporary test subscription...');

            // Check if there's a template we can use
            $template = NotificationTemplate::where('store_id', $store->id)
                ->where('is_enabled', true)
                ->first();

            if (! $template) {
                $this->error('No enabled templates found. Create a template first.');

                return self::FAILURE;
            }

            // Create a temporary subscription for testing
            $subscription = NotificationSubscription::create([
                'store_id' => $store->id,
                'notification_template_id' => $template->id,
                'activity' => $activity,
                'name' => "Test: {$activity}",
                'recipients' => [
                    ['type' => 'custom', 'value' => $email],
                ],
                'schedule_type' => NotificationSubscription::SCHEDULE_IMMEDIATE,
                'is_enabled' => true,
            ]);

            $this->info("Created temporary subscription #{$subscription->id} using template: {$template->name}");
        } else {
            $this->info("Found subscription: {$subscription->name}");

            // Temporarily override recipients for test
            $originalRecipients = $subscription->recipients;
            $subscription->recipients = [
                ['type' => 'custom', 'value' => $email],
            ];
        }

        $data = $this->getSampleData($store, $activity);

        $manager = new NotificationManager($store);

        try {
            $logs = $manager->sendSubscription($subscription, $data, null);

            if ($logs->isEmpty()) {
                $this->warn('No notifications were sent. Check that the template is enabled.');

                return self::FAILURE;
            }

            $this->info('Test notification sent successfully!');
            foreach ($logs as $log) {
                $this->line("  Log ID: {$log->id}");
                $this->line("  Status: {$log->status}");
                $this->line("  Recipient: {$log->recipient}");
            }

            // Restore original recipients if we modified them
            if (isset($originalRecipients)) {
                $subscription->recipients = $originalRecipients;
                $subscription->save();
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to send: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * Generate sample data for testing templates.
     */
    protected function getSampleData(Store $store, ?string $activity = null): array
    {
        $data = [
            'store' => $store->toArray(),
            'user' => [
                'id' => 1,
                'first_name' => 'Test',
                'last_name' => 'User',
                'full_name' => 'Test User',
                'email' => 'test@example.com',
            ],
            'timestamp' => now(),
            'date' => now()->format('F j, Y'),
        ];

        // Add activity-specific sample data
        if ($activity) {
            $data = array_merge($data, $this->getActivitySampleData($activity));
        }

        return $data;
    }

    /**
     * Get sample data specific to an activity type.
     */
    protected function getActivitySampleData(string $activity): array
    {
        return match (true) {
            str_starts_with($activity, 'products.') => [
                'product' => [
                    'id' => 12345,
                    'title' => 'Sample Product Title',
                    'sku' => 'TEST-SKU-001',
                    'price' => 199.99,
                    'wholesale_price' => 149.99,
                    'cost' => 99.99,
                ],
                'variant' => [
                    'id' => 1,
                    'sku' => 'TEST-SKU-001',
                    'price' => 199.99,
                    'wholesale_price' => 149.99,
                    'cost' => 99.99,
                ],
                'changes' => [
                    ['field' => 'price', 'previous' => 179.99, 'new' => 199.99],
                    ['field' => 'wholesale_price', 'previous' => 129.99, 'new' => 149.99],
                ],
                'old' => ['price' => 179.99, 'wholesale_price' => 129.99],
                'new' => ['price' => 199.99, 'wholesale_price' => 149.99],
            ],
            str_starts_with($activity, 'orders.') => [
                'order' => [
                    'id' => 1001,
                    'order_id' => 'ORD-1001',
                    'number' => 'ORD-1001',
                    'total' => 299.99,
                    'sub_total' => 279.99,
                    'shipping_cost' => 10.00,
                    'sales_tax' => 10.00,
                    'status' => 'pending',
                    'items' => [
                        ['title' => 'Sample Item 1', 'sku' => 'SKU-001', 'quantity' => 1, 'price' => 199.99],
                        ['title' => 'Sample Item 2', 'sku' => 'SKU-002', 'quantity' => 2, 'price' => 40.00],
                    ],
                    'payments' => [
                        ['mode' => 'Credit Card', 'status' => 'completed', 'total_paid' => 299.99],
                    ],
                    'customer' => ['full_name' => 'John Doe'],
                    'marketplace' => ['marketplace' => 'Shopify'],
                ],
                'customer' => [
                    'id' => 100,
                    'full_name' => 'John Doe',
                    'email' => 'john@example.com',
                ],
            ],
            str_starts_with($activity, 'transactions.') => [
                'transaction' => [
                    'id' => 5001,
                    'transaction_number' => 'TXN-5001',
                    'final_offer' => 500.00,
                    'status' => 'completed',
                    'items' => [
                        ['title' => 'Bought Item 1', 'quantity' => 1, 'buy_price' => 300.00],
                        ['title' => 'Bought Item 2', 'quantity' => 1, 'buy_price' => 200.00],
                    ],
                    'customer' => ['full_name' => 'Jane Smith'],
                ],
                'method' => 'Cash',
                'customer' => [
                    'id' => 101,
                    'full_name' => 'Jane Smith',
                    'email' => 'jane@example.com',
                ],
            ],
            str_starts_with($activity, 'memos.') => [
                'memo' => [
                    'id' => 2001,
                    'memo_id' => 'MEM-2001',
                    'status' => 'sent',
                    'total' => 1500.00,
                ],
                'memo_id' => 2001,
            ],
            default => [],
        };
    }
}
