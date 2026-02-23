<?php

use App\Jobs\CheckTerminalCheckoutTimeout;
use App\Jobs\GenerateAgentDigest;
use App\Jobs\ProcessLayawayReminders;
use App\Jobs\RunScheduledAgents;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Check for timed out terminal checkouts every minute
Schedule::job(new CheckTerminalCheckoutTimeout)->everyMinute();

// Process layaway payment reminders and overdue notices daily at 9am
Schedule::job(new ProcessLayawayReminders)->dailyAt('09:00');

// Run scheduled agents every 5 minutes
Schedule::job(new RunScheduledAgents)->everyFiveMinutes();

// Generate agent digests daily at 8am
Schedule::job(new GenerateAgentDigest)->dailyAt('08:00');

// Sync Rapnet prices every Friday at 6am and update all diamond products
Schedule::command('sync:rapnet-prices --update-products')
    ->weeklyOn(5, '06:00') // Friday at 6am
    ->withoutOverlapping()
    ->runInBackground();

// Fetch precious metal spot prices 3 times daily (6am, 12pm, 6pm)
Schedule::command('metals:fetch-prices')
    ->twiceDaily(6, 18) // 6am and 6pm
    ->withoutOverlapping();

Schedule::command('metals:fetch-prices')
    ->dailyAt('12:00') // noon
    ->withoutOverlapping();

// Update shipment tracking status every hour (FedEx, UPS, USPS)
Schedule::command('shipments:update-tracking')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Legacy warehouse sync - Clear and reload at configured time (default 8pm ET)
Schedule::command('clear:legacy-warehouse --force')
    ->when(fn () => config('legacy-sync.enabled'))
    ->dailyAt(config('legacy-sync.schedule.clear_and_reload_at', '20:00'))
    ->timezone(config('legacy-sync.schedule.timezone', 'America/New_York'))
    ->then(function () {
        Artisan::call('sync:legacy-warehouse');
    });

// Legacy daily reports - Send at configured time (default midnight ET)
Schedule::command('reports:send-legacy-daily')
    ->when(fn () => config('legacy-sync.enabled') && config('legacy-sync.reports.enabled'))
    ->dailyAt(config('legacy-sync.schedule.send_reports_at', '00:00'))
    ->timezone(config('legacy-sync.schedule.timezone', 'America/New_York'));
