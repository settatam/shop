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
