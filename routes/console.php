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
