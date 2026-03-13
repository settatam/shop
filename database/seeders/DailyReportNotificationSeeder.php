<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use App\Models\NotificationTemplate;
use Illuminate\Database\Seeder;

class DailyReportNotificationSeeder extends Seeder
{
    /**
     * The simple Twig template used by all daily reports.
     */
    protected string $reportTemplate = <<<'HTML'
<h2 style="margin-bottom: 20px;">{{ date }}</h2>
{{ report_html|raw }}
HTML;

    /**
     * Run the database seeds.
     *
     * Creates notification templates and subscriptions for:
     * 1. Daily Sales Report
     * 2. Daily Buy Report
     * 3. Items Not Reviewed Report
     * 4. Daily Memo Report
     * 5. Daily Repair Report
     */
    public function run(): void
    {
        $storeId = $this->command->ask('Enter store ID to seed daily reports for', 3);
        $storeId = (int) $storeId;

        $reports = [
            [
                'slug' => 'daily-sales-report',
                'name' => 'Daily Sales Report',
                'description' => 'Daily summary of sales orders',
                'subject' => '{{ date }}',
                'activity' => Activity::REPORTS_DAILY_SALES,
                'subscription_name' => 'Daily Sales Report',
                'subscription_description' => 'Sends a daily sales report summary',
            ],
            [
                'slug' => 'daily-buy-report',
                'name' => 'Daily Buy Report',
                'description' => 'Daily summary of buy transactions',
                'subject' => '{{ date }}',
                'activity' => Activity::REPORTS_DAILY_BUY,
                'subscription_name' => 'Daily Buy Report',
                'subscription_description' => 'Sends a daily buy transactions report summary',
            ],
            [
                'slug' => 'items-not-reviewed-report',
                'name' => 'Items Not Reviewed Report',
                'description' => 'Daily report of unreviewed buy transaction items',
                'subject' => '{{ date }}',
                'activity' => Activity::REPORTS_ITEMS_NOT_REVIEWED,
                'subscription_name' => 'Items Not Reviewed Report',
                'subscription_description' => 'Sends a daily report of items not yet reviewed',
            ],
            [
                'slug' => 'daily-memo-report',
                'name' => 'Daily Memo Report',
                'description' => 'Daily summary of consignment memo activity',
                'subject' => '{{ date }}',
                'activity' => Activity::REPORTS_DAILY_MEMO,
                'subscription_name' => 'Daily Memo Report',
                'subscription_description' => 'Sends a daily memo activity report summary',
            ],
            [
                'slug' => 'daily-repair-report',
                'name' => 'Daily Repair Report',
                'description' => 'Daily summary of repair activity',
                'subject' => '{{ date }}',
                'activity' => Activity::REPORTS_DAILY_REPAIR,
                'subscription_name' => 'Daily Repair Report',
                'subscription_description' => 'Sends a daily repair activity report summary',
            ],
        ];

        foreach ($reports as $report) {
            $this->createReportNotification($storeId, $report);
        }

        $this->command->info('Daily report notifications seeded successfully!');
    }

    /**
     * @param  array{slug: string, name: string, description: string, subject: string, activity: string, subscription_name: string, subscription_description: string}  $config
     */
    protected function createReportNotification(int $storeId, array $config): void
    {
        $template = NotificationTemplate::updateOrCreate(
            [
                'store_id' => $storeId,
                'slug' => $config['slug'],
            ],
            [
                'name' => $config['name'],
                'description' => $config['description'],
                'channel' => NotificationChannel::TYPE_EMAIL,
                'subject' => $config['subject'],
                'content' => $this->reportTemplate,
                'available_variables' => ['date', 'report_html', 'store'],
                'category' => 'reports',
                'is_enabled' => true,
            ]
        );

        NotificationSubscription::updateOrCreate(
            [
                'store_id' => $storeId,
                'activity' => $config['activity'],
            ],
            [
                'notification_template_id' => $template->id,
                'name' => $config['subscription_name'],
                'description' => $config['subscription_description'],
                'recipients' => [['type' => NotificationSubscription::RECIPIENT_OWNER]],
                'schedule_type' => NotificationSubscription::SCHEDULE_IMMEDIATE,
                'is_enabled' => true,
            ]
        );

        $this->command->info("Created: {$config['name']} for store {$storeId}");
    }
}
