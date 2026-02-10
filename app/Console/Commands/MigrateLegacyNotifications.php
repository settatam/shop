<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use App\Models\NotificationTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MigrateLegacyNotifications extends Command
{
    protected $signature = 'migrate:legacy-notifications
        {--legacy-store= : The legacy store ID to migrate from}
        {--new-store= : The new store ID to migrate to}
        {--dry-run : Show what would be migrated without making changes}';

    protected $description = 'Migrate legacy store notifications to the new notification system';

    /**
     * Map of legacy notification names to activity slugs.
     * Some notifications are scheduled reports and don't map to activities.
     */
    protected array $activityMapping = [
        'Product Deleted' => Activity::PRODUCTS_DELETE,
        'Memo Deleted' => Activity::MEMOS_DELETE,
        'Item Sold' => Activity::ORDERS_CREATE,
        'Deleted Buys' => Activity::TRANSACTIONS_DELETE,
        'Deleted or cancelled Sales' => Activity::ORDERS_DELETE,
        'Items Bought' => Activity::TRANSACTIONS_CREATE,
        'Price Change' => Activity::PRODUCTS_PRICE_CHANGE,
    ];

    /**
     * Scheduled reports that don't map to activities - these need separate handling.
     */
    protected array $scheduledReports = [
        'Daily Sales Report',
        'Daily Buy Report',
        'Daily Memos Report',
        'Daily Repairs Report',
        'Weekly Inventory Report',
        'Monthly Inventory Report',
        'Monthly Buy Report',
        'Monthly Sales Report',
        'Items not reviewed',
    ];

    public function handle(): int
    {
        $legacyStoreId = $this->option('legacy-store');
        $newStoreId = $this->option('new-store');
        $dryRun = $this->option('dry-run');

        if (! $legacyStoreId || ! $newStoreId) {
            $this->error('Both --legacy-store and --new-store options are required.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info('DRY RUN - No changes will be made');
        }

        $this->info("Migrating notifications from legacy store {$legacyStoreId} to new store {$newStoreId}...");

        // Fetch legacy notifications
        $legacyNotifications = DB::connection('legacy')
            ->table('store_notifications')
            ->where('store_id', $legacyStoreId)
            ->whereNull('deleted_at')
            ->where('is_deactivated', false)
            ->get();

        $this->info("Found {$legacyNotifications->count()} active legacy notifications");

        $migratedCount = 0;
        $skippedCount = 0;
        $scheduledReportCount = 0;

        foreach ($legacyNotifications as $notification) {
            $this->newLine();
            $this->line("Processing: <comment>{$notification->name}</comment>");

            // Check if this is a scheduled report (needs separate handling)
            if (in_array($notification->name, $this->scheduledReports)) {
                $this->warn('  → Scheduled report - will migrate template only (scheduled jobs need separate setup)');
                $scheduledReportCount++;
                $this->migrateScheduledReport($notification, $newStoreId, $dryRun);

                continue;
            }

            // Check if we have an activity mapping
            $activity = $this->activityMapping[$notification->name] ?? null;

            if (! $activity) {
                $this->warn('  → No activity mapping found, skipping');
                $skippedCount++;

                continue;
            }

            $this->line("  → Mapping to activity: <info>{$activity}</info>");

            // Get the email message for this notification
            $emailMessage = DB::connection('legacy')
                ->table('store_notification_messages')
                ->where('store_notification_id', $notification->id)
                ->where('channel', 'email')
                ->first();

            if (! $emailMessage || empty($emailMessage->message)) {
                $this->warn('  → No email message found, skipping');
                $skippedCount++;

                continue;
            }

            // Parse recipients
            $recipients = $this->parseRecipients($notification->send_to);
            $this->line('  → Recipients: <info>'.implode(', ', array_map(fn ($r) => $r['value'] ?? $r['type'], $recipients)).'</info>');

            if ($dryRun) {
                $this->info('  → Would create template and subscription');
                $migratedCount++;

                continue;
            }

            // Create the template
            $template = $this->createTemplate(
                $newStoreId,
                $notification->name,
                $emailMessage->email_subject,
                $emailMessage->message,
                $this->getCategoryFromActivity($activity)
            );

            $this->line("  → Created template: <info>#{$template->id}</info>");

            // Create the subscription
            $subscription = $this->createSubscription(
                $newStoreId,
                $template->id,
                $activity,
                $notification->name,
                $recipients
            );

            $this->line("  → Created subscription: <info>#{$subscription->id}</info>");

            $migratedCount++;
        }

        $this->newLine();
        $this->info('Migration Summary:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Migrated (activity-based)', $migratedCount],
                ['Scheduled Reports (partial)', $scheduledReportCount],
                ['Skipped', $skippedCount],
                ['Total Processed', $legacyNotifications->count()],
            ]
        );

        if ($scheduledReportCount > 0) {
            $this->newLine();
            $this->warn('Note: Scheduled reports have been migrated as templates only.');
            $this->warn('You will need to set up the scheduled jobs separately to trigger these notifications.');
        }

        return self::SUCCESS;
    }

    /**
     * Parse the comma-separated recipients into the new format.
     */
    protected function parseRecipients(?string $sendTo): array
    {
        if (empty($sendTo)) {
            return [['type' => NotificationSubscription::RECIPIENT_OWNER]];
        }

        $emails = array_filter(array_map('trim', explode(',', $sendTo)));

        if (empty($emails)) {
            return [['type' => NotificationSubscription::RECIPIENT_OWNER]];
        }

        $recipients = [];
        foreach ($emails as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $recipients[] = [
                    'type' => NotificationSubscription::RECIPIENT_CUSTOM,
                    'value' => $email,
                ];
            }
        }

        return $recipients ?: [['type' => NotificationSubscription::RECIPIENT_OWNER]];
    }

    /**
     * Get the category from the activity slug.
     */
    protected function getCategoryFromActivity(string $activity): string
    {
        $definitions = Activity::getDefinitions();

        return $definitions[$activity]['category'] ?? 'general';
    }

    /**
     * Create a notification template.
     */
    protected function createTemplate(
        int $storeId,
        string $name,
        ?string $subject,
        string $content,
        string $category
    ): NotificationTemplate {
        $slug = Str::slug($name);

        return NotificationTemplate::firstOrCreate(
            [
                'store_id' => $storeId,
                'slug' => $slug,
                'channel' => NotificationChannel::TYPE_EMAIL,
            ],
            [
                'store_id' => $storeId,
                'name' => $name,
                'slug' => $slug,
                'channel' => NotificationChannel::TYPE_EMAIL,
                'subject' => $subject ?: $name,
                'content' => $content,
                'category' => $category,
                'is_system' => false,
                'is_enabled' => true,
            ]
        );
    }

    /**
     * Create a notification subscription.
     */
    protected function createSubscription(
        int $storeId,
        int $templateId,
        string $activity,
        string $name,
        array $recipients
    ): NotificationSubscription {
        return NotificationSubscription::firstOrCreate(
            [
                'store_id' => $storeId,
                'notification_template_id' => $templateId,
                'activity' => $activity,
            ],
            [
                'store_id' => $storeId,
                'notification_template_id' => $templateId,
                'activity' => $activity,
                'name' => $name,
                'description' => "Migrated from legacy notification: {$name}",
                'recipients' => $recipients,
                'schedule_type' => NotificationSubscription::SCHEDULE_IMMEDIATE,
                'is_enabled' => true,
            ]
        );
    }

    /**
     * Migrate a scheduled report (template only, no subscription).
     */
    protected function migrateScheduledReport(object $notification, int $newStoreId, bool $dryRun): void
    {
        $emailMessage = DB::connection('legacy')
            ->table('store_notification_messages')
            ->where('store_notification_id', $notification->id)
            ->where('channel', 'email')
            ->first();

        if (! $emailMessage || empty($emailMessage->message)) {
            $this->warn('  → No email message found for scheduled report');

            return;
        }

        if ($dryRun) {
            $this->info('  → Would create template for scheduled report');

            return;
        }

        $template = $this->createTemplate(
            $newStoreId,
            $notification->name,
            $emailMessage->email_subject,
            $emailMessage->message,
            'reports'
        );

        $this->line("  → Created template for scheduled report: <info>#{$template->id}</info>");

        // Store the recipients in the template's available_variables for reference
        $recipients = $this->parseRecipients($notification->send_to);
        $template->update([
            'available_variables' => [
                'legacy_recipients' => array_map(fn ($r) => $r['value'] ?? $r['type'], $recipients),
                'legacy_notification_id' => $notification->id,
            ],
        ]);
    }
}
