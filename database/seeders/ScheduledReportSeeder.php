<?php

namespace Database\Seeders;

use App\Models\ScheduledReport;
use App\Models\Store;
use Illuminate\Database\Seeder;

class ScheduledReportSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Seeds scheduled reports for Rittenhouse Estate Buyers (store 3).
     * Recipients are imported from legacy StoreNotification table.
     */
    public function run(): void
    {
        // Rittenhouse Estate Buyers = Store 3 in new system (was store 63 in legacy)
        $store = Store::find(3);

        if (! $store) {
            $this->command->warn('Store 3 (Rittenhouse Estate Buyers) not found. Skipping scheduled report seeding.');

            return;
        }

        // Daily Sales Report recipients from legacy
        $salesRecipients = [
            'seth.atam@gmail.com',
            'jennifer@guesswho.com',
            'rlondon@guesswho.com',
            'rgreenstein@guesswho.com',
            'rotask23@gmail.com',
            'kyle@guesswho.com',
            'seriok547@gmail.com',
        ];

        // Daily Buy Report recipients from legacy (includes dvarela)
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

        // Create Daily Sales Report
        ScheduledReport::updateOrCreate(
            [
                'store_id' => $store->id,
                'report_type' => 'legacy_daily_sales',
            ],
            [
                'name' => 'REB - Daily Sales Report',
                'recipients' => $salesRecipients,
                'schedule_time' => '00:00',
                'timezone' => 'America/New_York',
                'schedule_days' => null, // Daily
                'is_enabled' => true,
            ]
        );

        $this->command->info('Created: REB - Daily Sales Report for store '.$store->name);

        // Create Daily Buy Report
        ScheduledReport::updateOrCreate(
            [
                'store_id' => $store->id,
                'report_type' => 'legacy_daily_buy',
            ],
            [
                'name' => 'REB - Daily Buy Report',
                'recipients' => $buyRecipients,
                'schedule_time' => '00:00',
                'timezone' => 'America/New_York',
                'schedule_days' => null, // Daily
                'is_enabled' => true,
            ]
        );

        $this->command->info('Created: REB - Daily Buy Report for store '.$store->name);
    }
}
