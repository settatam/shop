<?php

namespace App\Console\Commands;

use App\Models\ScheduledReport;
use App\Models\Store;
use Illuminate\Console\Command;

class SetupScheduledReports extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reports:setup-scheduled
                            {--store-id= : Store ID to create reports for (default: 3 for Rittenhouse)}
                            {--time=00:00 : Time to send reports (HH:MM format)}
                            {--timezone=America/New_York : Timezone for scheduling}
                            {--sales-only : Only create sales report}
                            {--buys-only : Only create buys report}
                            {--disabled : Create reports as disabled}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set up scheduled Daily Sales and Daily Buy reports for a store';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $storeId = $this->option('store-id') ?? 3;
        $time = $this->option('time');
        $timezone = $this->option('timezone');
        $salesOnly = $this->option('sales-only');
        $buysOnly = $this->option('buys-only');
        $disabled = $this->option('disabled');

        $store = Store::find($storeId);

        if (! $store) {
            $this->error("Store {$storeId} not found.");

            return self::FAILURE;
        }

        $this->info("Setting up scheduled reports for: {$store->name}");

        // Default recipients from legacy Rittenhouse config
        $salesRecipients = [
            'seth.atam@gmail.com',
            'jennifer@guesswho.com',
            'rlondon@guesswho.com',
            'rgreenstein@guesswho.com',
            'rotask23@gmail.com',
            'kyle@guesswho.com',
            'seriok547@gmail.com',
        ];

        $buyRecipients = [
            'seth.atam@gmail.com',
            'jennifer@guesswho.com',
            'rlondon@guesswho.com',
            'rgreenstein@guesswho.com',
            'rotask23@gmail.com',
            'dvarela@guesswho.com',
            'kyle@guesswho.com',
            'seriok547@gmail.com',
        ];

        $created = 0;

        // Create Daily Sales Report
        if (! $buysOnly) {
            $salesReport = ScheduledReport::updateOrCreate(
                [
                    'store_id' => $store->id,
                    'report_type' => 'legacy_daily_sales',
                ],
                [
                    'name' => 'REB - Daily Sales Report',
                    'recipients' => $salesRecipients,
                    'schedule_time' => $time,
                    'timezone' => $timezone,
                    'schedule_days' => null, // Daily
                    'is_enabled' => ! $disabled,
                ]
            );

            $status = $salesReport->wasRecentlyCreated ? 'Created' : 'Updated';
            $this->info("  {$status}: Daily Sales Report");
            $this->line('    Recipients: '.count($salesRecipients).' emails');
            $this->line("    Schedule: Daily at {$time} ({$timezone})");
            $this->line('    Enabled: '.($salesReport->is_enabled ? 'Yes' : 'No'));
            $created++;
        }

        // Create Daily Buy Report
        if (! $salesOnly) {
            $buyReport = ScheduledReport::updateOrCreate(
                [
                    'store_id' => $store->id,
                    'report_type' => 'legacy_daily_buy',
                ],
                [
                    'name' => 'REB - Daily Buy Report',
                    'recipients' => $buyRecipients,
                    'schedule_time' => $time,
                    'timezone' => $timezone,
                    'schedule_days' => null, // Daily
                    'is_enabled' => ! $disabled,
                ]
            );

            $status = $buyReport->wasRecentlyCreated ? 'Created' : 'Updated';
            $this->info("  {$status}: Daily Buy Report");
            $this->line('    Recipients: '.count($buyRecipients).' emails');
            $this->line("    Schedule: Daily at {$time} ({$timezone})");
            $this->line('    Enabled: '.($buyReport->is_enabled ? 'Yes' : 'No'));
            $created++;
        }

        $this->newLine();
        $this->info("Done! {$created} scheduled report(s) configured.");
        $this->line('');
        $this->line('To test these reports, run:');
        $this->line("  php artisan reports:send-scheduled --store-id={$store->id} --dry-run");
        $this->line('');
        $this->line('To view in UI, go to:');
        $this->line('  /settings/notifications/scheduled-reports');

        return self::SUCCESS;
    }
}
